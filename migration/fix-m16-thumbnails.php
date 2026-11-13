<?php
declare( strict_types=1 );
/**
 * One-off (audit M16, 2026-11-13): featured images for the three news posts
 * imported from the July WXR. The importer copied _thumbnail_id values that
 * are old-site attachment IDs and resolve to nothing on staging.
 *
 * Run on staging (outside the docroot, include mode because of strict_types):
 *   wp eval-file /home/trailser/tsr-data/migration/fix-m16-thumbnails.php --use-include
 *
 * Idempotent: reuses an attachment that already carries the same
 * _tsr_source_url (the convention shared with import-news-images.php).
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// post slug => original image URL on the live site (from the WXR attachment items).
$tsr_m16_thumbs = array(
	'промени-свързани-с-covid-19'                     => 'https://trailseries.bg/wp-content/uploads/2020/03/Covid-19-Trail-Series-e1584102184549.png',
	'50-намаления-в-sls'                              => 'https://trailseries.bg/wp-content/uploads/2020/04/SLS-TS.jpg',
	'промяна-в-трасето-на-cactus-run-наложена-от-сто' => 'https://trailseries.bg/wp-content/uploads/2025/11/the-cactus-run-2025-снимка-1.jpg',
);

foreach ( $tsr_m16_thumbs as $slug => $source_url ) {
	$post = get_page_by_path( $slug, OBJECT, 'post' );
	if ( ! $post instanceof WP_Post ) {
		WP_CLI::warning( "post not found: $slug" );
		continue;
	}

	$existing = get_posts( array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_tsr_source_url',
		'meta_value'  => $source_url,
	) );
	$att_id = $existing[0] ?? 0;

	if ( ! $att_id ) {
		// Percent-encode the file name only (Cyrillic), keep the path as is.
		$parts     = explode( '/', $source_url );
		$file      = array_pop( $parts );
		$fetch_url = implode( '/', $parts ) . '/' . rawurlencode( $file );
		$att_id    = media_sideload_image( $fetch_url, $post->ID, null, 'id' );
		if ( is_wp_error( $att_id ) ) {
			WP_CLI::warning( "$slug: sideload failed: " . $att_id->get_error_message() );
			continue;
		}
		update_post_meta( (int) $att_id, '_tsr_source_url', $source_url );
	}

	update_post_meta( $post->ID, '_thumbnail_id', (int) $att_id );
	WP_CLI::log( sprintf( '%d %s -> attachment %d %s', $post->ID, $slug, $att_id, wp_get_attachment_url( (int) $att_id ) ) );
}
WP_CLI::success( 'done' );
