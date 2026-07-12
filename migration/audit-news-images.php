<?php
declare( strict_types=1 );
/**
 * Read-only QA audit of the Новини image migration (category term_id 8).
 *
 * Run on staging:
 *   wp eval-file migration/audit-news-images.php
 *
 * Checks, per post:
 *   1. Featured image: exists, its file is on disk, and its association is
 *      plausible — flags thumbnails whose attachment is parented to a
 *      DIFFERENT post (the post-6596/attachment-6597 bug class) and any
 *      attachment used as featured image by more than one news post.
 *   2. Inline images: every uploads URL in post_content (src + srcset)
 *      must point at this site's uploads and the file must exist on disk.
 *      Any URL still on trailseries.bg (live) is a FAIL.
 *   3. Verdict table at the end: one PASS/FAIL row per post.
 *
 * Makes NO changes — safe to run any number of times.
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TSR_NEWS_CAT_ID = 8;

$uploads  = wp_upload_dir();
$base_url = (string) $uploads['baseurl'];               // e.g. https://stg.trailseries.bg/wp-content/uploads
$base_dir = rtrim( (string) $uploads['basedir'], '/' ); // filesystem path

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
WP_CLI::log( sprintf( 'Auditing %d posts in category %d. Uploads base: %s', count( $posts ), TSR_NEWS_CAT_ID, $base_url ) );
WP_CLI::log( '' );

// First pass: which attachment IDs are used as featured image, and by whom —
// the same attachment doing thumbnail duty for two posts is the bug class we
// already hit once (6596 showing 6597's image).
$thumb_users = array(); // attachment ID => [post IDs]
foreach ( $posts as $post ) {
	$tid = (int) get_post_thumbnail_id( $post );
	if ( $tid > 0 ) {
		$thumb_users[ $tid ][] = $post->ID;
	}
}

$rows = array();

foreach ( $posts as $post ) {
	WP_CLI::log( sprintf( '[%d] %s', $post->ID, $post->post_title ) );

	$feat_status   = 'PASS';
	$inline_status = 'PASS';
	$notes         = array();

	// ── 1. Featured image ──────────────────────────────────────────────────────
	$thumb_id = (int) get_post_thumbnail_id( $post );
	if ( 0 === $thumb_id ) {
		$feat_status = 'FAIL';
		$notes[]     = 'no featured image';
		WP_CLI::log( '      FEATURED: none' );
	} else {
		$att = get_post( $thumb_id );
		if ( ! $att instanceof WP_Post || 'attachment' !== $att->post_type ) {
			$feat_status = 'FAIL';
			$notes[]     = sprintf( 'thumbnail %d is not a valid attachment', $thumb_id );
			WP_CLI::log( sprintf( '      FEATURED: FAIL — attachment %d missing', $thumb_id ) );
		} else {
			$file = (string) get_attached_file( $thumb_id );
			if ( '' === $file || ! file_exists( $file ) ) {
				$feat_status = 'FAIL';
				$notes[]     = sprintf( 'thumbnail %d file missing on disk', $thumb_id );
			}

			$source = (string) get_post_meta( $thumb_id, '_tsr_source_url', true );
			WP_CLI::log( sprintf(
				'      FEATURED: attachment %d ("%s")%s',
				$thumb_id,
				$att->post_title,
				'' !== $source ? ' src=' . $source : ''
			) );

			// Association checks — these catch the wrong-image bug class.
			if ( count( $thumb_users[ $thumb_id ] ) > 1 ) {
				$feat_status = 'FLAG';
				$others      = array_diff( $thumb_users[ $thumb_id ], array( $post->ID ) );
				$notes[]     = sprintf( 'attachment %d is ALSO the thumbnail of post(s) %s', $thumb_id, implode( ',', $others ) );
			}
			if ( $att->post_parent > 0 && $att->post_parent !== $post->ID ) {
				$parent = get_post( $att->post_parent );
				if ( 'FAIL' !== $feat_status ) {
					$feat_status = 'FLAG';
				}
				$notes[] = sprintf(
					'attachment %d is parented to post %d ("%s") — verify the image matches this post',
					$thumb_id,
					$att->post_parent,
					$parent instanceof WP_Post ? $parent->post_title : '?'
				);
			}
		}
	}

	// ── 2. Inline images ───────────────────────────────────────────────────────
	$live  = array();
	$local = array();
	$other = array();
	if ( preg_match_all( '~https?://[^\s"\'<>]+/wp-content/uploads/[^\s"\'<>]+\.(?:jpe?g|png|gif|webp)~i', $post->post_content, $m ) ) {
		foreach ( array_unique( $m[0] ) as $url ) {
			if ( preg_match( '~^https?://(?:www\.)?trailseries\.bg/~i', $url ) ) {
				$live[] = $url;
			} elseif ( 0 === stripos( $url, $base_url . '/' ) ) {
				$local[] = $url;
			} else {
				$other[] = $url;
			}
		}
	}

	foreach ( $live as $url ) {
		$inline_status = 'FAIL';
		$notes[]       = 'still points at live: ' . $url;
		WP_CLI::log( '      INLINE FAIL (live URL): ' . $url );
	}
	foreach ( $other as $url ) {
		$inline_status = 'FLAG' === $inline_status || 'FAIL' === $inline_status ? $inline_status : 'FLAG';
		$notes[]       = 'unexpected host: ' . $url;
		WP_CLI::log( '      INLINE FLAG (unexpected host): ' . $url );
	}
	foreach ( $local as $url ) {
		$rel  = rawurldecode( substr( $url, strlen( $base_url ) ) );
		$path = $base_dir . $rel;
		if ( file_exists( $path ) ) {
			WP_CLI::log( '      INLINE ok: ' . $url );
		} else {
			$inline_status = 'FAIL';
			$notes[]       = 'local file missing: ' . $url;
			WP_CLI::log( '      INLINE FAIL (file missing on disk): ' . $url );
		}
	}
	if ( empty( $live ) && empty( $local ) && empty( $other ) ) {
		WP_CLI::log( '      INLINE: no images in content' );
	}

	$verdict = ( 'PASS' === $feat_status && 'PASS' === $inline_status ) ? 'PASS' : ( 'FAIL' === $feat_status || 'FAIL' === $inline_status ? 'FAIL' : 'FLAG' );

	$rows[] = array(
		'post'     => $post->ID,
		'title'    => mb_substr( $post->post_title, 0, 40 ),
		'featured' => $feat_status . ( $thumb_id > 0 ? " (#$thumb_id)" : '' ),
		'inline'   => $inline_status . sprintf( ' (%d local, %d live)', count( $local ), count( $live ) ),
		'verdict'  => $verdict,
		'notes'    => implode( ' | ', $notes ),
	);
}

WP_CLI::log( '' );
\WP_CLI\Utils\format_items( 'table', $rows, array( 'post', 'title', 'featured', 'inline', 'verdict', 'notes' ) );

$fails = count( array_filter( $rows, static fn( array $r ): bool => 'FAIL' === $r['verdict'] ) );
$flags = count( array_filter( $rows, static fn( array $r ): bool => 'FLAG' === $r['verdict'] ) );
if ( 0 === $fails && 0 === $flags ) {
	WP_CLI::success( 'All posts PASS.' );
} else {
	WP_CLI::warning( sprintf( '%d FAIL, %d FLAG — see notes column and per-post log above.', $fails, $flags ) );
}
