<?php
declare( strict_types=1 );
/**
 * One-time migration: inline content images + featured images for the
 * Новини posts (category term_id 8 on staging).
 *
 * Run on staging:
 *   wp eval-file migration/import-news-images.php
 *
 * For every post in the category:
 *   1. Finds all image URLs in post_content still pointing at the live
 *      site (trailseries.bg/wp-content/uploads/...), in both src and
 *      srcset attributes.
 *   2. Sideloads each unique image ONCE into the staging media library.
 *      Size-suffixed variants (photo-300x200.jpg) are collapsed to their
 *      original (photo.jpg); if the original 404s, the largest variant
 *      seen in the content is fetched instead.
 *   3. Rewrites post_content so every variant of the old URL points at
 *      the local copy.
 *   4. Posts without a featured image get the first sideloaded content
 *      image as thumbnail; when a post has no content images at all, the
 *      live post page's og:image is fetched instead (same approach as
 *      import-zero-hero-images.php).
 *
 * Idempotent: sideloaded attachments carry a _tsr_source_url meta with
 * the live original URL, and re-runs reuse them instead of downloading
 * again; posts whose content has no live URLs left are skipped.
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const TSR_NEWS_CAT_ID  = 8;
const TSR_LIVE_UPLOADS = '~https?://(?:www\.)?trailseries\.bg/wp-content/uploads/([^\s"\'<>]+?)(-\d+x\d+)?\.(jpe?g|png|gif|webp)~i';

/**
 * Find an already-sideloaded attachment for a live original URL, if any.
 */
function tsr_find_existing_attachment( string $source_url ): int {
	$found = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_tsr_source_url',
			'meta_value'     => $source_url,
		)
	);
	return empty( $found ) ? 0 : (int) $found[0];
}

$posts = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'category'       => TSR_NEWS_CAT_ID,
		'orderby'        => 'date',
		'order'          => 'ASC',
	)
);
WP_CLI::log( sprintf( 'Found %d posts in category %d.', count( $posts ), TSR_NEWS_CAT_ID ) );

$failures     = 0;
$rewritten    = 0;
$thumbs_set   = 0;
$images_added = 0;

