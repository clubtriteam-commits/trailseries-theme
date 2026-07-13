<?php
declare( strict_types=1 );
/**
 * Bulk migration: 158 event landing Pages from live (trailseries.bg)
 * to staging, list-driven by migration/event-pages-list.csv (reviewed
 * and committed separately).
 *
 * Run on staging:
 *   wp eval-file migration/import-event-pages.php
 *
 * Per page:
 *   1. Fetches the live page via REST (/wp-json/wp/v2/pages/{id}) —
 *      title, rendered content, publish date, featured media.
 *   2. Creates (or updates) a staging Page with the IDENTICAL slug and
 *      parent path — the seven /sabitia/<slug>/ pages get a 'sabitia'
 *      parent page, created on first need. Publish dates preserved.
 *   3. Sideloads the featured image and every inline
 *      trailseries.bg/wp-content/uploads image (src + srcset), with the
 *      _tsr_source_url dedup convention shared with the news scripts,
 *      then rewrites content URLs to the local copies.
 *
 * Idempotent: pages are keyed by the _tsr_live_id meta; a page whose
 * _tsr_event_import_done flag is set is skipped outright, so re-runs
 * only touch previously failed pages. Every failure logs a warning and
 * CONTINUES with the next page — one broken page never aborts the batch.
 *
 * Source-dead images degrade gracefully: when an image is unrecoverable
 * from live (404 there too, e.g. comborides-e1403015691793.jpg or the
 * SIMEONOVO-RUN-MAP.png variants), the <img> keeps its original live URL
 * and the page still counts as imported — only staging-side failures
 * (insert/rewrite errors) leave a page unmarked for retry.
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Old landing pages embed registration iframes/forms; without a logged-in
// user the kses content filters would strip those tags on insert. This is a
// one-time trusted import of our own site's content.
kses_remove_filters();

const TSR_LIVE_API     = 'https://trailseries.bg/wp-json/wp/v2';
const TSR_LIVE_UPLOADS = '~https?://(?:www\.)?trailseries\.bg/wp-content/uploads/([^\s"\'<>]+?)(-\d+x\d+)?\.(jpe?g|png|gif|webp)~i';

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
 * GET a live REST endpoint, decoded; null (with a warning) on any failure.
 */
function tsr_live_api_get( string $url ): ?array {
	$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) {
		WP_CLI::warning( sprintf( '      API fetch failed: %s (%s)', $response->get_error_message(), $url ) );
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		WP_CLI::warning( sprintf( '      API returned HTTP %d (%s)', $code, $url ) );
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		WP_CLI::warning( '      API returned non-JSON body: ' . $url );
		return null;
	}
	return $data;
}

/**
 * Sideload one live image (with _tsr_source_url dedup); 0 on failure.
 */
function tsr_sideload_dedup( string $image_url, int $post_id, string $desc, int &$new_count ): int {
	$existing = tsr_find_existing_attachment( $image_url );
	if ( $existing > 0 ) {
		return $existing;
	}
	$sideloaded = media_sideload_image( $image_url, $post_id, $desc, 'id' );
	if ( is_wp_error( $sideloaded ) ) {
		WP_CLI::warning( sprintf( '      sideload failed: %s (%s)', $sideloaded->get_error_message(), $image_url ) );
		return 0;
	}
	update_post_meta( (int) $sideloaded, '_tsr_source_url', $image_url );
	$new_count++;
	return (int) $sideloaded;
}

/**
 * Staging page previously imported from this live ID, or null.
 */
function tsr_page_by_live_id( int $live_id ): ?WP_Post {
	$found = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => '_tsr_live_id',
			'meta_value'     => (string) $live_id,
		)
	);
	return $found[0] ?? null;
}

// ── Load the reviewed page list ──────────────────────────────────────────────

$csv_path = __DIR__ . '/event-pages-list.csv';
$handle   = fopen( $csv_path, 'r' );
if ( false === $handle ) {
	WP_CLI::error( 'Cannot open ' . $csv_path );
}
fgetcsv( $handle, 0, ',', '"', '\\' ); // header row

