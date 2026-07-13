<?php
declare( strict_types=1 );
/**
 * TrailSeries Child Theme (Exhibz) — setup and hooks.
 *
 * Presentation only. Results data and table rendering belong to the
 * trailseries-results plugin (ADR-002). This file wires up menus,
 * enqueues stylesheets, and adds the homepage stats helper.
 *
 * @package exhibz-child
 */

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
	// Child-owned fonts: Roboto (body) + Raleway (headings), same families the
	// parent used, but one trimmed css2 request with display=swap instead of
	// the parent's exhibz-fonts + fontfaceobserver pipeline (dequeued below).
	// css2 serves Cyrillic subsets automatically via unicode-range.
	wp_enqueue_style(
		'tsr-fonts',
		'https://fonts.googleapis.com/css2?family=Raleway:wght@700;800&family=Roboto:ital,wght@0,400;0,700;1,400&display=swap',
		array(),
		null // Google versions the URL itself; a ver param only busts its cache.
	);

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

// Preconnect for the font origins — the css2 response points at
// fonts.gstatic.com, so warming both saves a DNS+TLS round trip on mobile.
add_filter( 'wp_resource_hints', static function ( array $urls, string $relation ): array {
	if ( 'preconnect' === $relation ) {
		$urls[] = array(
			'href' => 'https://fonts.googleapis.com',
		);
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
	}
	return $urls;
}, 10, 2 );

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
	// Deferred: 147 KB off the render-blocking path. Safe because the inline
	// init in front-page.php goes through initTsrMap(), which falls back to
	// DOMContentLoaded/load listeners when L is not yet defined — and deferred
	// scripts always execute before DOMContentLoaded fires.
	wp_enqueue_script(
		'leaflet',
		'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js',
		array(),
		'1.9.4',
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);

	if ( $is_traseta ) {
		// Must also be deferred: it checks `typeof L` at parse time, so a
		// non-deferred footer script would run before deferred Leaflet and
		// bail. WP keeps execution order for deferred dependency chains.
		wp_enqueue_script(
			'tsr-traseta-modal',
			get_stylesheet_directory_uri() . '/js/traseta-modal.js',
			array( 'leaflet' ),
			wp_get_theme()->get( 'Version' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}, 25 );

// ── Performance: strip unused third-party assets ─────────────────────────────
//
// Diagnosed 2026-07 (mobile LCP 13.6 s): EventON enqueues its full stack —
// ~407 KB CSS + ~925 KB JS including the Google Maps API and the Jitsi meet
// SDK — on every URL, and the Exhibz parent enqueues ~641 KB of bundle/master
// CSS+JS sitewide. Every template on this site is fully custom tsr-* markup,
// so the parent bundles are dead code everywhere; EventON is only rendered on
// the calendar page and its own event pages. Handles verified against the
// live staging HTML (link/script id attributes).
add_action( 'wp_enqueue_scripts', static function (): void {

	// True on the only pages that render EventON output.
	$is_event_page = is_page( 'calendar' )
		|| is_singular( 'ajde_events' )
		|| is_post_type_archive( 'ajde_events' )
		|| is_tax( 'event_type' )
		|| is_tax( 'event_location' );

	// Dead on ALL pages, event pages included: the Maps API is enqueued
	// without an API key (maps.googleapis.com/maps/api/js?ver=1.0 — 311 KB
	// that can only error), and nothing on the site uses Jitsi video events.
	foreach ( array( 'evcal_gmaps', 'eventon_gmaps', 'evo_jitsi' ) as $tsr_handle ) {
		wp_dequeue_script( $tsr_handle );
	}

	if ( ! $is_event_page ) {
		foreach ( array(
			'evcal_functions',
			'evcal_easing',
			'evo_handlebars',
			'evo_mobile',
			'evo_moment',
			'evo_moment_tz',
			'evo_mouse',
			'evcal_ajax_handle',
			'evo-inlinescripts-header',
		) as $tsr_handle ) {
			wp_dequeue_script( $tsr_handle );
		}
		foreach ( array(
			'evcal_google_fonts',
			'evcal_cal_default',
			'evo_font_icons',
			'eventon_dynamic_styles',
		) as $tsr_handle ) {
			wp_dequeue_style( $tsr_handle );
		}

		// With EventON gone nothing left on these pages depends on jQuery —
		// the theme's own JS (header nav, countdown, maps, charts) is all
		// vanilla. Saves 101 KB of render-blocking <head> JS. Logged-in
		// users keep it: admin-bar-adjacent plugin scripts may expect $.
		if ( ! is_user_logged_in() ) {
			wp_dequeue_script( 'jquery' );
		}
	}

	// Exhibz parent bundles — unused under the fully custom child templates.
	// The 426-byte exhibz-parent stub stays: exhibz-child declares it as a
	// dependency. Dropping exhibz-style also drops its attached inline CSS
	// (body/heading fonts), which style.css's own base typography replaces.
	foreach ( array(
		'exhibz-fonts',
		'bundle',
		'icofont',
		'exhibz-gutenberg-custom',
		'exhibz-style',
	) as $tsr_handle ) {
		wp_dequeue_style( $tsr_handle );
	}
	foreach ( array( 'bundle', 'fontfaceobserver', 'exhibz-script' ) as $tsr_handle ) {
		wp_dequeue_script( $tsr_handle );
	}
}, 100 );

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

// ── SEO fundamentals ─────────────────────────────────────────────────────────
//
// No SEO plugin is installed; meta description, Open Graph, Twitter Card,
// JSON-LD (SportsEvent + BreadcrumbList) and the XML sitemap are all hand-
// rolled here via hooks rather than per-template edits, per project
// convention (results tables belong to the plugin, everything else to the
// theme — ADR-002). The one unavoidable template edit is the visible
// breadcrumb trail on single-ts_result.php: many imported result posts have
// empty post_content, so a the_content filter would never fire for them.

/**
 * Clean event name for a ts_result post: prefers the _tsr_event_base meta
 * (set by backfill-meta or the admin uploader) and falls back to stripping
 * the " — {category}" suffix bulk-import appends to post_title.
 */
function tsr_result_event_title( WP_Post $post ): string {
	$base = get_post_meta( $post->ID, '_tsr_event_base', true );
	if ( is_string( $base ) && '' !== $base ) {
		return $base;
	}
	$pos = mb_strrpos( $post->post_title, ' — ' );
	return false !== $pos ? trim( mb_substr( $post->post_title, 0, $pos ) ) : $post->post_title;
}

/**
 * slug → ID index of every published ts_result post. One query per request,
 * shared by all hub lookups — the previous per-post LOCATE() query made a
 * page like /rezultati/ (which resolves the hub of every post) issue one
 * SQL query per result post (~900 per view).
 *
 * @return array<string, int>
 */
function tsr_result_slug_index(): array {
	static $index = null;
	if ( null !== $index ) {
		return $index;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows  = $wpdb->get_results(
		"SELECT ID, post_name FROM {$wpdb->posts}
		 WHERE post_type = 'ts_result' AND post_status = 'publish'"
	);
	$index = array();
	foreach ( $rows as $row ) {
		$index[ (string) $row->post_name ] = (int) $row->ID;
	}
	return $index;
}

/**
 * Find the hub post a ts_result post belongs to, or null when the post IS a
 * hub (or a standalone post with no siblings at all).
 *
 * Sibling slugs are "{hub_slug}-{cat_part}" — sanitize_title() collapses the
 * importer's "--" separator to a single dash (see single-ts_result.php for
 * the full explanation). Because hub_slug itself can contain dashes, the
 * only reliable way to recover it is to ask which OTHER published ts_result
 * post's slug, plus a trailing dash, is a prefix of this post's slug.
 * Trimming one dash-segment at a time enumerates exactly those candidate
 * prefixes, longest first — same result as the old per-post SQL
 * (LOCATE prefix + ORDER BY LENGTH DESC) without the per-post query.
 */
function tsr_hub_head_for( WP_Post $post ): ?WP_Post {
	if ( 'ts_result' !== $post->post_type ) {
		return null;
	}
	$index     = tsr_result_slug_index();
	$candidate = $post->post_name;
	while ( false !== ( $dash = strrpos( $candidate, '-' ) ) ) {
		$candidate = substr( $candidate, 0, $dash );
		if ( isset( $index[ $candidate ] ) && $index[ $candidate ] !== $post->ID ) {
			return get_post( $index[ $candidate ] );
		}
	}
	return null;
}

/**
 * The legacy page's base slug for a ts_result post — itself if this post is
 * a hub or a standalone post, or its hub's slug if this post is a category
 * sub-page. This is the ONLY correct input for the slug-based heuristics in
 * the trailseries-results plugin (tsr_slug_year(), tsr_slug_event_name());
 * a raw section post_name carries its category suffix and cannot be split
 * on '--' because sanitize_title() collapsed that separator before insert.
 *
 * @param WP_Post $post ts_result post.
 * @return string The resolved legacy-page slug.
 */
function tsr_slug_base( WP_Post $post ): string {
	$hub = tsr_hub_head_for( $post );
	return null !== $hub ? $hub->post_name : $post->post_name;
}

/**
 * Site logo URL from the Customizer "Main Logo" (custom_logo theme mod),
 * used as the Open Graph image fallback when a page has no featured image.
 */
function tsr_site_logo_url(): string {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id <= 0 ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, 'full' );
	return is_string( $url ) ? $url : '';
}

/**
 * Finisher count for a ts_result post, or null when the result data is
 * missing/invalid (mirrors tsr_render_results()'s own tolerance for bad data
 * — SEO metadata must never fatal a page).
 */
function tsr_result_finisher_count( int $post_id ): ?int {
	try {
		$set = TSR_Repository::load( $post_id );
	} catch ( Exception $e ) {
		return null;
	}
	return null !== $set ? count( $set->rows() ) : null;
}

/**
 * Most recent season with any _tsr_season data — the same default
 * page-klasiraniya.php falls back to when no ?sezon= is in the URL, without
 * duplicating its full $season_labels map (only the year is needed here).
 */
function tsr_latest_season(): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$year = $wpdb->get_var(
		"SELECT MAX( CAST( meta_value AS UNSIGNED ) )
		 FROM {$wpdb->postmeta}
		 WHERE meta_key = '_tsr_season'"
	);
	return $year ? (int) $year : 0;
}

