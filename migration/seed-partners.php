<?php
declare( strict_types=1 );
/**
 * One-time seeder for the Партньори page and the initial ts_partner posts.
 *
 * Run on staging:
 *   wp eval-file migration/seed-partners.php
 *
 * Idempotent: existing partners (matched by exact title) only get their URL
 * meta refreshed; the page is created once. Logos are NOT seeded — upload
 * them in wp-admin as each partner's featured image; until then the page
 * template renders placeholder boxes with the partner's initials.
 *
 * @package trailseries-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partners = array(
	array( 'SLS', 'https://www.sls.bg/' ),
	array( 'Mizuno', 'https://www.sls.bg/sls-marki/mizuno/' ),
	array( 'Leya', 'https://www.holaleya.com/' ),
	array( 'The Barbarian', 'https://www.holaleya.com/the-barbarian' ),
	array( "Leya's Oaties", 'https://www.holaleya.com/leya-s-oaties-1' ),
);

foreach ( $partners as $i => $partner ) {
	list( $name, $url ) = $partner;

	$existing = get_posts(
		array(
			'post_type'   => 'ts_partner',
			'title'       => $name,
			'post_status' => 'any',
			'numberposts' => 1,
		)
	);

	if ( ! empty( $existing ) ) {
		$post_id = $existing[0]->ID;
		update_post_meta( $post_id, '_tsr_partner_url', esc_url_raw( $url ) );
		WP_CLI::log( sprintf( 'exists  [%d]  %s — URL refreshed', $post_id, $name ) );
		continue;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'ts_partner',
			'post_title'  => $name,
			'post_status' => 'publish',
			'menu_order'  => $i + 1,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( sprintf( 'FAIL  %s — %s', $name, $post_id->get_error_message() ) );
		continue;
	}
	update_post_meta( $post_id, '_tsr_partner_url', esc_url_raw( $url ) );
	WP_CLI::log( sprintf( 'created [%d]  %s', $post_id, $name ) );
}

// -- Партньори page with the template assigned --------------------------------

$page = get_page_by_path( 'partniori' );
if ( $page instanceof WP_Post ) {
	WP_CLI::log( sprintf( 'page exists [%d] /partniori/ (template: %s)', $page->ID, (string) get_post_meta( $page->ID, '_wp_page_template', true ) ) );
} else {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Партньори',
			'post_name'    => 'partniori',
			'post_status'  => 'publish',
			'page_template' => 'page-partniori.php',
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		WP_CLI::warning( 'FAIL page: ' . $page_id->get_error_message() );
	} else {
		WP_CLI::log( sprintf( 'page created [%d] /partniori/', $page_id ) );
	}
}

WP_CLI::success( 'Partners seed done. Upload logos via wp-admin → Партньори (featured image = лого).' );
