<?php
/**
 * TrailSeries theme setup. Presentation only — all results logic belongs to
 * the trailseries-results plugin (see docs/decisions/ADR-002).
 *
 * @package trailseries
 */

declare( strict_types=1 );

add_action( 'after_setup_theme', static function (): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'trailseries' ),
		)
	);
} );

add_action( 'wp_enqueue_scripts', static function (): void {
	wp_enqueue_style(
		'trailseries',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
} );

/**
 * Comments are disabled site-wide — a results/news site, not a discussion
 * forum. Belt-and-suspenders: removes support (hides the admin UI) AND
 * force-closes via filters, so a post that already has comment_status=open
 * (imported content, a future admin override) still can't accept new
 * comments. Existing comments, if any, are hidden rather than deleted.
 */
add_action( 'init', static function (): void {
	foreach ( get_post_types() as $post_type ) {
		remove_post_type_support( $post_type, 'comments' );
		remove_post_type_support( $post_type, 'trackbacks' );
	}
}, 100 );

add_filter( 'comments_open', '__return_false', 20, 2 );
add_filter( 'pings_open', '__return_false', 20, 2 );
add_filter( 'comments_array', static fn(): array => array(), 10, 2 );