/**
 * Best-effort race date from the post slug's legacy "DD-month" pattern
 * ("vladaya-21-april" → 21 April), combined with the _tsr_season year.
 * Exact month-token match only (no fuzzy prefixing) — a wrong date is worse
 * than an absent one for a SportsEvent's startDate, so this never guesses.
 * Returns an ISO 8601 date, or null when the slug carries no day/month.
 */
function tsr_result_event_date( WP_Post $post, string $season ): ?string {
	static $months = array(
		'yanuari'   => 1,
		'януари'    => 1,
		'fevruari'  => 2,
		'февруари'  => 2,
		'mart'      => 3,
		'март'      => 3,
		'april'     => 4,
		'април'     => 4,
		'may'       => 5,
		'май'       => 5,
		'yuni'      => 6,
		'юни'       => 6,
		'yuli'      => 7,
		'юли'       => 7,
		'avgust'    => 8,
		'август'    => 8,
		'septemvri' => 9,
		'септември' => 9,
		'oktomvri'  => 10,
		'октомври'  => 10,
		'noemvri'   => 11,
		'ноември'   => 11,
		'dekemvri'  => 12,
		'декември'  => 12,
	);

	$slug    = mb_strtolower( urldecode( $post->post_name ), 'UTF-8' );
	$pattern = '/(?:^|-)(\d{1,2})-(' . implode( '|', array_keys( $months ) ) . ')(?:-|$)/u';
	if ( ! preg_match( $pattern, $slug, $m ) ) {
		return null;
	}
	$day = (int) $m[1];
	if ( $day < 1 || $day > 31 || '' === $season ) {
		return null;
	}
	return sprintf( '%s-%02d-%02d', $season, $months[ $m[2] ], $day );
}

