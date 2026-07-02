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
