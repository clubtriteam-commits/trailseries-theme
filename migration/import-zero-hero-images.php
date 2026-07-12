<?php
declare( strict_types=1 );
/**
 * One-time migration: featured images for the Zero to HERO posts.
 *
 * Run on staging:
 *   wp eval-file migration/import-zero-hero-images.php
 *
 * For each mapped post, fetches the LIVE post page (trailseries.bg),
 * extracts the og:image URL, sideloads the file into the staging media
 * library and sets it as the post's featured image. Idempotent: posts
 * that already have a thumbnail are skipped.
 *
 * Uses wp_remote_get()/media_sideload_image() (PHP's HTTP layer), which
 * works even where the shell wget/curl binaries are blocked. If every
 * fetch fails with a connection error, outbound HTTP is blocked for PHP
 * too — fall back to downloading the images manually and uploading via
 * wp-admin (Post → Featured image).
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Staging post ID => live post slug.
$map = array(
	4794 => 'zero-to-hero-с-цветелина-тричкова-xmas-edition',
	4619 => 'zero-to-hero-s-ivaylo-hadjiev',
	4547 => 'zero-to-hero-с-христо-цветков',
	4523 => 'zero-to-hero-s-konstantin-ivanov',
	4509 => 'zero-to-hero-with-benjamin-kane',
	4487 => 'zero-to-hero-с-бенджамин-кейн',
);

$failures = 0;

foreach ( $map as $post_id => $slug ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		WP_CLI::warning( sprintf( '[%d] post not found — check the ID mapping', $post_id ) );
		$failures++;
		continue;
	}
	WP_CLI::log( sprintf( '[%d] %s (staging slug: %s)', $post_id, $post->post_title, $post->post_name ) );

	if ( has_post_thumbnail( $post_id ) ) {
		WP_CLI::log( '      skip — already has a featured image' );
		continue;
	}

	// Cyrillic slugs must be percent-encoded per path segment for HTTP.
	$live_url = 'https://trailseries.bg/' . rawurlencode( $slug ) . '/';
	$response = wp_remote_get( $live_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) {
		WP_CLI::warning( sprintf( '      fetch failed: %s — if ALL posts fail like this, outbound HTTP is blocked for PHP too; fall back to manual upload', $response->get_error_message() ) );
		$failures++;
		continue;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		WP_CLI::warning( sprintf( '      live page returned HTTP %d (%s)', $code, $live_url ) );
		$failures++;
		continue;
	}

	$html = (string) wp_remote_retrieve_body( $response );
	// og:image, tolerant of attribute order.
	if ( ! preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m )
		&& ! preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
		WP_CLI::warning( '      no og:image tag found on the live page' );
		$failures++;
		continue;
	}
	$image_url = html_entity_decode( $m[1], ENT_QUOTES );
	WP_CLI::log( '      image: ' . $image_url );

	$attachment_id = media_sideload_image( $image_url, $post_id, $post->post_title, 'id' );
	if ( is_wp_error( $attachment_id ) ) {
		WP_CLI::warning( '      sideload failed: ' . $attachment_id->get_error_message() );
		$failures++;
		continue;
	}
	if ( ! set_post_thumbnail( $post_id, (int) $attachment_id ) ) {
		WP_CLI::warning( sprintf( '      set_post_thumbnail failed (attachment %d imported)', $attachment_id ) );
		$failures++;
		continue;
	}
	WP_CLI::log( sprintf( '      OK — attachment %d set as featured image', $attachment_id ) );
}

if ( 0 === $failures ) {
	WP_CLI::success( 'All Zero to HERO featured images migrated.' );
} else {
	WP_CLI::warning( sprintf( '%d post(s) failed — see warnings above.', $failures ) );
}