/**
 * Best-effort race location from slug keywords — the series runs almost
 * exclusively in the mountains ringing Sofia plus a handful of named
 * villages; falls back to the generic "София, България" when nothing
 * matches. Heuristic, not authoritative.
 */
function tsr_result_event_location( WP_Post $post ): string {
	$slug = mb_strtolower( urldecode( $post->post_name ), 'UTF-8' );
	$map  = array(
		'lyulin'      => 'Люлин планина, София, България',
		'buhovo'      => 'Бухово, София, България',
		'vladaya'     => 'Владая, София, България',
		'bankya'      => 'Банкя, София, България',
		'bankia'      => 'Банкя, София, България',
		'zheleznitsa' => 'Железница, София, България',
		'lokorsko'    => 'Локорско, София, България',
		'pancharevo'  => 'Панчарево, София, България',
		'simeonovo'   => 'Симеоново, София, България',
		'maliovitsa'  => 'Малиовица, Рила, България',
		'palakaria'   => 'Палакария, България',
	);
	foreach ( $map as $needle => $location ) {
		if ( str_contains( $slug, $needle ) ) {
			return $location;
		}
	}
	return 'София, България';
}

/**
 * Resolve the meta description for the current request. Also reused
 * verbatim as the JSON-LD SportsEvent 'description' and the og/twitter
 * description tags, so it is computed once per request via a static cache.
 */
