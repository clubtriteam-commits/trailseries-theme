<?php
/**
 * TrailSeries Child Theme (Exhibz) — setup and hooks.
 *
 * Presentation only. Results data and table rendering belong to the
 * trailseries-results plugin (ADR-002). This file wires up menus,
 * enqueues stylesheets, and adds the homepage stats helper.
 *
 * @package exhibz-child
 */

declare( strict_types=1 );

// ── Theme setup ──────────────────────────────────────────────────────────────

add_action( 'after_setup_theme', static function (): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

	/**
	 * Navigation menu locations.
	 *
	 * After activating the theme, go to Appearance → Menus and assign
	 * a menu to "Основна навигация" with the following items:
	 *   Начало → /
	 *   Календар → /kalendar/
	 *   Резултати → /rezultati/
	 *   Класирания → /klasiraniya/
	 *   Рекорди → /rekordi/
	 *   Правила → /pravila/
	 *   Новини → /novini/
	 */
	register_nav_menus(
		array(
			'primary' => 'Основна навигация',
		)
	);

	// Bulgarian date locale (wp_date() uses WP locale automatically;
	// this ensures date_i18n() also picks it up).
	load_child_theme_textdomain( 'exhibz-child', get_stylesheet_directory() . '/languages' );
} );

// ── Stylesheets ───────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', static function (): void {
	// Enqueue parent theme stylesheet first.
	wp_enqueue_style(
		'exhibz-parent',
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme( get_template() )->get( 'Version' )
	);

	// Child theme stylesheet (overrides + homepage sections).
	wp_enqueue_style(
		'exhibz-child',
		get_stylesheet_uri(),
		array( 'exhibz-parent' ),
		wp_get_theme()->get( 'Version' )
	);
}, 20 );

// ── Homepage stats helper ─────────────────────────────────────────────────────

/**
 * Return total published ts_result posts (= race categories imported).
 */
function tsr_homepage_total_races(): int {
	$counts = wp_count_posts( 'ts_result' );
	return isset( $counts->publish ) ? (int) $counts->publish : 0;
}

/**
 * Return total finisher count across all published ts_result posts.
 *
 * Uses a cached MySQL JSON_LENGTH aggregate (requires MySQL 5.7.8+).
 * Falls back to 0 rather than failing; if the DB doesn't support JSON
 * functions, add a `_tsr_row_count` meta to the import command instead.
 *
 * Cache lifetime: 12 hours (invalidated by bulk-import via
 * delete_transient('tsr_total_finishers') if added to class-cli.php).
 */
function tsr_homepage_total_finishers(): int {
	$cached = get_transient( 'tsr_total_finishers' );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$total = (int) $wpdb->get_var(
		"SELECT SUM( JSON_LENGTH( pm.meta_value, '$.rows' ) )
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_tsr_result_set'
		   AND p.post_type  = 'ts_result'
		   AND p.post_status = 'publish'"
	);

	set_transient( 'tsr_total_finishers', $total, 12 * HOUR_IN_SECONDS );
	return $total;
}