$rows = array();
while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
	if ( count( $row ) < 6 ) {
		continue;
	}
	$rows[] = array(
		'live_id'  => (int) $row[0],
		'date'     => (string) $row[1],
		'slug'     => (string) $row[2],
		'url_path' => (string) $row[3],
	);
}
fclose( $handle );
WP_CLI::log( sprintf( 'Loaded %d pages from %s', count( $rows ), basename( $csv_path ) ) );

// ── The /sabitia/ parent for the seven hierarchical children ─────────────────

/**
 * Lazily create/find the 'sabitia' parent page. Static cache rather than a
 * global: wp eval-file includes this file inside a method, so top-level
 * variables here are not actually globals.
 */
function tsr_sabitia_parent_id(): int {
	static $sabitia_id = 0;
	if ( $sabitia_id > 0 ) {
		return $sabitia_id;
	}
	$existing = get_page_by_path( 'sabitia', OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		$sabitia_id = $existing->ID;
		return $sabitia_id;
	}
	$created = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'sabitia',
			'post_title'   => 'Събития',
			'post_content' => '',
		),
		true
	);
	if ( is_wp_error( $created ) ) {
		WP_CLI::warning( 'sabitia parent creation failed: ' . $created->get_error_message() );
		return 0;
	}
	$sabitia_id = (int) $created;
	WP_CLI::log( sprintf( 'Created /sabitia/ parent page (ID %d)', $sabitia_id ) );
	return $sabitia_id;
}

// ── Import loop ───────────────────────────────────────────────────────────────

$done     = 0;
$skipped  = 0;
$failures = 0;
$images   = 0;