function tsr_meta_description(): string {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	if ( is_front_page() ) {
		return $cached = 'TrailSeries.bg — 14 сезона планинско бягане около София. '
			. 'Golyam Sechko Run, Baba Marta Run, 7 Hills Run и още 5 събития годишно '
			. 'в 5-те планини около столицата.';
	}

	if ( is_singular( 'ts_result' ) ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$title = tsr_result_event_title( $post );
			$count = tsr_result_finisher_count( $post->ID );
			return $cached = ( null !== $count )
				? sprintf( '%s — пълни резултати, класиране и времена. %d финиширали.', $title, $count )
				: sprintf( '%s — пълни резултати, класиране и времена.', $title );
		}
	}

	if ( is_page_template( 'page-klasiraniya.php' ) ) {
		$season = isset( $_GET['sezon'] ) ? absint( $_GET['sezon'] ) : tsr_latest_season(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $cached = ( $season > 0 )
			? sprintf( 'Генерално класиране Сезон %d — точки по сезон, мъже и жени.', $season )
			: 'Генерално класиране по сезони — точки, мъже и жени.';
	}

	if ( is_page_template( 'page-rezultati.php' ) ) {
		return $cached = sprintf(
			'Всички резултати от TrailSeries — 14 сезона, %d състезания от 2012 до днес.',
			tsr_homepage_total_races()
		);
	}

	if ( is_page_template( 'page-calendar.php' ) ) {
		return $cached = 'Календар на предстоящи планински бягания от TrailSeries.bg';
	}

	if ( is_page_template( 'page-traseta.php' ) ) {
		return $cached = 'GPX трасета и профили за всички маршрути на TrailSeries';
	}

	if ( is_page_template( 'page-partniori.php' ) ) {
		return $cached = 'Партньорите на TrailSeries.bg — марките и хората, които подкрепят планинското бягане около София.';
	}

	if ( is_singular() ) {
		$excerpt = get_the_excerpt();
		if ( '' !== trim( (string) $excerpt ) ) {
			return $cached = wp_strip_all_tags( $excerpt );
		}
	}

	return $cached = 'TrailSeries.bg — трейл сериите на София. Резултати, класирания '
		. 'и календар на планинските бягания.';
}

/**
 * Meta description + Open Graph + Twitter Card tags. Runs before other
 * wp_head output (priority 1) so these land near the top of <head>.
 */
add_action( 'wp_head', static function (): void {
	$description = tsr_meta_description();
	echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";

	if ( is_front_page() ) {
		$og_url = home_url( '/' );
	} elseif ( is_singular() ) {
		$og_url = (string) get_permalink();
	} else {
		$og_url = home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
	}

	$og_title = wp_strip_all_tags( wp_get_document_title() );

	$og_image = '';
	if ( is_singular() && has_post_thumbnail() ) {
		$thumb    = get_the_post_thumbnail_url( null, 'large' );
		$og_image = is_string( $thumb ) ? $thumb : '';
	}
	if ( '' === $og_image ) {
		$og_image = tsr_site_logo_url();
	}

	$og_type = ( is_singular( 'ts_result' ) || is_singular( 'post' ) ) ? 'article' : 'website';

	echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $og_url ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	if ( '' !== $og_image ) {
		echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
	}

	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	if ( '' !== $og_image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '">' . "\n";
	}
}, 1 );

