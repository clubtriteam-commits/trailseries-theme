<?php
declare( strict_types=1 );
/**
 * Targeted fixes for the 15 FAIL posts from the Новини image audit
 * (migration/audit-news-images.php).
 *
 * Run on staging:
 *   wp eval-file migration/fix-news-images.php
 * then re-run the audit to confirm:
 *   wp eval-file migration/audit-news-images.php
 *
 * Fix groups:
 *   1. No featured image at all      → og:image from the live post page.
 *   2. _thumbnail_id → dead attachment (orphaned by an earlier broken
 *      import) → clear the stale meta, then same og:image fallback.
 *   3. Posts 6245/6259: inline URLs point at local uploads paths whose
 *      files never landed on disk → re-sideload from the live
 *      counterpart URL and rewrite the content.
 *
 * Idempotent: posts that already have a valid thumbnail are skipped,
 * sideloads are deduplicated via the _tsr_source_url attachment meta
 * (same convention as import-news-images.php), and the inline repair
 * only acts on files that are still missing on disk.
 *
 * The Comborides FLAG on post 2083 is expected and deliberately not
 * touched here.
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Audit FAIL groups (2026-07).
$tsr_no_thumb   = array( 4811, 4812, 1542, 1544, 4814, 4816 );
$tsr_bad_thumb  = array( 5871, 5888, 6099, 6115, 6181, 6245, 6259, 6291, 6376 );
$tsr_fix_inline = array( 6245, 6259 );

if ( ! function_exists( 'tsr_find_existing_attachment' ) ) {
	/** Attachment previously sideloaded from this live URL, or 0. */
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
}

/**
 * og:image URL from the live post page, '' when unavailable.
 */
function tsr_live_og_image( WP_Post $post ): string {
	// post_name may be stored percent-encoded (Cyrillic slugs) — decode
	// first so we never double-encode.
	$live_url = 'https://trailseries.bg/' . rawurlencode( rawurldecode( $post->post_name ) ) . '/';
	$response = wp_remote_get( $live_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) {
		WP_CLI::warning( sprintf( '      live fetch failed: %s (%s)', $response->get_error_message(), $live_url ) );
		return '';
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		WP_CLI::warning( sprintf( '      live page HTTP %d (%s)', $code, $live_url ) );
		return '';
	}
	$html = (string) wp_remote_retrieve_body( $response );
	if ( ! preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m )
		&& ! preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
		WP_CLI::warning( '      no og:image on the live page' );
		return '';
	}
	return html_entity_decode( $m[1], ENT_QUOTES );
}

/**
 * Sideload (or reuse) an image and set it as the post's featured image.
 */
function tsr_set_thumb_from_url( WP_Post $post, string $image_url, int &$failures ): void {
	$attachment_id = tsr_find_existing_attachment( $image_url );
	if ( $attachment_id > 0 ) {
		WP_CLI::log( sprintf( '      reuse attachment %d (%s)', $attachment_id, $image_url ) );
	} else {
		$sideloaded = media_sideload_image( $image_url, $post->ID, $post->post_title, 'id' );
		if ( is_wp_error( $sideloaded ) ) {
			WP_CLI::warning( sprintf( '      TODO manual featured image — sideload failed: %s (%s)', $sideloaded->get_error_message(), $image_url ) );
			$failures++;
			return;
		}
		$attachment_id = (int) $sideloaded;
		update_post_meta( $attachment_id, '_tsr_source_url', $image_url );
		WP_CLI::log( sprintf( '      sideloaded attachment %d from %s', $attachment_id, $image_url ) );
	}

	if ( set_post_thumbnail( $post->ID, $attachment_id ) ) {
		WP_CLI::log( sprintf( '      featured image set (attachment %d)', $attachment_id ) );
	} else {
		WP_CLI::warning( sprintf( '      set_post_thumbnail failed (attachment %d)', $attachment_id ) );
		$failures++;
	}
}

$uploads  = wp_upload_dir();
$base_url = (string) $uploads['baseurl'];
$base_dir = rtrim( (string) $uploads['basedir'], '/' );
$failures = 0;

// ── Groups 1 + 2: featured images ────────────────────────────────────────────