foreach ( $rows as $i => $row ) {
	WP_CLI::log( sprintf( '[%d/%d] %s', $i + 1, count( $rows ), $row['url_path'] ) );

	// Idempotency: fully imported pages are skipped.
	$existing = tsr_page_by_live_id( $row['live_id'] );
	if ( $existing instanceof WP_Post && '1' === (string) get_post_meta( $existing->ID, '_tsr_event_import_done', true ) ) {
		WP_CLI::log( sprintf( '      skip — already imported as page %d', $existing->ID ) );
		$skipped++;
		continue;
	}

	// 1. Live page.
	$live = tsr_live_api_get(
		TSR_LIVE_API . '/pages/' . $row['live_id']
		. '?_fields=id,slug,title,content,date,date_gmt,featured_media,link'
	);
	if ( null === $live || empty( $live['slug'] ) ) {
		$failures++;
		continue;
	}

	// 2. Create / update the staging page.
	$parent_id = 0;
	if ( 0 === strpos( $row['url_path'], '/sabitia/' ) ) {
		$parent_id = tsr_sabitia_parent_id();
		if ( 0 === $parent_id ) {
			$failures++;
			continue;
		}
	}

	$postarr = array(
		'post_type'     => 'page',
		'post_status'   => 'publish',
		// REST 'slug' is WP's canonical stored form (Cyrillic slugs arrive
		// percent-encoded exactly as the live DB stores them).
		'post_name'     => (string) $live['slug'],
		'post_title'    => html_entity_decode( (string) ( $live['title']['rendered'] ?? $row['slug'] ), ENT_QUOTES | ENT_HTML5 ),
		'post_content'  => wp_slash( (string) ( $live['content']['rendered'] ?? '' ) ),
		'post_date'     => (string) $live['date'],
		'post_date_gmt' => (string) ( $live['date_gmt'] ?? $live['date'] ),
		'post_parent'   => $parent_id,
	);
	if ( $existing instanceof WP_Post ) {
		$postarr['ID'] = $existing->ID;
	}

	$page_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $page_id ) ) {
		WP_CLI::warning( '      insert failed: ' . $page_id->get_error_message() );
		$failures++;
		continue;
	}
	$page_id = (int) $page_id;
	update_post_meta( $page_id, '_tsr_live_id', (string) $row['live_id'] );
	WP_CLI::log( sprintf( '      page %d (%s)', $page_id, $existing instanceof WP_Post ? 'updated' : 'created' ) );

	$page_failed = false;

	// 3a. Inline images.
	$content = (string) get_post_field( 'post_content', $page_id );
	if ( preg_match_all( TSR_LIVE_UPLOADS, $content, $matches, PREG_SET_ORDER ) ) {
		$originals = array();
		foreach ( $matches as $m ) {
			$original = 'https://trailseries.bg/wp-content/uploads/' . $m[1] . '.' . $m[3];
			$variant  = '' !== $m[2] ? $m[0] : '';
			if ( ! isset( $originals[ $original ] ) || strlen( $variant ) > strlen( $originals[ $original ] ) ) {
				$originals[ $original ] = $variant;
			}
		}

		foreach ( $originals as $original => $largest_variant ) {
			$attachment_id = tsr_sideload_dedup( $original, $page_id, $postarr['post_title'], $images );
			if ( 0 === $attachment_id && '' !== $largest_variant ) {
				$attachment_id = tsr_sideload_dedup( $largest_variant, $page_id, $postarr['post_title'], $images );
			}
			if ( 0 === $attachment_id ) {
				// Unrecoverable from live (dead there too). Leave this <img>
				// pointing at the original URL — the page text still imports,
				// and the page is NOT held back from being marked done.
				WP_CLI::warning( sprintf( '      image unrecoverable — left pointing at live URL: %s', $original ) );
				continue;
			}

			$local_url = (string) wp_get_attachment_url( $attachment_id );
			if ( '' === $local_url ) {
				$page_failed = true;
				continue;
			}
			$stem_and_ext = (string) preg_replace( '~^https?://(?:www\.)?trailseries\.bg/wp-content/uploads/~i', '', $original );
			$dot          = strrpos( $stem_and_ext, '.' );
			$stem         = substr( $stem_and_ext, 0, (int) $dot );
			$ext          = substr( $stem_and_ext, (int) $dot + 1 );
			$content      = (string) preg_replace(
				'~https?://(?:www\.)?trailseries\.bg/wp-content/uploads/'
				. preg_quote( $stem, '~' ) . '(?:-\d+x\d+)?\.' . preg_quote( $ext, '~' ) . '~i',
				$local_url,
				$content
			);
		}

		if ( $content !== (string) get_post_field( 'post_content', $page_id ) ) {
			$updated = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => wp_slash( $content ),
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				WP_CLI::warning( '      content rewrite failed: ' . $updated->get_error_message() );
				$page_failed = true;
			} else {
				WP_CLI::log( '      inline images rewritten to local URLs' );
			}
		}
	}

	// 3b. Featured image. A source-dead featured image (media record or file
	// gone on live) is logged but does not block the page — same graceful
	// degradation as inline images.
	if ( ! has_post_thumbnail( $page_id ) && ! empty( $live['featured_media'] ) ) {
		$media = tsr_live_api_get( TSR_LIVE_API . '/media/' . (int) $live['featured_media'] . '?_fields=source_url' );
		if ( null !== $media && ! empty( $media['source_url'] ) ) {
			$thumb_id = tsr_sideload_dedup( (string) $media['source_url'], $page_id, $postarr['post_title'], $images );
			if ( $thumb_id > 0 ) {
				if ( set_post_thumbnail( $page_id, $thumb_id ) ) {
					WP_CLI::log( sprintf( '      featured image set (attachment %d)', $thumb_id ) );
				} else {
					// Staging-side failure — retryable, keep the page unmarked.
					WP_CLI::warning( sprintf( '      set_post_thumbnail failed (attachment %d)', $thumb_id ) );
					$page_failed = true;
				}
			} else {
				WP_CLI::warning( '      featured image unrecoverable — page imported without thumbnail' );
			}
		} else {
			WP_CLI::warning( '      featured media record unavailable on live — page imported without thumbnail' );
		}
	}

	// Mark done ONLY when everything succeeded — failed pages stay unmarked
	// so the next run retries them.
	if ( $page_failed ) {
		$failures++;
		WP_CLI::warning( sprintf( '      page %d imported with errors — will retry on next run', $page_id ) );
	} else {
		update_post_meta( $page_id, '_tsr_event_import_done', '1' );
		$done++;
	}

	// Politeness to the live host across ~160 pages / ~400 requests.
	usleep( 200000 );
}

WP_CLI::log( '' );
WP_CLI::log( sprintf(
	'Summary: %d imported, %d skipped (already done), %d with failures, %d new image(s) sideloaded.',
	$done,
	$skipped,
	$failures,
	$images
) );
if ( 0 === $failures ) {
	WP_CLI::success( 'Event page migration complete.' );
} else {
	WP_CLI::warning( 'Some pages failed — re-run this script to retry only the failed ones.' );
}