/**
 * JSON-LD SportsEvent + BreadcrumbList for single ts_result pages.
 *
 * JSON is deliberately encoded WITHOUT JSON_UNESCAPED_SLASHES: event/category
 * names can originate from admin-uploaded CSV/XLSX files (class-admin-
 * upload.php), so a literal "</script>" inside a name must not be able to
 * break out of the <script> block — escaped slashes ("\/") neutralise that.
 */
add_action( 'wp_head', static function (): void {
	if ( ! is_singular( 'ts_result' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$season = (string) get_post_meta( $post->ID, '_tsr_season', true );
	$name   = tsr_result_event_title( $post );
	$count  = tsr_result_finisher_count( $post->ID );

	$event_schema = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'SportsEvent',
		'name'        => $name,
		'description' => tsr_meta_description(),
		'sport'       => 'Trail running',
		'location'    => array(
			'@type'   => 'Place',
			'name'    => tsr_result_event_location( $post ),
			'address' => array(
				'@type'          => 'PostalAddress',
				'addressCountry' => 'BG',
			),
		),
	);
	if ( '' !== $season ) {
		$date = tsr_result_event_date( $post, $season );
		if ( null !== $date ) {
			$event_schema['startDate'] = $date;
		}
	}
	if ( null !== $count && $count > 0 ) {
		// No first-class schema.org property maps cleanly to "finisher count"
		// on a SportsEvent (maximumAttendeeCapacity means venue capacity, not
		// actual attendance) — additionalProperty is the generic, honest fit.
		$event_schema['additionalProperty'] = array(
			'@type' => 'PropertyValue',
			'name'  => 'CompetitorCount',
			'value' => $count,
		);
	}

	$crumbs   = array();
	$crumbs[] = array( 'name' => 'Начало', 'url' => home_url( '/' ) );
	$crumbs[] = array( 'name' => 'Резултати', 'url' => home_url( '/rezultati/' ) );
	if ( '' !== $season ) {
		$crumbs[] = array( 'name' => 'Сезон ' . $season, 'url' => home_url( '/klasiraniya/?sezon=' . rawurlencode( $season ) ) );
	}
	$crumbs[] = array( 'name' => $name, 'url' => (string) get_permalink( $post ) );

	$breadcrumb_schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => array(),
	);
	foreach ( $crumbs as $i => $crumb ) {
		$breadcrumb_schema['itemListElement'][] = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'name'     => $crumb['name'],
			'item'     => $crumb['url'],
		);
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $event_schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	echo '<script type="application/ld+json">' . wp_json_encode( $breadcrumb_schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 2 );

/**
 * Canonical URL for ts_result category sub-pages: point at their hub post
 * instead of self. The hub's accordion view (single-ts_result.php) renders
 * every category's full result table in the DOM, so a sub-page's own URL is
 * near-duplicate content of a section already indexed under the hub — and
 * the hub is the URL every old-site backlink and the redirect map actually
 * point to (iron rule 3). Hub posts and genuinely standalone posts (no
 * siblings at all) keep WordPress's default self-canonical.
 */
add_filter( 'get_canonical_url', static function ( string $canonical_url, WP_Post $post ): string {
	if ( 'ts_result' !== $post->post_type ) {
		return $canonical_url;
	}
	$hub = tsr_hub_head_for( $post );
	if ( null === $hub ) {
		return $canonical_url;
	}
	$hub_url = get_permalink( $hub );
	return ( is_string( $hub_url ) && '' !== $hub_url ) ? $hub_url : $canonical_url;
}, 10, 2 );

/**
 * Visible breadcrumb trail for single-ts_result.php. A function (not a
 * the_content filter) because most imported result posts have empty
 * post_content — the_content never fires for them, so a filter-based
 * approach would silently skip the majority of pages.
 */
function tsr_render_breadcrumbs( WP_Post $post ): void {
	$season = (string) get_post_meta( $post->ID, '_tsr_season', true );
	$name   = tsr_result_event_title( $post );

	$crumbs   = array();
	$crumbs[] = array( 'label' => 'Начало', 'url' => home_url( '/' ) );
	$crumbs[] = array( 'label' => 'Резултати', 'url' => home_url( '/rezultati/' ) );
	if ( '' !== $season ) {
		$crumbs[] = array( 'label' => 'Сезон ' . $season, 'url' => home_url( '/klasiraniya/?sezon=' . rawurlencode( $season ) ) );
	}
	$crumbs[] = array( 'label' => $name, 'url' => null );

	$last = count( $crumbs ) - 1;
	echo '<nav class="tsr-breadcrumbs" aria-label="Трохички">';
	foreach ( $crumbs as $i => $crumb ) {
		if ( $i > 0 ) {
			echo '<span class="tsr-breadcrumbs__sep" aria-hidden="true">/</span>';
		}
		if ( null !== $crumb['url'] && $i !== $last ) {
			echo '<a class="tsr-breadcrumbs__link" href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
		} else {
			echo '<span class="tsr-breadcrumbs__current" aria-current="page">' . esc_html( $crumb['label'] ) . '</span>';
		}
	}
	echo '</nav>';
}

/**
 * Visible breadcrumb trail + BreadcrumbList JSON-LD for the static page
 * templates (Начало / [trail /] <page>). single-ts_result.php keeps its own
 * deeper trail via tsr_render_breadcrumbs() above. The JSON-LD is emitted
 * inline next to the visible nav rather than from a wp_head hook: several
 * of these templates are slug-matched (page-{slug}.php) with no assigned
 * template, so wp_head can't reliably tell which page it serves — and
 * JSON-LD is valid anywhere in the document.
 *
 * @param string $label Current-page label (unlinked, aria-current).
 * @param array  $trail Optional middle crumbs between Начало and the label:
 *                      array of array{label: string, url: string}.
 */
function tsr_page_breadcrumbs( string $label, array $trail = array() ): void {
	$home = home_url( '/' );
	$self = (string) get_permalink();

	echo '<nav class="tsr-breadcrumbs" aria-label="Трохички">';
	echo '<a class="tsr-breadcrumbs__link" href="' . esc_url( $home ) . '">Начало</a>';
	foreach ( $trail as $crumb ) {
		echo '<span class="tsr-breadcrumbs__sep" aria-hidden="true">/</span>';
		echo '<a class="tsr-breadcrumbs__link" href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
	}
	echo '<span class="tsr-breadcrumbs__sep" aria-hidden="true">/</span>';
	echo '<span class="tsr-breadcrumbs__current" aria-current="page">' . esc_html( $label ) . '</span>';
	echo '</nav>';

	$items = array(
		array(
			'@type'    => 'ListItem',
			'position' => 1,
			'name'     => 'Начало',
			'item'     => $home,
		),
	);
	foreach ( $trail as $crumb ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => count( $items ) + 1,
			'name'     => $crumb['label'],
			'item'     => $crumb['url'],
		);
	}
	$items[] = array(
		'@type'    => 'ListItem',
		'position' => count( $items ) + 1,
		'name'     => $label,
		'item'     => $self,
	);

	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

// ── XML sitemap (/sitemap.xml, no plugin) ────────────────────────────────────

add_action( 'init', static function (): void {
	add_rewrite_rule( '^sitemap\.xml$', 'index.php?tsr_sitemap=1', 'top' );
} );

add_filter( 'query_vars', static function ( array $vars ): array {
	$vars[] = 'tsr_sitemap';
	return $vars;
} );

/**
 * One-time rewrite-rule flush when the sitemap rule changes, so the new
 * /sitemap.xml route works immediately after deploy without a manual
 * Settings → Permalinks visit. Bump the version string if the rule ever
 * changes shape.
 */
add_action( 'init', static function (): void {
	if ( '1' !== (string) get_option( 'tsr_sitemap_rule_version' ) ) {
		flush_rewrite_rules( false );
		update_option( 'tsr_sitemap_rule_version', '1', false );
	}
}, 20 );

add_action( 'template_redirect', static function (): void {
	if ( '1' !== (string) get_query_var( 'tsr_sitemap' ) ) {
		return;
	}

	header( 'Content-Type: application/xml; charset=UTF-8' );

	$xml = get_transient( 'tsr_sitemap_xml' );
	if ( ! is_string( $xml ) || '' === $xml ) {
		$xml = tsr_build_sitemap_xml();
		set_transient( 'tsr_sitemap_xml', $xml, 12 * HOUR_IN_SECONDS );
	}

	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped in tsr_build_sitemap_xml().
	exit;
} );

/**
 * Build the sitemap XML: homepage, all published WP pages (calendar,
 * klasiraniya, rekordi, pravila, istoriya, rezultati, traseta, ...), all
 * "hub" ts_result posts (category sub-pages are skipped — they canonical to
 * their hub via the get_canonical_url filter above, so listing them here
 * would submit non-canonical URLs), and all published blog posts.
 */
function tsr_build_sitemap_xml(): string {
	$urls   = array();
	$urls[] = array( 'loc' => home_url( '/' ), 'priority' => '1.0', 'changefreq' => 'daily' );

	foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
		$urls[] = array(
			'loc'        => get_permalink( $page ),
			'lastmod'    => get_the_modified_date( 'c', $page ),
			'priority'   => '0.8',
			'changefreq' => 'weekly',
		);
	}

	$result_ids = get_posts( array(
		'post_type'   => 'ts_result',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
		'orderby'     => 'ID',
		'order'       => 'ASC',
	) );
	foreach ( $result_ids as $result_id ) {
		$result_post = get_post( $result_id );
		if ( ! $result_post instanceof WP_Post || null !== tsr_hub_head_for( $result_post ) ) {
			continue; // category sub-page — skip, it canonicals to its hub.
		}
		$urls[] = array(
			'loc'        => get_permalink( $result_post ),
			'lastmod'    => get_the_modified_date( 'c', $result_post ),
			'priority'   => '0.6',
			'changefreq' => 'monthly',
		);
	}

	$blog_ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
	) );
	foreach ( $blog_ids as $blog_id ) {
		$urls[] = array(
			'loc'        => get_permalink( $blog_id ),
			'lastmod'    => get_the_modified_date( 'c', $blog_id ),
			'priority'   => '0.5',
			'changefreq' => 'monthly',
		);
	}

	ob_start();
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $urls as $u ) {
		echo "\t<url>\n";
		echo "\t\t<loc>" . esc_url( $u['loc'] ) . "</loc>\n";
		if ( ! empty( $u['lastmod'] ) ) {
			echo "\t\t<lastmod>" . esc_html( $u['lastmod'] ) . "</lastmod>\n";
		}
		echo "\t\t<changefreq>" . esc_html( $u['changefreq'] ) . "</changefreq>\n";
		echo "\t\t<priority>" . esc_html( $u['priority'] ) . "</priority>\n";
		echo "\t</url>\n";
	}
	echo '</urlset>';
	return (string) ob_get_clean();
}

