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

// ── Leaflet map (front page + Трасета template) ─────────────────────────────

add_action( 'wp_enqueue_scripts', static function (): void {
	$is_traseta = is_page_template( 'page-traseta.php' );
	if ( ! is_front_page() && ! $is_traseta ) {
		return;
	}
	wp_enqueue_style(
		'leaflet',
		'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css',
		array(),
		'1.9.4'
	);
	// Load in <head> (false) so the L global exists when the inline init
	// script in front-page.php executes during body rendering.
	wp_enqueue_script(
		'leaflet',
		'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js',
		array(),
		'1.9.4',
		false
	);

	if ( $is_traseta ) {
		wp_enqueue_script(
			'tsr-traseta-modal',
			get_stylesheet_directory_uri() . '/js/traseta-modal.js',
			array( 'leaflet' ),
			wp_get_theme()->get( 'Version' ),
			true
		);
	}
}, 25 );

// ── Трасета: admin-controlled current/legacy labels ─────────────────────────

/**
 * Read the theme tracks.json (source data for the Трасета page).
 *
 * @return array<int, array<string, mixed>> Events array, empty on failure.
 */
function tsr_tracks_events(): array {
	$file = get_stylesheet_directory() . '/data/tracks.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$json = json_decode( (string) file_get_contents( $file ), true );
	return ( is_array( $json ) && ! empty( $json['events'] ) ) ? $json['events'] : array();
}

/**
 * Effective status for a track: admin override first, JSON default second.
 *
 * Overrides live in the 'tsr_track_status' option as slug => 'current'|'legacy',
 * managed via Tools → Трасета — етикети.
 *
 * @param array<string, mixed> $track Track entry from tracks.json.
 */
function tsr_track_status( array $track ): string {
	static $overrides = null;
	if ( null === $overrides ) {
		$overrides = (array) get_option( 'tsr_track_status', array() );
	}
	$status = $overrides[ $track['slug'] ] ?? ( $track['status'] ?? 'current' );
	return ( 'legacy' === $status ) ? 'legacy' : 'current';
}

/**
 * Number of loops for a track (1-3), admin-set via Tools → Трасета — етикети.
 *
 * Stored in the 'tsr_track_laps' option as slug => int. Defaults to 1 for
 * tracks with no override.
 *
 * @param array<string, mixed> $track Track entry from tracks.json.
 */
function tsr_track_laps( array $track ): int {
	static $laps = null;
	if ( null === $laps ) {
		$laps = (array) get_option( 'tsr_track_laps', array() );
	}
	$n = (int) ( $laps[ $track['slug'] ] ?? 1 );
	return ( $n >= 1 && $n <= 3 ) ? $n : 1;
}

add_action( 'admin_menu', static function (): void {
	add_management_page(
		'Трасета — етикети',
		'Трасета — етикети',
		'manage_options',
		'tsr-track-labels',
		'tsr_track_labels_page'
	);
} );

/**
 * Render (and save) the Tools → Трасета — етикети admin page.
 */
function tsr_track_labels_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Недостатъчни права.' );
	}

	$events = tsr_tracks_events();

	// Save.
	if ( isset( $_POST['tsr_track_status'] ) && check_admin_referer( 'tsr_track_labels' ) ) {
		$posted_status = (array) wp_unslash( $_POST['tsr_track_status'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- values whitelisted below.
		$posted_laps   = (array) wp_unslash( $_POST['tsr_track_laps'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- values whitelisted below.
		$clean_status  = array();
		$clean_laps    = array();
		foreach ( $events as $event ) {
			foreach ( $event['tracks'] as $track ) {
				$slug = $track['slug'];

				$status = isset( $posted_status[ $slug ] ) && 'legacy' === $posted_status[ $slug ] ? 'legacy' : 'current';
				$clean_status[ $slug ] = $status;

				$laps = isset( $posted_laps[ $slug ] ) ? (int) $posted_laps[ $slug ] : 1;
				$clean_laps[ $slug ] = ( $laps >= 1 && $laps <= 3 ) ? $laps : 1;
			}
		}
		update_option( 'tsr_track_status', $clean_status, false );
		update_option( 'tsr_track_laps', $clean_laps, false );
		echo '<div class="notice notice-success is-dismissible"><p>Етикетите са запазени.</p></div>';
	}

	echo '<div class="wrap"><h1>Трасета — етикети (Актуално / Легаси)</h1>';

	if ( empty( $events ) ) {
		echo '<p>data/tracks.json не е намерен или е празен.</p></div>';
		return;
	}

	echo '<p>Етикетът определя подредбата на страницата „Трасета“: актуалните трасета са отгоре, легаси версиите — в свито поле отдолу. Броят обиколки се показва като значка до дистанцията, когато е повече от 1.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'tsr_track_labels' );

	foreach ( $events as $event ) {
		echo '<h2 style="margin:1.5em 0 0.4em">' . esc_html( $event['name'] ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px">';
		echo '<thead><tr><th>Трасе</th><th style="width:110px">Дистанция</th><th style="width:180px">Етикет</th><th style="width:150px">Брой обиколки</th></tr></thead><tbody>';
		foreach ( $event['tracks'] as $track ) {
			$status = tsr_track_status( $track );
			$laps   = tsr_track_laps( $track );
			echo '<tr><td>' . esc_html( $track['title'] ) . '</td>';
			echo '<td>' . ( ! empty( $track['distance_km'] ) ? esc_html( number_format_i18n( (float) $track['distance_km'], 1 ) ) . ' км' : '—' ) . '</td>';
			echo '<td><select name="tsr_track_status[' . esc_attr( $track['slug'] ) . ']">';
			echo '<option value="current"' . selected( $status, 'current', false ) . '>Актуално</option>';
			echo '<option value="legacy"' . selected( $status, 'legacy', false ) . '>Легаси</option>';
			echo '</select></td>';
			echo '<td><select name="tsr_track_laps[' . esc_attr( $track['slug'] ) . ']">';
			foreach ( array( 1, 2, 3 ) as $n ) {
				echo '<option value="' . esc_attr( (string) $n ) . '"' . selected( $laps, $n, false ) . '>' . esc_html( (string) $n ) . ' обиколк' . ( 1 === $n ? 'а' : 'и' ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
	}

	submit_button( 'Запази етикетите' );
	echo '</form></div>';
}

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