foreach ( $posts as $post ) {
	WP_CLI::log( sprintf( '[%d] %s', $post->ID, $post->post_title ) );

	$content = $post->post_content;

	// ── 1. Collect unique originals from src/srcset URLs ──────────────────────
	// Keyed by original URL; value = largest size-suffixed variant seen (used
	// as the download fallback when the original itself 404s on live).
	$originals = array();
	if ( preg_match_all( TSR_LIVE_UPLOADS, $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			$original = 'https://trailseries.bg/wp-content/uploads/' . $m[1] . '.' . $m[3];
			$variant  = '' !== $m[2] ? $m[0] : '';
			if ( ! isset( $originals[ $original ] ) || strlen( $variant ) > strlen( $originals[ $original ] ) ) {
				$originals[ $original ] = $variant;
			}
		}
	}

	$first_attachment = 0;

	foreach ( $originals as $original => $largest_variant ) {
		// Idempotency: reuse a previously sideloaded copy.
		$attachment_id = tsr_find_existing_attachment( $original );
		if ( $attachment_id > 0 ) {
			WP_CLI::log( sprintf( '      reuse attachment %d for %s', $attachment_id, $original ) );
		} else {
			$attachment_id = media_sideload_image( $original, $post->ID, $post->post_title, 'id' );
			if ( is_wp_error( $attachment_id ) && '' !== $largest_variant ) {
				WP_CLI::log( sprintf( '      original failed (%s) — trying variant %s', $attachment_id->get_error_message(), $largest_variant ) );
				$attachment_id = media_sideload_image( $largest_variant, $post->ID, $post->post_title, 'id' );
			}
			if ( is_wp_error( $attachment_id ) ) {
				WP_CLI::warning( sprintf( '      sideload failed for %s: %s', $original, $attachment_id->get_error_message() ) );
				$failures++;
				continue;
			}
			$attachment_id = (int) $attachment_id;
			update_post_meta( $attachment_id, '_tsr_source_url', $original );
			$images_added++;
			WP_CLI::log( sprintf( '      sideloaded attachment %d from %s', $attachment_id, $original ) );
		}

		if ( 0 === $first_attachment ) {
			$first_attachment = $attachment_id;
		}

		$local_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $local_url ) || '' === $local_url ) {
			WP_CLI::warning( sprintf( '      no URL for attachment %d', $attachment_id ) );
			$failures++;
			continue;
		}

		// ── 3. Rewrite every variant of this image to the local copy ──────────
		// (srcset entries collapse onto the single local file — harmless).
		$stem_and_ext = (string) preg_replace( '~^https?://(?:www\.)?trailseries\.bg/wp-content/uploads/~i', '', $original );
		$dot          = strrpos( $stem_and_ext, '.' );
		$stem         = substr( $stem_and_ext, 0, (int) $dot );
		$ext          = substr( $stem_and_ext, (int) $dot + 1 );
		$pattern      = '~https?://(?:www\.)?trailseries\.bg/wp-content/uploads/'
			. preg_quote( $stem, '~' ) . '(?:-\d+x\d+)?\.' . preg_quote( $ext, '~' ) . '~i';
		$content      = (string) preg_replace( $pattern, $local_url, $content );
	}

	// ── Persist rewritten content ──────────────────────────────────────────────
	if ( $content !== $post->post_content ) {
		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				// wp_update_post() unslashes its input — pass through wp_slash()
				// so literal backslashes in the content survive the round trip.
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			WP_CLI::warning( '      content update failed: ' . $updated->get_error_message() );
			$failures++;
		} else {
			$rewritten++;
			WP_CLI::log( '      content rewritten to local URLs' );
		}
	} elseif ( empty( $originals ) ) {
		WP_CLI::log( '      no live-site image URLs in content' );
	}

	// ── 4. Featured image ──────────────────────────────────────────────────────
	if ( has_post_thumbnail( $post->ID ) ) {
		continue;
	}

	if ( 0 === $first_attachment ) {
		// No content images — fall back to the live page's og:image.
		$live_url = 'https://trailseries.bg/' . rawurlencode( $post->post_name ) . '/';
		$response = wp_remote_get( $live_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::warning( sprintf( '      no featured image and live page unavailable (%s)', $live_url ) );
			$failures++;
			continue;
		}
		$html = (string) wp_remote_retrieve_body( $response );
		if ( ! preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m )
			&& ! preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
			WP_CLI::warning( '      no og:image on the live page either — needs a manual featured image' );
			$failures++;
			continue;
		}
		$og_url           = html_entity_decode( $m[1], ENT_QUOTES );
		$first_attachment = tsr_find_existing_attachment( $og_url );
		if ( 0 === $first_attachment ) {
			$sideloaded = media_sideload_image( $og_url, $post->ID, $post->post_title, 'id' );
			if ( is_wp_error( $sideloaded ) ) {
				WP_CLI::warning( '      og:image sideload failed: ' . $sideloaded->get_error_message() );
				$failures++;
				continue;
			}
			$first_attachment = (int) $sideloaded;
			update_post_meta( $first_attachment, '_tsr_source_url', $og_url );
			$images_added++;
		}
		WP_CLI::log( '      og:image fallback: ' . $og_url );
	}

	if ( set_post_thumbnail( $post->ID, $first_attachment ) ) {
		$thumbs_set++;
		WP_CLI::log( sprintf( '      featured image set (attachment %d)', $first_attachment ) );
	} else {
		WP_CLI::warning( sprintf( '      set_post_thumbnail failed (attachment %d)', $first_attachment ) );
		$failures++;
	}
}

WP_CLI::log( '' );
WP_CLI::log( sprintf(
	'Summary: %d image(s) sideloaded, %d post(s) rewritten, %d featured image(s) set.',
	$images_added,
	$rewritten,
	$thumbs_set
) );
if ( 0 === $failures ) {
	WP_CLI::success( 'News image migration complete.' );
} else {
	WP_CLI::warning( sprintf( '%d failure(s) — see warnings above.', $failures ) );
}