// ── Results-derived cache invalidation ───────────────────────────────────────
//
// Every cache derived from ts_result data (season standings, course
// records, event histories, finisher totals, sitemap) must die when
// results change. Rather than tracking every transient key, derived
// caches embed a GENERATION number in their key (tsr_cache_gen());
// flushing = incrementing the generation, after which the old entries
// are unreachable and expire on their own TTL. The two fixed-key
// transients are deleted directly.
//
// Write paths that trigger a flush:
//  - TSR_Repository::save() fires 'tsr_results_updated' (CLI bulk-import,
//    admin XLSX upload — every validated data write).
//  - save_post_ts_result covers title/slug/status edits in wp-admin.
//  - trashed/untrashed/deleted hooks cover post removal (e.g. orphan
//    cleanup via wp post delete), which save() never sees.

/** Current cache generation for results-derived transients. */
function tsr_cache_gen(): int {
	return (int) get_option( 'tsr_results_cache_gen', 1 );
}

/** Invalidate every results-derived cache. Cheap; safe to over-fire. */
function tsr_flush_result_caches(): void {
	update_option( 'tsr_results_cache_gen', tsr_cache_gen() + 1, true );
	delete_transient( 'tsr_total_finishers' );
	delete_transient( 'tsr_sitemap_xml' );
}