foreach ( array_merge( $tsr_bad_thumb, $tsr_no_thumb ) as $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		WP_CLI::warning( sprintf( '[%d] post not found', $post_id ) );
		$failures++;
		continue;
	}
	WP_CLI::log( sprintf( '[%d] %s', $post_id, $post->post_title ) );

	// Group 2: clear a _thumbnail_id that points at a dead attachment.
	$thumb_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );
	if ( $thumb_id > 0 ) {
		$att = get_post( $thumb_id );
		if ( $att instanceof WP_Post && 'attachment' === $att->post_type ) {
			WP_CLI::log( sprintf( '      skip — valid featured image already set (attachment %d)', $thumb_id ) );
			continue;
		}
		delete_post_meta( $post_id, '_thumbnail_id' );
		WP_CLI::log( sprintf( '      cleared stale _thumbnail_id %d (attachment does not exist)', $thumb_id ) );
	}

	$og = tsr_live_og_image( $post );
	if ( '' === $og ) {
		WP_CLI::warning( sprintf( '      TODO manual featured image for post %d (no og:image available)', $post_id ) );
		$failures++;
		continue;
	}
	WP_CLI::log( '      og:image ' . $og );
	tsr_set_thumb_from_url( $post, $og, $failures );
}

// ── Group 3: re-sideload missing inline files (6245, 6259) ───────────────────

foreach ( $tsr_fix_inline as $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		WP_CLI::warning( sprintf( '[%d] post not found', $post_id ) );
		$failures++;
		continue;
	}
	WP_CLI::log( sprintf( '[%d] inline repair: %s', $post_id, $post->post_title ) );

	$content = $post->post_content;
	if ( ! preg_match_all(
		'~' . preg_quote( $base_url, '~' ) . '/([^\s"\'<>]+?)(-\d+x\d+)?\.(jpe?g|png|gif|webp)~i',
		$content,
		$matches,
		PREG_SET_ORDER
	) ) {
		WP_CLI::log( '      no local uploads URLs in content' );
		continue;
	}

	// Unique per original stem; remember the found URL for logging.
	$broken = array();
	foreach ( $matches as $m ) {
		$rel_original = $m[1] . '.' . $m[3];                       // path under uploads/, size suffix stripped
		$found_file   = $base_dir . '/' . rawurldecode( $m[1] . ( $m[2] ?? '' ) . '.' . $m[3] );
		$orig_file    = $base_dir . '/' . rawurldecode( $rel_original );
		if ( ! file_exists( $found_file ) && ! file_exists( $orig_file ) ) {
			$broken[ $rel_original ] = true;
		}
	}
	if ( empty( $broken ) ) {
		WP_CLI::log( '      all inline files exist on disk — nothing to repair' );
		continue;
	}

	foreach ( array_keys( $broken ) as $rel ) {
		// The WXR import copied live URLs verbatim onto the staging host, so
		// the live counterpart lives at the same uploads-relative path.
		$live_original = 'https://trailseries.bg/wp-content/uploads/' . $rel;
		WP_CLI::log( '      missing on disk: ' . $rel );

		$attachment_id = tsr_find_existing_attachment( $live_original );
		if ( $attachment_id > 0 ) {
			WP_CLI::log( sprintf( '      reuse attachment %d', $attachment_id ) );
		} else {
			$sideloaded = media_sideload_image( $live_original, $post_id, $post->post_title, 'id' );
			if ( is_wp_error( $sideloaded ) ) {
				WP_CLI::warning( sprintf( '      sideload failed: %s (%s)', $sideloaded->get_error_message(), $live_original ) );
				$failures++;
				continue;
			}
			$attachment_id = (int) $sideloaded;
			update_post_meta( $attachment_id, '_tsr_source_url', $live_original );
			WP_CLI::log( sprintf( '      sideloaded attachment %d from %s', $attachment_id, $live_original ) );
		}

		$local_url = (string) wp_get_attachment_url( $attachment_id );
		if ( '' === $local_url ) {
			WP_CLI::warning( sprintf( '      no URL for attachment %d', $attachment_id ) );
			$failures++;
			continue;
		}

		// Rewrite every size variant of the broken URL to the new local copy.
		$dot     = strrpos( $rel, '.' );
		$stem    = substr( $rel, 0, (int) $dot );
		$ext     = substr( $rel, (int) $dot + 1 );
		$pattern = '~' . preg_quote( $base_url . '/', '~' )
			. preg_quote( $stem, '~' ) . '(?:-\d+x\d+)?\.' . preg_quote( $ext, '~' ) . '~i';
		$content = (string) preg_replace( $pattern, $local_url, $content );
	}

	if ( $content !== $post->post_content ) {
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			WP_CLI::warning( '      content update failed: ' . $updated->get_error_message() );
			$failures++;
		} else {
			WP_CLI::log( '      content rewritten to repaired URLs' );
		}
	}
}

WP_CLI::log( '' );
if ( 0 === $failures ) {
	WP_CLI::success( 'All targeted fixes applied — re-run migration/audit-news-images.php to confirm.' );
} else {
	WP_CLI::warning( sprintf( '%d failure(s) need manual follow-up — see TODO warnings above.', $failures ) );
}