add_action( 'tsr_results_updated', 'tsr_flush_result_caches' );
add_action( 'save_post_ts_result', 'tsr_flush_result_caches' );

add_action( 'trashed_post', static function ( int $post_id ): void {
	if ( 'ts_result' === get_post_type( $post_id ) ) {
		tsr_flush_result_caches();
	}
} );
add_action( 'untrashed_post', static function ( int $post_id ): void {
	if ( 'ts_result' === get_post_type( $post_id ) ) {
		tsr_flush_result_caches();
	}
} );
add_action( 'deleted_post', static function ( int $post_id, ?WP_Post $post = null ): void {
	if ( $post instanceof WP_Post && 'ts_result' === $post->post_type ) {
		tsr_flush_result_caches();
	}
}, 10, 2 );

// ── Партньори (ts_partner CPT) ───────────────────────────────────────────────
//
// Partner logos shown as a flat grid on the Партньори page template
// (page-partniori.php). Registered in the theme, not the plugin: the plugin
// owns results data only (ADR-002), and partners are pure presentation.
// One post per partner: title = name, featured image = logo, website URL in
// `_tsr_partner_url` meta. No front-end single pages — the CPT exists only
// to give club admins a wp-admin UI, so it is not public and has no rewrite.
// Display order = menu_order ("Order" box), then title.

add_action( 'init', static function (): void {
	register_post_type(
		'ts_partner',
		array(
			'labels'          => array(
				'name'                  => 'Партньори',
				'singular_name'         => 'Партньор',
				'add_new_item'          => 'Добави партньор',
				'edit_item'             => 'Редактирай партньор',
				'featured_image'        => 'Лого',
				'set_featured_image'    => 'Задай лого',
				'remove_featured_image' => 'Премахни логото',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_rest'    => false, // classic edit screen — just title, logo, URL box.
			'menu_icon'       => 'dashicons-groups',
			'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
			'rewrite'         => false,
			'capability_type' => 'page',
		)
	);
} );

/**
 * Strip bracket-shortcode-looking syntax by pattern rather than registry
 * lookup. strip_shortcodes() only removes tags present in the global
 * $shortcode_tags list — leftover markup from a disabled page-builder
 * shortcode (e.g. "[quote author=\"\" source=\"\"]…[/quote]") was never
 * registered on the front end and survives it untouched. Shortcode tags are
 * always ASCII, so this can't accidentally eat Cyrillic "[…]" prose.
 */
function tsr_strip_shortcode_syntax( string $text ): string {
	return (string) preg_replace( '/\[\/?[a-zA-Z][a-zA-Z0-9_-]*(?:\s+[^\]]*)?\]/u', '', $text );
}

/**
 * Initials for a partner's placeholder logo box: first letter of the first
 * two words ("The Barbarian" → "TB", "SLS" → "S"). Used by both
 * page-partniori.php and the homepage partners strip until a real logo is
 * uploaded as the partner's featured image.
 */
function tsr_partner_initials( string $name ): string {
	$words    = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	$initials = '';
	foreach ( array_slice( $words, 0, 2 ) as $word ) {
		$initials .= mb_substr( $word, 0, 1, 'UTF-8' );
	}
	return mb_strtoupper( $initials, 'UTF-8' );
}

add_action( 'add_meta_boxes_ts_partner', static function (): void {
	add_meta_box(
		'tsr-partner-url',
		'Уебсайт',
		static function ( WP_Post $post ): void {
			wp_nonce_field( 'tsr_partner_url', 'tsr_partner_url_nonce' );
			$url = (string) get_post_meta( $post->ID, '_tsr_partner_url', true );
			echo '<input type="url" name="tsr_partner_url" value="' . esc_attr( $url ) . '"'
				. ' style="width:100%" placeholder="https://...">';
			echo '<p class="description">Логото на страницата „Партньори“ води към този адрес. Празно = логото не е линк.</p>';
		},
		'ts_partner',
		'normal',
		'high'
	);
} );

add_action( 'save_post_ts_partner', static function ( int $post_id ): void {
	if ( ! isset( $_POST['tsr_partner_url_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['tsr_partner_url_nonce'] ), 'tsr_partner_url' )
		|| ! current_user_can( 'edit_post', $post_id )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}
	$url = esc_url_raw( wp_unslash( $_POST['tsr_partner_url'] ?? '' ) );
	if ( '' !== $url ) {
		update_post_meta( $post_id, '_tsr_partner_url', $url );
	} else {
		delete_post_meta( $post_id, '_tsr_partner_url' );
	}
} );
