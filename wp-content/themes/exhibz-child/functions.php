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
	 *   Ранкинг → /klasiraniya/
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

// ── Comments — disabled site-wide ───────────────────────────────────────────
//
// A results/news site, not a discussion forum. Belt-and-suspenders: removes
// support (hides the admin UI, comment form, comment count) AND force-closes
// via filters, so a post that already has comment_status=open (imported
// content, a future admin override) still can't accept new comments.
// Existing comments, if any, are hidden rather than deleted.

add_action( 'init', static function (): void {
	foreach ( get_post_types() as $post_type ) {
		remove_post_type_support( $post_type, 'comments' );
		remove_post_type_support( $post_type, 'trackbacks' );
	}
}, 100 );

add_filter( 'comments_open', '__return_false', 20, 2 );
add_filter( 'pings_open', '__return_false', 20, 2 );
add_filter( 'comments_array', static fn(): array => array(), 10, 2 );

// ── Stylesheets ───────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', static function (): void {
	// Fonts are self-hosted (audit L15): @font-face rules at the top of
	// style.css, woff2 files in fonts/. No Google Fonts request any more,
	// and the parent's style.css (theme header comment only) is no longer
	// enqueued — two fewer render-blocking stylesheets.

	// Child theme stylesheet (overrides + homepage sections). Busts on the
	// file's own mtime, not the theme's Version: header — that header is a
	// hand-edited string nobody reliably remembers to bump on every CSS
	// change, and with the site's long Cache-Control max-age (see
	// .htaccess), a stale ?ver= means visitors' browsers keep serving old
	// CSS against new markup indefinitely instead of just until next load.
	wp_enqueue_style(
		'exhibz-child',
		get_stylesheet_uri(),
		array(),
		(string) filemtime( get_stylesheet_directory() . '/style.css' )
	);
}, 20 );

// Preload the two Cyrillic font files every page uses above the fold —
// Geist (body/nav) and Ubuntu 700 (titles). Same-origin, so crossorigin
// is still required for font preloads. Other subsets load on demand via
// unicode-range. Map tiles get no preconnect (audit M19).
add_action( 'wp_head', static function (): void {
	$base = get_stylesheet_directory_uri() . '/fonts/';
	foreach ( array( 'geist-cyrillic.woff2', 'ubuntu-700-cyrillic.woff2' ) as $file ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "
",
			esc_url( $base . $file )
		);
	}
}, 2 );

// ── Leaflet map (front page + Трасета template) ─────────────────────────────
//
// Audit M19: Leaflet is no longer enqueued. js/tsr-leaflet.js (~1 KB) loads
// its CSS + JS from cdnjs (with SRI) only when a map is needed — the home map
// when it scrolls near the viewport, the /traseta/ map when a track modal
// opens (or a track row is hovered/focused, to get a head start).
add_action( 'wp_enqueue_scripts', static function (): void {
	$is_traseta = is_page_template( 'page-traseta.php' );
	if ( ! is_front_page() && ! $is_traseta ) {
		return;
	}
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	wp_enqueue_script(
		'tsr-leaflet',
		$uri . '/js/tsr-leaflet.js',
		array(),
		(string) filemtime( $dir . '/js/tsr-leaflet.js' ),
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);

	if ( $is_traseta ) {
		wp_enqueue_script(
			'tsr-traseta-modal',
			$uri . '/js/traseta-modal.js',
			array( 'tsr-leaflet' ),
			(string) filemtime( $dir . '/js/traseta-modal.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}, 25 );

// ── Results / ranking UI (season dropdown, gender tabs) ──────────────────────
//
// Progressive enhancement only: without JS the season form has a submit
// button and both gender lists are shown one after the other.
add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! is_page_template( array( 'page-rezultati.php', 'page-klasiraniya.php' ) ) ) {
		return;
	}
	$path = get_stylesheet_directory() . '/js/tsr-ui.js';
	wp_enqueue_script(
		'tsr-ui',
		get_stylesheet_directory_uri() . '/js/tsr-ui.js',
		array(),
		(string) filemtime( $path ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}, 25 );

/**
 * Display label of a season (the race year stored in `_tsr_season`).
 * One definition for every template (audit L10). Seasons 1–8 spanned two
 * calendar years; how those map is still an open owner decision (audit H1),
 * so 2019 and 2020 both read "Сезон 8" until then.
 */
function tsr_season_label( int $year ): string {
	if ( 0 === $year ) {
		return 'Без година';
	}
	$labels = array(
		2012 => 'Сезон 1 (2012–2013)',
		2013 => 'Сезон 2 (2013–2014)',
		2014 => 'Сезон 3 (2014–2015)',
		2015 => 'Сезон 4 (2015–2016)',
		2016 => 'Сезон 5 (2016–2017)',
		2017 => 'Сезон 6 (2017–2018)',
		2018 => 'Сезон 7 (2018–2019)',
		2019 => 'Сезон 8 (2019–2020)',
		2020 => 'Сезон 8 (2019–2020)',
		2021 => 'Сезон 9 (2021)',
		2022 => 'Сезон 10 (2022)',
		2023 => 'Сезон 11 (2023)',
		2024 => 'Сезон 12 (2024)',
		2025 => 'Сезон 13 (2025)',
		2026 => 'Сезон 14 (2026)',
		2027 => 'Сезон 15 (2027)',
	);
	return $labels[ $year ] ?? "Сезон $year";
}

/**
 * Photo of a full-bleed home-page card as a real <img> (audit M18) instead
 * of a CSS background: srcset lets phones and desktops pick a fitting
 * sub-size (300/768/1024 …) and loading="lazy" keeps below-the-fold photos
 * off the initial load. Decorative (alt=""): the card's title carries the
 * meaning. The card CSS positions it under the gradient (object-fit: cover).
 *
 * @param int|WP_Post $post  Post whose featured image to show.
 * @param string      $class CSS class of the <img>.
 * @param string      $sizes `sizes` attribute — rendered card width.
 * @return string HTML, or '' when the post has no featured image.
 */
function tsr_card_image( $post, string $class, string $sizes ): string {
	$id = get_post_thumbnail_id( $post );
	if ( ! $id ) {
		return '';
	}
	return wp_get_attachment_image(
		$id,
		'large', // src fallback; WP falls back to the original when no 'large' exists
		false,
		array(
			'class'    => $class,
			'alt'      => '',
			'sizes'    => $sizes,
			'loading'  => 'lazy',
			'decoding' => 'async',
		)
	);
}

/**
 * New uploads: JPEG sub-sizes are written as WebP (audit M18; the server's
 * Imagick supports it). Originals stay JPEG; existing uploads are untouched.
 */
add_filter( 'image_editor_output_format', static function ( array $formats ): array {
	$formats['image/jpeg'] = 'image/webp';
	return $formats;
} );

/**
 * Number of races with published results in a series season (season key:
 * start year for 2012–2019, the calendar year from 2021). A race is an event
 * name + race year, so season 8's two editions of one race count twice.
 */
function tsr_season_race_count( int $season_key ): int {
	$races = array();
	foreach ( tsr_results_index() as $entry ) {
		if ( (string) $season_key === $entry['series'] && '' !== $entry['event_base'] ) {
			$races[ $entry['event_base'] . '|' . $entry['season'] ] = true;
		}
	}
	return count( $races );
}

/** "1 състезание" / "10 състезания". */
function tsr_races_label( int $n ): string {
	return sprintf( '%d %s', $n, 1 === $n ? 'състезание' : 'състезания' );
}

/**
 * Card data for a calendar event (shared by /calendar/ and the home page).
 *
 * Times: EventON stores the wall-clock time the organiser typed as a UTC
 * timestamp, so it is read with gmdate(). A past card links to the race's
 * results when they are published.
 *
 * @return array<string, mixed>
 */
function tsr_cal_card_data( int $event_id, bool $past, string $sizes = '(max-width: 600px) calc(100vw - 2.5rem), (max-width: 1100px) 50vw, 280px' ): array {
	$title = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' );
	$ts    = (int) get_post_meta( $event_id, 'evcal_srow', true );
	$base  = tsr_event_base_name( $title );
	$url   = (string) get_permalink( $event_id );
	$res   = '';
	if ( $past ) {
		$res = tsr_event_results_url( $base, tsr_title_year( $title ) ?? (int) gmdate( 'Y', $ts ) );
		if ( '' !== $res ) {
			$url = $res;
		}
	}
	$today = strtotime( wp_date( 'Y-m-d', time(), new DateTimeZone( 'Europe/Sofia' ) ) . ' UTC' );
	return array(
		'title'    => $title,
		'url'      => $url,
		'ts'       => $ts,
		'days'     => (int) round( ( strtotime( gmdate( 'Y-m-d', $ts ) . ' UTC' ) - $today ) / DAY_IN_SECONDS ),
		'location' => trim( (string) get_post_meta( $event_id, 'evcal_location_raw', true ) ),
		'image'    => tsr_card_image( $event_id, 'tsr-cal-card__img', $sizes ),
		'dist'     => tsr_event_distance_labels( $base ),
		'results'  => '' !== $res,
	);
}

/** "след 30 дни" / "утре" / "днес". */
function tsr_in_days( int $days ): string {
	if ( $days <= 0 ) {
		return 'днес';
	}
	return 1 === $days ? 'утре' : sprintf( 'след %d дни', $days );
}

/**
 * Print one calendar card <li>.
 *
 * @param array<string, mixed> $c    tsr_cal_card_data() output.
 * @param bool                 $past Past race ("преминало", faded, results link).
 */
function tsr_cal_card_print( array $c, bool $past ): void {
	$classes = 'tsr-cal-card' . ( '' !== $c['image'] ? ' tsr-cal-card--photo' : '' ) . ( $past ? ' tsr-cal-card--past' : '' );
	$meta    = trim( $c['location'] . ( '' !== $c['location'] ? ' · ' : '' ) . gmdate( 'H:i', $c['ts'] ) );
	?>
	<li class="<?php echo esc_attr( $classes ); ?>">
		<?php
		if ( '' !== $c['image'] ) {
			echo $c['image']; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image() output.
		} else {
			echo '<span class="tsr-cal-card__mark" aria-hidden="true">' . esc_html( gmdate( 'j', $c['ts'] ) ) . '</span>';
		}
		?>
		<div class="tsr-cal-card__top">
			<time class="tsr-cal-card__date" datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $c['ts'] ) ); ?>">
				<span class="tsr-cal-card__day"><?php echo esc_html( gmdate( 'j', $c['ts'] ) ); ?></span>
				<span class="tsr-cal-card__mon"><?php echo esc_html( tsr_bg_month_short( (int) gmdate( 'n', $c['ts'] ) ) . ' ' . gmdate( 'Y', $c['ts'] ) ); ?></span>
			</time>
			<span class="tsr-cal-card__in<?php echo $past ? ' tsr-cal-card__in--past' : ''; ?>">
				<?php echo esc_html( $past ? 'преминало' : tsr_in_days( (int) $c['days'] ) ); ?>
			</span>
		</div>
		<div class="tsr-cal-card__body">
			<h3 class="tsr-cal-card__title">
				<a href="<?php echo esc_url( $c['url'] ); ?>">
					<?php echo esc_html( $c['title'] ); ?><?php if ( $c['results'] ) : ?><span class="screen-reader-text"> — резултати</span><?php endif; ?>
				</a>
			</h3>
			<p class="tsr-cal-card__meta"><?php echo esc_html( $meta ); ?></p>
			<?php if ( array() !== $c['dist'] ) : ?>
				<ul class="tsr-cal-card__dist" aria-label="Дистанции">
					<?php foreach ( $c['dist'] as $tsr_d ) : ?>
						<li><?php echo esc_html( $tsr_d ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</li>
	<?php
}

/** Full Bulgarian month name ("януари" … ). */
function tsr_bg_month( int $month ): string {
	$m = array( 1 => 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември' );
	return $m[ $month ] ?? '';
}

/**
 * Races of the series for the "Серия" menu: every race on the calendar in
 * the last 14 months or ahead, in calendar order (January → December), with
 * its history page and its latest published results.
 *
 * @return list<array{name: string, month: string, details: string, results: string}>
 */
function tsr_menu_races(): array {
	$key    = 'tsr_menu_races2_g' . tsr_cache_gen();
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$latest = array(); // base name => latest start timestamp
	if ( post_type_exists( 'ajde_events' ) ) {
		foreach ( get_posts( array( 'post_type' => 'ajde_events', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $eid ) {
			$base = tsr_event_base_name( html_entity_decode( get_the_title( $eid ), ENT_QUOTES, 'UTF-8' ) );
			$ts   = (int) get_post_meta( $eid, 'evcal_srow', true );
			if ( '' !== $base && $ts > ( $latest[ $base ] ?? 0 ) ) {
				$latest[ $base ] = $ts;
			}
		}
	}
	$since = time() - 425 * DAY_IN_SECONDS;
	$seen  = array(); // base name => latest race year with results
	foreach ( tsr_results_index() as $e ) {
		$y = (int) $e['season'];
		if ( '' !== $e['event_base'] && $y > ( $seen[ $e['event_base'] ] ?? 0 ) ) {
			$seen[ $e['event_base'] ] = $y;
		}
	}
	$races = array();
	foreach ( $latest as $base => $ts ) {
		if ( $ts < $since ) {
			continue;
		}
		$res_year = 0;
		foreach ( $seen as $rb => $y ) {
			// Latest year across spellings ("GOLYAM SECHKO RUN" in 2018).
			if ( 0 === strcasecmp( $rb, $base ) && $y > $res_year ) {
				$res_year = $y;
			}
		}
		$races[] = array(
			'name'    => $base,
			'month'   => tsr_bg_month( (int) gmdate( 'n', $ts ) ),
			'order'   => (int) gmdate( 'n', $ts ) * 100 + (int) gmdate( 'j', $ts ),
			'details' => add_query_arg( 'sabitie', sanitize_title( $base ), home_url( '/event/' ) ),
			'results' => $res_year ? tsr_event_results_url( $base, $res_year ) : '',
		);
	}
	usort( $races, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
	set_transient( $key, $races, 12 * HOUR_IN_SECONDS );
	return $races;
}

/**
 * Usual month of a series race, for ordering races that have no calendar
 * entry (seasons before the calendar existed). From the old site's dates.
 */
function tsr_race_usual_month( string $base ): int {
	static $months = array(
		'golyam sechko'  => 1, 'golyamsechko'  => 1, 'golyam secko' => 1,
		'malak sechko'   => 2, 'malaksechko'   => 2,
		'baba marta'     => 3,
		'vladaya'        => 4,
		'palakaria'      => 5, 'палакария' => 5, 'buhovo run' => 5, 'buhovorun' => 5, 'lyulin' => 6,
		'7 hills'        => 6,
		'pancharevo night' => 7, 'heat stroke' => 7, 'bankya summer' => 8, 'maliovitsa' => 8,
		'simeonovo'      => 9, 'boyana x' => 9, 'september run' => 9, 'iran' => 9,
		'birthday run'   => 10, 'buhovo half' => 10, 'vertical' => 10,
		'cactus'         => 11,
		'christmas'      => 12, 'chrismas' => 12, 'коледн' => 12,
	);
	$b = mb_strtolower( $base );
	foreach ( $months as $needle => $m ) {
		if ( false !== mb_strpos( $b, $needle ) ) {
			return $m;
		}
	}
	// 2012–2013 races are named by their date ("Банкя 24 март").
	$bg = array( 'януари' => 1, 'февруари' => 2, 'март' => 3, 'април' => 4, 'май' => 5, 'юни' => 6, 'юли' => 7, 'август' => 8, 'септември' => 9, 'октомври' => 10, 'ноември' => 11, 'декември' => 12 );
	foreach ( $bg as $name => $m ) {
		if ( false !== mb_strpos( $b, $name ) ) {
			return $m;
		}
	}
	if ( preg_match( '/\b\d{1,2}[.\/](\d{1,2})\b/', $b, $mm ) ) {
		return (int) $mm[1];
	}
	return 13; // unknown: after the dated races of its year
}

/**
 * Navigation data for a result page (season / race / distance switchers,
 * previous / next race). Everything comes from the cached results index.
 *
 * @param WP_Post $post The result post being viewed (hub or section).
 * @param bool    $is_hub Whether the page shows all sections of its race.
 * @return array<string, mixed>|null Null when the post is not in the index.
 */
function tsr_results_nav( WP_Post $post, bool $is_hub ): ?array {
	$index = tsr_results_index();
	$cur   = $index[ $post->ID ] ?? null;
	if ( null === $cur || '' === $cur['event_base'] || '' === $cur['series'] ) {
		return null;
	}
	$norm = static fn( string $s ): string => mb_strtolower( trim( $s ) );

	// Calendar dates per race year + base (newer seasons only).
	static $cal = null;
	if ( null === $cal ) {
		$cal = array();
		if ( post_type_exists( 'ajde_events' ) ) {
			foreach ( get_posts( array( 'post_type' => 'ajde_events', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $eid ) {
				$t  = html_entity_decode( get_the_title( $eid ), ENT_QUOTES, 'UTF-8' );
				$ts = (int) get_post_meta( $eid, 'evcal_srow', true );
				$y  = tsr_title_year( $t ) ?? (int) gmdate( 'Y', $ts );
				$cal[ $y ][ mb_strtolower( tsr_event_base_name( $t ) ) ] = $ts;
			}
		}
	}

	// Races: series season => race key ("base|year") => data.
	$races = array();
	foreach ( $index as $id => $e ) {
		if ( '' === $e['series'] || '' === $e['event_base'] ) {
			continue;
		}
		$rk = $norm( $e['event_base'] ) . '|' . $e['season'];
		$r  = &$races[ $e['series'] ][ $rk ];
		if ( null === $r ) {
			$y  = (int) $e['season'];
			$ts = $cal[ $y ][ $norm( $e['event_base'] ) ] ?? 0;
			$r  = array(
				'name'  => $e['event_base'],
				'year'  => $y,
				'ts'    => $ts,
				'sort'  => $ts > 0 ? $y * 10000 + (int) gmdate( 'n', $ts ) * 100 + (int) gmdate( 'j', $ts ) : $y * 10000 + tsr_race_usual_month( $e['event_base'] ) * 100,
				'posts' => array(),
			);
		}
		$r['posts'][ $id ] = $e;
		unset( $r );
	}
	foreach ( $races as &$season_races ) {
		uasort( $season_races, static fn( array $a, array $b ): int => $a['sort'] <=> $b['sort'] );
	}
	unset( $season_races );

	$season  = $cur['series'];
	$race_k  = $norm( $cur['event_base'] ) . '|' . $cur['season'];
	$race    = $races[ $season ][ $race_k ] ?? null;
	if ( null === $race ) {
		return null;
	}
	$cur_km  = $cur['km'];
	$cur_g   = $cur['gender'];

	// URL of a race: same distance + gender when it has one, else its hub.
	$race_url = static function ( array $r, bool $match ) use ( $cur_km, $cur_g, $is_hub ): string {
		$hub = 0;
		foreach ( $r['posts'] as $id => $e ) {
			$p = get_post( $id );
			if ( $p instanceof WP_Post && null === tsr_hub_head_for( $p ) ) {
				$hub = $id;
				break;
			}
		}
		if ( $match && ! $is_hub && '' !== $cur_km ) {
			foreach ( $r['posts'] as $id => $e ) {
				if ( $e['km'] === $cur_km && $e['gender'] === $cur_g ) {
					return (string) get_permalink( $id );
				}
			}
		}
		return (string) get_permalink( $hub ? $hub : array_key_first( $r['posts'] ) );
	};
	$label = static function ( array $r ): string {
		$d = $r['ts'] > 0 ? ' · ' . gmdate( 'j', $r['ts'] ) . ' ' . tsr_bg_month_short( (int) gmdate( 'n', $r['ts'] ) ) : '';
		return $r['name'] . $d;
	};

	// Season switcher: same race in each season if it ran, else that season's list.
	$seasons = array_keys( $races );
	rsort( $seasons, SORT_NUMERIC );
	$season_opts = array();
	foreach ( $seasons as $s ) {
		$same = null;
		foreach ( $races[ $s ] as $r ) {
			if ( $norm( $r['name'] ) === $norm( $cur['event_base'] ) ) {
				$same = $r;
			}
		}
		$season_opts[] = array(
			'label'   => tsr_season_label( (int) $s ),
			'url'     => null !== $same ? $race_url( $same, true ) : add_query_arg( 'sezon', $s, home_url( '/rezultati/' ) ),
			'current' => (string) $s === (string) $season,
		);
	}

	// Race switcher + previous / next.
	$race_opts = array();
	$keys      = array_keys( $races[ $season ] );
	$pos       = array_search( $race_k, $keys, true );
	foreach ( $races[ $season ] as $k => $r ) {
		$race_opts[] = array(
			'label'   => $label( $r ),
			'url'     => $race_url( $r, true ),
			'current' => $k === $race_k,
		);
	}
	$prev = ( false !== $pos && $pos > 0 ) ? $races[ $season ][ $keys[ $pos - 1 ] ] : null;
	$next = ( false !== $pos && $pos < count( $keys ) - 1 ) ? $races[ $season ][ $keys[ $pos + 1 ] ] : null;

	// Distances of this race: km (longest first) => gender links.
	$dists = array();
	foreach ( $race['posts'] as $id => $e ) {
		$km = '' !== $e['km'] ? $e['km'] : '';
		$g  = in_array( $e['gender'], array( 'M', 'F' ), true ) ? $e['gender'] : '';
		$p  = get_post( $id );
		$dk = '' !== $km ? 'km' . str_pad( (string) (int) round( (float) $km * 100 ), 7, '0', STR_PAD_LEFT ) : 'x' . $id;
		$dists[ $dk ]['label'] = '' !== $km ? str_replace( '.', ',', (string) (float) $km ) . ' км' : '';
		$dists[ $dk ]['links'][] = array(
			'text'    => 'M' === $g ? 'Мъже' : ( 'F' === $g ? 'Жени' : ( $p ? TSR_Section_Meta::title_category_suffix( $p->post_title ) : '' ) ),
			'url'     => (string) get_permalink( $id ),
			'current' => ! $is_hub && $id === $post->ID,
			'order'   => 'M' === $g ? 0 : ( 'F' === $g ? 1 : 2 ),
		);
	}
	krsort( $dists );
	foreach ( $dists as &$d ) {
		usort( $d['links'], static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
	}
	unset( $d );

	return array(
		'seasons' => $season_opts,
		'races'   => $race_opts,
		'prev'    => null !== $prev ? array( 'label' => $prev['name'], 'url' => $race_url( $prev, true ) ) : null,
		'next'    => null !== $next ? array( 'label' => $next['name'], 'url' => $race_url( $next, true ) ) : null,
		'dists'   => array_values( $dists ),
		'hub_url' => $race_url( $race, false ),
		'is_hub'  => $is_hub,
		'title'   => $race['name'] . ' ' . $race['year'],
	);
}

/** Print the results navigation bar. */
function tsr_results_nav_print( array $n ): void {
	$cur_dist = '';
	foreach ( $n['dists'] as $d ) {
		foreach ( $d['links'] as $l ) {
			if ( $l['current'] ) {
				$cur_dist = trim( $d['label'] . ' ' . $l['text'] );
			}
		}
	}
	?>
	<nav class="tsr-rnav" aria-label="Смяна на сезон, състезание и дистанция" data-tsr-rnav>
		<button class="tsr-rnav__compact" type="button" aria-expanded="false" hidden>
			<span><?php echo esc_html( ( '' !== $cur_dist ? $cur_dist . ' · ' : '' ) . $n['title'] ); ?></span>
			<span class="tsr-rnav__compact-cta">Смени</span>
		</button>
		<div class="tsr-rnav__body">
			<div class="tsr-rnav__row">
				<label class="tsr-rnav__select">
					<span class="tsr-rnav__select-label">Сезон</span>
					<select data-tsr-go>
						<?php foreach ( $n['seasons'] as $o ) : ?>
							<option value="<?php echo esc_url( $o['url'] ); ?>"<?php selected( $o['current'] ); ?>><?php echo esc_html( $o['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="tsr-rnav__select">
					<span class="tsr-rnav__select-label">Състезание</span>
					<select data-tsr-go>
						<?php foreach ( $n['races'] as $o ) : ?>
							<option value="<?php echo esc_url( $o['url'] ); ?>"<?php selected( $o['current'] ); ?>><?php echo esc_html( $o['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="tsr-rnav__steps">
					<?php if ( $n['prev'] ) : ?>
						<a class="tsr-rnav__step" href="<?php echo esc_url( $n['prev']['url'] ); ?>" rel="prev"><span aria-hidden="true">‹</span> <?php echo esc_html( $n['prev']['label'] ); ?><span class="screen-reader-text"> — предишно състезание</span></a>
					<?php endif; ?>
					<?php if ( $n['next'] ) : ?>
						<a class="tsr-rnav__step" href="<?php echo esc_url( $n['next']['url'] ); ?>" rel="next"><?php echo esc_html( $n['next']['label'] ); ?><span class="screen-reader-text"> — следващо състезание</span> <span aria-hidden="true">›</span></a>
					<?php endif; ?>
				</div>
			</div>
			<ul class="tsr-rnav__dists">
				<?php foreach ( $n['dists'] as $d ) : ?>
					<li class="tsr-rnav__dist">
						<?php if ( '' !== $d['label'] ) : ?>
							<span class="tsr-rnav__km"><?php echo esc_html( $d['label'] ); ?></span>
						<?php endif; ?>
						<?php foreach ( $d['links'] as $l ) : ?>
							<a href="<?php echo esc_url( $l['url'] ); ?>"<?php echo $l['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $l['text'] ); ?><?php if ( '' !== $d['label'] ) : ?><span class="screen-reader-text"> — <?php echo esc_html( $d['label'] ); ?></span><?php endif; ?></a>
						<?php endforeach; ?>
					</li>
				<?php endforeach; ?>
				<li>
					<a class="tsr-rnav__all" href="<?php echo esc_url( $n['hub_url'] ); ?>"<?php echo $n['is_hub'] ? ' aria-current="page"' : ''; ?>>Всички дистанции ›</a>
				</li>
			</ul>
		</div>
	</nav>
	<script>
	(function () {
		var nav = document.currentScript.previousElementSibling;
		nav.querySelectorAll( 'select[data-tsr-go]' ).forEach( function ( s ) {
			s.addEventListener( 'change', function () { if ( s.value ) { window.location.href = s.value; } } );
		} );
		// Phones: once scrolled past, the bar shrinks to one line that opens it.
		var compact = nav.querySelector( '.tsr-rnav__compact' );
		var mq      = window.matchMedia( '(max-width: 768px)' );
		compact.hidden = false;
		compact.addEventListener( 'click', function () {
			var open = nav.classList.toggle( 'is-open' );
			compact.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		var top = nav.getBoundingClientRect().top + window.scrollY;
		var head = document.getElementById( 'masthead' );
		var onScroll = function () {
			// stick right under the (shrinking) site header
			nav.style.top = ( head ? Math.round( head.getBoundingClientRect().height ) : 0 ) + 'px';
			var stuck = mq.matches && window.scrollY > top + nav.offsetHeight;
			nav.classList.toggle( 'is-compact', stuck );
			if ( ! stuck ) { nav.classList.remove( 'is-open' ); compact.setAttribute( 'aria-expanded', 'false' ); }
		};
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();
	}());
	</script>
	<?php
}

/** Short Bulgarian month name ("ян", "февр", … ) for date badges. */
function tsr_bg_month_short( int $month ): string {
	$m = array( 1 => 'ян', 'февр', 'март', 'апр', 'май', 'юни', 'юли', 'авг', 'септ', 'окт', 'ноем', 'дек' );
	return $m[ $month ] ?? '';
}

/**
 * Distance labels of a race ("6 км", "13 км", …) from data/tracks.json:
 * the nominal distance in each current track's name, shortest first. Tracks
 * whose nominal distances are within 1 km (variants of one course) show
 * once; at most four labels. An event's "calendar_km" list overrides this.
 *
 * @return list<string>
 */
function tsr_event_distance_labels( string $event_base ): array {
	if ( '' === $event_base ) {
		return array();
	}
	$kms = array();
	foreach ( tsr_tracks_events() as $event ) {
		if ( 0 !== strcasecmp( (string) ( $event['name'] ?? '' ), $event_base ) ) {
			continue;
		}
		// Owner-set list wins (a race distance without a published track,
		// e.g. Buhovo Half Marathon's 10 km): "calendar_km" on the event.
		if ( ! empty( $event['calendar_km'] ) && is_array( $event['calendar_km'] ) ) {
			$kms = array_map( 'floatval', $event['calendar_km'] );
			break;
		}
		foreach ( (array) ( $event['tracks'] ?? array() ) as $track ) {
			if ( 'current' !== tsr_track_status( $track ) ) {
				continue;
			}
			$label = (string) ( $track['variant'] ?? $track['title'] ?? '' );
			if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*(?:км|km)/iu', $label, $m ) ) {
				$kms[] = (float) str_replace( ',', '.', $m[1] );
			} elseif ( ! empty( $track['distance_km'] ) ) {
				$kms[] = round( (float) $track['distance_km'] );
			}
		}
	}
	sort( $kms );
	$out  = array();
	$last = null;
	foreach ( $kms as $km ) {
		if ( null !== $last && $km - $last <= 1.0 ) { // 15 and 16 km are variants of one course
			continue;
		}
		$last  = $km;
		$out[] = str_replace( '.', ',', rtrim( rtrim( number_format( $km, 1, '.', '' ), '0' ), '.' ) ) . ' км';
	}
	return array_slice( $out, 0, 4 );
}

/**
 * URL of the published results of a race edition (its hub page), or ''.
 * Matched on `_tsr_event_base` + race year via the results index.
 */
function tsr_event_results_url( string $event_base, int $year ): string {
	if ( '' === $event_base ) {
		return '';
	}
	$ids = array();
	foreach ( tsr_results_index() as $id => $entry ) {
		if ( (string) $year === $entry['season'] && 0 === strcasecmp( $entry['event_base'], $event_base ) ) {
			$ids[] = $id;
		}
	}
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post instanceof WP_Post && null === tsr_hub_head_for( $post ) ) {
			return (string) get_permalink( $post );
		}
	}
	return array() !== $ids ? (string) get_permalink( $ids[0] ) : '';
}

/**
 * Bulgarian date "8 ноември 2026" regardless of the site locale (the staging
 * site language is English, so wp_date( 'F' ) gives English month names).
 */
function tsr_bg_date( int $ts ): string {
	$months = array( 1 => 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември' );
	return wp_date( 'j', $ts ) . ' ' . $months[ (int) wp_date( 'n', $ts ) ] . ' ' . wp_date( 'Y', $ts );
}

/**
 * Season dropdown (GET form on the current page). Submits on change via
 * js/tsr-ui.js; the button is the no-JS fallback and is hidden by the script.
 *
 * @param list<int> $years   Seasons to offer, in display order.
 * @param int       $current Selected season.
 * @param string    $note    Optional text after the picker (e.g. "7 състезания").
 */
function tsr_season_select( array $years, int $current, string $note = '' ): void {
	?>
	<form class="tsr-toolbar" method="get" action="<?php echo esc_url( get_permalink() ); ?>">
		<label class="tsr-toolbar__label" for="tsr-season">Сезон</label>
		<select class="tsr-select" id="tsr-season" name="sezon" data-tsr-autosubmit>
			<?php foreach ( $years as $year ) : ?>
				<option value="<?php echo esc_attr( (string) $year ); ?>"<?php selected( $year, $current ); ?>>
					<?php echo esc_html( tsr_season_label( (int) $year ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button class="tsr-toolbar__go" type="submit">Покажи</button>
		<?php if ( '' !== $note ) : ?>
			<span class="tsr-toolbar__note"><?php echo esc_html( $note ); ?></span>
		<?php endif; ?>
	</form>
	<?php
}

// ── Emoji loader off ─────────────────────────────────────────────────────────
//
// wp-emoji-loader tests emoji support in a blob: Worker, which the host's
// Content-Security-Policy (no worker-src blob:) blocks — a console error on
// every page (Lighthouse "errors-in-console", 2026-11-13). Every supported
// browser renders emoji natively, so the loader and its inline CSS go.
add_action( 'init', static function (): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
} );

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
	// (Single events use the theme's single-ajde_events.php since 2026-11-19
	// and need none of EventON's assets.)
	$is_event_page = is_page( 'calendar' )
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
	// (The parent's 426-byte style.css stub is not enqueued at all — L15.)
	// Dropping exhibz-style also drops its attached inline CSS
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

/**
 * Garmin Connect course link for a track, admin-set via Tools → Трасета —
 * етикети ('tsr_track_garmin' option, slug => URL). '' when none.
 *
 * @param array<string, mixed> $track Track entry from tracks.json.
 */
function tsr_track_garmin( array $track ): string {
	static $urls = null;
	if ( null === $urls ) {
		$urls = (array) get_option( 'tsr_track_garmin', array() );
	}
	return (string) ( $urls[ $track['slug'] ] ?? '' );
}

/**
 * Strava route link for a track: the admin value from Tools → Трасета —
 * етикети ('tsr_track_strava' option, slug => URL) wins over tracks.json.
 * An admin value of '-' hides the JSON link.
 *
 * @param array<string, mixed> $track Track entry from tracks.json.
 */
function tsr_track_strava( array $track ): string {
	static $urls = null;
	if ( null === $urls ) {
		$urls = (array) get_option( 'tsr_track_strava', array() );
	}
	$url = (string) ( $urls[ $track['slug'] ] ?? '' );
	if ( '-' === $url ) {
		return '';
	}
	return '' !== $url ? $url : (string) ( $track['strava_route_url'] ?? '' );
}

/**
 * A pasted Strava link, or '' when it is not an https link to Strava
 * (strava.com routes, or the strava.app.link short links of the app).
 */
function tsr_strava_url_clean( string $raw ): string {
	$url  = esc_url_raw( $raw, array( 'https' ) );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return in_array( $host, array( 'www.strava.com', 'strava.com', 'strava.app.link' ), true ) ? $url : '';
}

/**
 * A pasted Garmin link, or '' when it is not an https link to Garmin Connect
 * (connect.garmin.com, or the gar.mn short links its share button makes).
 */
function tsr_garmin_url_clean( string $raw ): string {
	$url  = esc_url_raw( $raw, array( 'https' ) );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return in_array( $host, array( 'connect.garmin.com', 'gar.mn' ), true ) ? $url : '';
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

// ── Single calendar event: the theme's own page (owner-approved 2026-11-19) ──
//
// EventON swaps in its own single-event template at template_include
// priority 99; this runs after it so single-ajde_events.php wins. (A stub
// file named single-ajde_events.php alone is NOT enough — see 867a96d.)
add_filter( 'template_include', static function ( string $template ): string {
	if ( is_singular( 'ajde_events' ) ) {
		$own = locate_template( 'single-ajde_events.php' );
		if ( '' !== $own ) {
			return $own;
		}
	}
	return $template;
}, 100 );

/**
 * Start and end of an event as wall-clock timestamps (EventON stores the
 * typed local time as UTC — read with gmdate()). End falls back to +3 h.
 *
 * @return array{0: int, 1: int}
 */
function tsr_event_times( int $event_id ): array {
	$start = (int) get_post_meta( $event_id, 'evcal_srow', true );
	$end   = (int) get_post_meta( $event_id, 'evcal_erow', true );
	if ( $end <= $start || gmdate( 'Y-m-d', $end ) !== gmdate( 'Y-m-d', $start ) ) {
		$end = $start + 3 * HOUR_IN_SECONDS;
	}
	return array( $start, $end );
}

/** "Как да стигна" link: the admin value, else a Google Maps search of the place. */
function tsr_event_directions_url( int $event_id ): string {
	$url = (string) get_post_meta( $event_id, '_tsr_directions_url', true );
	if ( '' !== $url ) {
		return $url;
	}
	$place = trim( (string) get_post_meta( $event_id, 'evcal_location_raw', true ) );
	return '' !== $place ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $place . ', България' ) : '';
}

/**
 * Programme lines from the admin field: "09:30 – Регистрация" per line.
 *
 * @return list<array{0: string, 1: string}> [time, text]
 */
function tsr_event_programme( int $event_id ): array {
	$out = array();
	foreach ( preg_split( '/\R/u', (string) get_post_meta( $event_id, '_tsr_programme', true ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( preg_match( '/^(\d{1,2}[:.]\d{2}(?:\s*[–-]\s*\d{1,2}[:.]\d{2})?)\s*(?:[–—-]\s*|\s)(.+)$/u', $line, $m ) ) {
			$out[] = array( str_replace( array( '.', ' ' ), array( ':', '' ), $m[1] ), trim( $m[2] ) );
		} else {
			$out[] = array( '', $line );
		}
	}
	return $out;
}

// "Добави в календара": /events/<slug>/?ics=1 downloads an .ics file.
add_action( 'template_redirect', static function (): void {
	if ( ! is_singular( 'ajde_events' ) || ! isset( $_GET['ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$id               = get_queried_object_id();
	[ $start, $end ]  = tsr_event_times( $id );
	if ( $start <= 0 ) {
		return;
	}
	$esc   = static fn( string $t ): string => str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $t );
	$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	$place = trim( (string) get_post_meta( $id, 'evcal_location_raw', true ) );
	$lines = array(
		'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TrailSeries.bg//BG', 'CALSCALE:GREGORIAN',
		'BEGIN:VEVENT',
		'UID:event-' . $id . '@trailseries.bg',
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART;TZID=Europe/Sofia:' . gmdate( 'Ymd\THis', $start ),
		'DTEND;TZID=Europe/Sofia:' . gmdate( 'Ymd\THis', $end ),
		'SUMMARY:' . $esc( $title ),
		'LOCATION:' . $esc( $place ),
		'URL:' . get_permalink( $id ),
		'DESCRIPTION:' . $esc( 'Trail Series — ' . get_permalink( $id ) ),
		'END:VEVENT', 'END:VCALENDAR',
	);
	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_title( $title ) . '.ics"' );
	echo implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- iCalendar text, escaped above.
	exit;
} );

// Admin: "Страница на събитието" box on the EventON edit screen (owner,
// 2026-11-19). Description = the normal editor; everything else here.
// Multi-line fields use one item per line, parts split with "|".

/** Text fields of the event box: meta key => [label, help, rows (0 = one line)]. */
function tsr_event_text_fields(): array {
	return array(
		'_tsr_programme'     => array( 'Програма', 'По един ред: час – текст. Напр. „09:00–10:30 – Получаване на стартови номера“.', 5 ),
		'_tsr_highlights'    => array( 'Акценти (карти под описанието)', 'По един ред: емоджи | заглавие | текст. Напр. „🎅 | Бягаме дегизирани | Дядо Коледа, Снежанка…“.', 4 ),
		'_tsr_fees'          => array( 'Такса участие', 'По един ред: сума | заглавие | бележка. Първият ред е основната такса (оранжева рамка). Напр. „15 € | Редовна такса | при регистрация в drace.bg“.', 4 ),
		'_tsr_reg_url'       => array( 'Линк за регистрация', 'Напр. https://drace.bg/bg/event/… — оранжевият бутон „Регистрация“.', 0 ),
		'_tsr_kids'          => array( 'Детско бягане', 'Кратко, напр. „1000 м · безплатно“. Празно = няма.', 0 ),
		'_tsr_surface'       => array( 'Настилка', 'Напр. „горски пътеки и алеи“.', 0 ),
		'_tsr_support'       => array( 'Със съдействието на', 'Напр. „Столична община – район Банкя“. Празно = не се показва.', 0 ),
		'_tsr_getting_there' => array( 'Как да стигна — указания', 'По един ред: емоджи | заглавие | текст. Напр. „🚌 | Автобус 47 | от метростанция „Сливница“.', 4 ),
		'_tsr_start_photo'   => array( 'Снимка на мястото на старта (линк)', 'Линк към изображение (Медия → копирай URL).', 0 ),
		'_tsr_directions_url' => array( 'Как да стигна — линк към картата', 'Google Maps и др. Празно = търсене по мястото на събитието.', 0 ),
	);
}

/** "a | b | c" lines → list of trimmed parts per line. */
function tsr_event_lines( int $event_id, string $key ): array {
	$out = array();
	foreach ( preg_split( '/\R/u', (string) get_post_meta( $event_id, $key, true ) ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$out[] = array_map( 'trim', explode( '|', $line ) );
		}
	}
	return $out;
}

/** Partners picked for an event (ts_partner posts); none picked = all. */
function tsr_event_partners( int $event_id ): array {
	$picked = array_filter( array_map( 'intval', (array) get_post_meta( $event_id, '_tsr_partners', true ) ) );
	$all    = get_posts( array( 'post_type' => 'ts_partner', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) );
	return $picked ? array_values( array_filter( $all, static fn( WP_Post $p ): bool => in_array( $p->ID, $picked, true ) ) ) : $all;
}

/**
 * The season's cause block ("Една от 8"), edited once in Tools → Кауза на
 * сезона; each event can hide it.
 *
 * @return array{on: bool, kicker: string, title: string, text: string, image: string, links: list<array{0: string, 1: string}>}
 */
function tsr_cause(): array {
	$c = wp_parse_args(
		(array) get_option( 'tsr_cause', array() ),
		array( 'on' => false, 'kicker' => '', 'title' => '', 'text' => '', 'image' => '', 'links' => '' )
	);
	$links = array();
	foreach ( preg_split( '/\R/u', (string) $c['links'] ) as $line ) {
		$p = array_map( 'trim', explode( '|', $line ) );
		if ( 2 === count( $p ) && '' !== $p[0] && '' !== $p[1] ) {
			$links[] = $p;
		}
	}
	return array(
		'on'     => (bool) $c['on'],
		'kicker' => (string) $c['kicker'],
		'title'  => (string) $c['title'],
		'text'   => (string) $c['text'],
		'image'  => (string) $c['image'],
		'links'  => $links,
	);
}

add_action( 'add_meta_boxes_ajde_events', static function (): void {
	add_meta_box( 'tsr-event-extra', 'Страница на събитието (TrailSeries)', static function ( WP_Post $post ): void {
		wp_nonce_field( 'tsr_event_extra', 'tsr_event_extra_nonce' );
		echo '<p>Описанието („За състезанието“) е обикновеното съдържание горе в редактора. Празно поле = секцията не се показва.</p>';
		foreach ( tsr_event_text_fields() as $key => [ $label, $help, $rows ] ) {
			$val = (string) get_post_meta( $post->ID, $key, true );
			$id  = 'tsr' . str_replace( '_', '-', $key );
			echo '<p style="margin:1.1em 0 .3em"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><span class="description">' . esc_html( $help ) . '</span></p>';
			if ( $rows > 0 ) {
				echo '<textarea id="' . esc_attr( $id ) . '" name="tsr_ev[' . esc_attr( $key ) . ']" rows="' . (int) $rows . '" style="width:100%">' . esc_textarea( $val ) . '</textarea>';
			} else {
				echo '<input id="' . esc_attr( $id ) . '" type="text" name="tsr_ev[' . esc_attr( $key ) . ']" style="width:100%" value="' . esc_attr( $val ) . '">';
			}
		}
		$picked = array_map( 'intval', (array) get_post_meta( $post->ID, '_tsr_partners', true ) );
		echo '<p style="margin:1.1em 0 .3em"><strong>Партньори на събитието</strong><br><span class="description">Отметни кои логота да се показват. Нищо отметнато = всички от „Партньори“.</span></p><p>';
		foreach ( get_posts( array( 'post_type' => 'ts_partner', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) ) as $p ) {
			echo '<label style="display:inline-block;margin:0 1.2em .4em 0"><input type="checkbox" name="tsr_partners[]" value="' . (int) $p->ID . '"' . checked( in_array( $p->ID, $picked, true ), true, false ) . '> ' . esc_html( get_the_title( $p ) ) . '</label>';
		}
		echo '</p>';
		echo '<p style="margin:1.1em 0 .3em"><label><input type="checkbox" name="tsr_hide_cause" value="1"' . checked( '1', get_post_meta( $post->ID, '_tsr_hide_cause', true ), false ) . '> <strong>Скрий блока „Кауза на сезона“</strong> на това събитие</label></p>';
	}, 'ajde_events', 'normal', 'high' );
} );

add_action( 'save_post_ajde_events', static function ( int $post_id ): void {
	if ( ! isset( $_POST['tsr_event_extra_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tsr_event_extra_nonce'] ) ), 'tsr_event_extra' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$posted = (array) wp_unslash( $_POST['tsr_ev'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
	foreach ( tsr_event_text_fields() as $key => [ , , $rows ] ) {
		$raw = (string) ( $posted[ $key ] ?? '' );
		if ( in_array( $key, array( '_tsr_reg_url', '_tsr_directions_url', '_tsr_start_photo' ), true ) ) {
			$val = esc_url_raw( trim( $raw ), array( 'https' ) );
		} else {
			$val = $rows > 0 ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		'' !== $val ? update_post_meta( $post_id, $key, $val ) : delete_post_meta( $post_id, $key );
	}
	$partners = array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['tsr_partners'] ?? array() ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- ints.
	$partners ? update_post_meta( $post_id, '_tsr_partners', $partners ) : delete_post_meta( $post_id, '_tsr_partners' );
	empty( $_POST['tsr_hide_cause'] ) ? delete_post_meta( $post_id, '_tsr_hide_cause' ) : update_post_meta( $post_id, '_tsr_hide_cause', '1' );
} );

// Tools → Кауза на сезона: the cause block shown on every event page.
add_action( 'admin_menu', static function (): void {
	add_management_page( 'Кауза на сезона', 'Кауза на сезона', 'manage_options', 'tsr-cause', static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостатъчни права.' );
		}
		if ( isset( $_POST['tsr_cause'] ) && check_admin_referer( 'tsr_cause' ) ) {
			$in = (array) wp_unslash( $_POST['tsr_cause'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
			update_option( 'tsr_cause', array(
				'on'     => ! empty( $in['on'] ),
				'kicker' => sanitize_text_field( (string) ( $in['kicker'] ?? '' ) ),
				'title'  => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
				'text'   => sanitize_textarea_field( (string) ( $in['text'] ?? '' ) ),
				'image'  => esc_url_raw( trim( (string) ( $in['image'] ?? '' ) ), array( 'https' ) ),
				'links'  => sanitize_textarea_field( (string) ( $in['links'] ?? '' ) ),
			), false );
			echo '<div class="notice notice-success is-dismissible"><p>Запазено.</p></div>';
		}
		$c = wp_parse_args( (array) get_option( 'tsr_cause', array() ), array( 'on' => false, 'kicker' => '', 'title' => '', 'text' => '', 'image' => '', 'links' => '' ) );
		echo '<div class="wrap"><h1>Кауза на сезона</h1><p>Карта в десния панел на всяко събитие (напр. „Една от 8“). Отделно събитие може да я скрие от своята страница.</p><form method="post">';
		wp_nonce_field( 'tsr_cause' );
		echo '<table class="form-table"><tr><th>Показвай</th><td><label><input type="checkbox" name="tsr_cause[on]" value="1"' . checked( (bool) $c['on'], true, false ) . '> да</label></td></tr>';
		echo '<tr><th><label for="tc-k">Надпис горе</label></th><td><input id="tc-k" class="regular-text" name="tsr_cause[kicker]" value="' . esc_attr( (string) $c['kicker'] ) . '" placeholder="🎗 Каузата на сезона"></td></tr>';
		echo '<tr><th><label for="tc-t">Заглавие</label></th><td><input id="tc-t" class="regular-text" name="tsr_cause[title]" value="' . esc_attr( (string) $c['title'] ) . '"></td></tr>';
		echo '<tr><th><label for="tc-x">Текст</label></th><td><textarea id="tc-x" class="large-text" rows="4" name="tsr_cause[text]">' . esc_textarea( (string) $c['text'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="tc-i">Снимка (линк)</label></th><td><input id="tc-i" class="large-text" name="tsr_cause[image]" value="' . esc_attr( (string) $c['image'] ) . '"></td></tr>';
		echo '<tr><th><label for="tc-l">Линкове</label></th><td><textarea id="tc-l" class="large-text" rows="3" name="tsr_cause[links]" placeholder="ednaot8.bg | https://www.ednaot8.bg/">' . esc_textarea( (string) $c['links'] ) . '</textarea><p class="description">По един ред: текст | линк</p></td></tr></table>';
		submit_button( 'Запази' );
		echo '</form></div>';
	} );
} );

// ── Track helpers shared by /traseta/ and the event page ─────────────────────

/** Tracks shown in dark red (the extra-long category colour). */
if ( ! defined( 'TSR_TRACK_XL_SLUGS' ) ) {
	define( 'TSR_TRACK_XL_SLUGS', array( '7-hills-run-hard-core-edition-26km' ) );
}

if ( ! function_exists( 'tsr_star_rating' ) ) {
	/**
	 * 1-5 star difficulty rating.
	 *
	 * @param int|null $stars Star count 1-5, or null when unknown.
	 * @return string HTML markup, '' when unknown.
	 */
	function tsr_star_rating( ?int $stars ): string {
		if ( null === $stars || $stars < 1 ) {
			return '';
		}
		$labels = array( 1 => 'лесно', 2 => 'умерено', 3 => 'средно', 4 => 'трудно', 5 => 'много трудно' );
		$stars  = min( $stars, 5 );
		$html   = '<span class="tsr-stars" role="img" aria-label="'
			. esc_attr( sprintf( 'Трудност: %d от 5 (%s)', $stars, $labels[ $stars ] ) ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$html .= '<span class="tsr-star' . ( $i <= $stars ? ' tsr-star--on' : '' ) . '" aria-hidden="true">&#9733;</span>';
		}
		return $html . '</span>';
	}
}

if ( ! function_exists( 'tsr_track_icon_defs' ) ) {
	/**
	 * Icon <symbol>s, rendered once per page. The modal's stat cells and
	 * buttons (js/traseta-modal.js) reference them via <use>.
	 */
	function tsr_track_icon_defs(): void {
		?>
		<svg width="0" height="0" style="position:absolute" aria-hidden="true">
			<symbol id="tsr-i-distance" viewBox="0 0 24 24"><circle cx="4" cy="12" r="2"/><circle cx="20" cy="12" r="2"/><path d="M6 12h12" stroke-dasharray="3 3"/></symbol>
			<symbol id="tsr-i-ascent" viewBox="0 0 24 24"><path d="M4 20 20 4M20 4H10M20 4v10"/></symbol>
			<symbol id="tsr-i-descent" viewBox="0 0 24 24"><path d="M4 4 20 20M20 20H10M20 20V10"/></symbol>
			<symbol id="tsr-i-elevation" viewBox="0 0 24 24"><path d="M3 20 9 8l4 6 2-3 6 9H3z"/></symbol>
			<symbol id="tsr-i-download" viewBox="0 0 24 24"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></symbol>
			<?php // Official Strava mark (Simple Icons, CC0) — brand guidelines ask for the real glyph when linking to Strava. ?>
			<?php // Garmin delta (triangle) mark; link glyph for "Копирай линк". ?>
			<symbol id="tsr-i-garmin" viewBox="0 0 24 24"><path d="M12 3 2.5 20h19L12 3zm0 5.2 5.6 9.8H6.4L12 8.2z"/></symbol>
			<symbol id="tsr-i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></symbol>
			<symbol id="tsr-i-strava" viewBox="0 0 24 24"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/></symbol>
		</svg>
		<?php
	}
}

if ( ! function_exists( 'tsr_track_nominal_km' ) ) {
	/** Nominal distance from the track name ("Middle 9 км" → 9.0), else the GPX distance. */
	function tsr_track_nominal_km( array $tr ): ?float {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*км/u', (string) ( $tr['variant'] ?? '' ), $m ) ) {
			return (float) str_replace( ',', '.', $m[1] );
		}
		return empty( $tr['distance_km'] ) ? null : (float) $tr['distance_km'];
	}
}

if ( ! function_exists( 'tsr_track_cat' ) ) {
	/**
	 * Distance category, same limits as /pravila/: under 8 km short,
	 * 8–13.9 km medium, 14 km and more long.
	 *
	 * @return string 'short'|'medium'|'long'|''
	 */
	function tsr_track_cat( ?float $km ): string {
		if ( null === $km ) {
			return '';
		}
		return $km < 8 ? 'short' : ( $km < 14 ? 'medium' : 'long' );
	}
}

if ( ! function_exists( 'tsr_track_profiles' ) ) {
	/**
	 * Elevation samples (48 per track, evenly spaced by distance) for the row
	 * sparklines, keyed by points file. Cached until tracks.json changes.
	 *
	 * @return array<string, list<int>>
	 */
	function tsr_track_profiles( array $events ): array {
		$dir = get_stylesheet_directory() . '/data/';
		$key = 'tsr_track_prof_' . (int) @filemtime( $dir . 'tracks.json' );
		$out = get_transient( $key );
		if ( is_array( $out ) ) {
			return $out;
		}
		$out = array();
		$n   = 48;
		foreach ( $events as $event ) {
			foreach ( (array) ( $event['tracks'] ?? array() ) as $tr ) {
				$file = (string) ( $tr['points_file'] ?? '' );
				if ( '' === $file || isset( $out[ $file ] ) || ! is_readable( $dir . 'tracks/' . $file ) ) {
					continue;
				}
				$pts = json_decode( (string) file_get_contents( $dir . 'tracks/' . $file ), true )['pts'] ?? array();
				if ( ! is_array( $pts ) || count( $pts ) < 2 ) {
					continue;
				}
				$total = (float) end( $pts )[3];
				if ( $total <= 0 ) {
					continue;
				}
				$last    = count( $pts ) - 1;
				$samples = array();
				for ( $i = 0, $j = 0; $i < $n; $i++ ) {
					$d = $total * $i / ( $n - 1 );
					while ( $j < $last && $pts[ $j + 1 ][3] < $d ) {
						++$j;
					}
					$samples[] = (int) round( (float) $pts[ $j ][2] );
				}
				$out[ $file ] = $samples;
			}
		}
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}
}

if ( ! function_exists( 'tsr_track_result_stats' ) ) {
	/**
	 * Results per current track, for the modal's "Колко време отнема" and
	 * "Финишири по години": each published result table goes to the current
	 * track of the same race whose nominal distance is nearest (within 1 km).
	 * Percentiles, not records — a few wrong rows (a shortened course, a
	 * mistyped time) barely move them. Cached per results generation.
	 *
	 * @return array<string, array{n: int, p10: int, p50: int, p90: int, years: array<string, array{0: int, 1: int, 2: int}>, results: string}>
	 */
	function tsr_track_result_stats( array $events ): array {
		$key = 'tsr_track_stats_g' . tsr_cache_gen() . '_' . (int) @filemtime( get_stylesheet_directory() . '/data/tracks.json' );
		$out = get_transient( $key );
		if ( is_array( $out ) ) {
			return $out;
		}
		$tracks = array(); // race => list of [slug, nominal km]
		foreach ( $events as $event ) {
			foreach ( (array) $event['tracks'] as $tr ) {
				$km = tsr_track_nominal_km( $tr );
				if ( null !== $km && 'legacy' !== tsr_track_status( $tr ) ) {
					$tracks[ (string) $event['name'] ][] = array( (string) $tr['slug'], $km );
				}
			}
		}
		$assign = array(); // post id => [slug, year, gender, event base]
		foreach ( tsr_results_index() as $id => $e ) {
			$race = tsr_runner_event_name( $e['event_base'] );
			$km   = (float) $e['km'];
			if ( $km <= 0 || empty( $tracks[ $race ] ) ) {
				continue;
			}
			$best = null;
			foreach ( $tracks[ $race ] as [ $slug, $tkm ] ) {
				$diff = abs( $tkm - $km );
				if ( $diff <= 1.0 && ( null === $best || $diff < $best[1] ) ) {
					$best = array( $slug, $diff );
				}
			}
			if ( null !== $best ) {
				$assign[ (int) $id ] = array( $best[0], (string) $e['season'], (string) $e['gender'], (string) $e['event_base'] );
			}
		}
		update_meta_cache( 'post', array_keys( $assign ) );
		$times  = array();
		$latest = array(); // slug => [year, event base]
		$out    = array();
		foreach ( $assign as $id => [ $slug, $year, $gender, $base ] ) {
			$set = json_decode( (string) get_post_meta( $id, '_tsr_result_set', true ), true );
			foreach ( (array) ( $set['rows'] ?? array() ) as $r ) {
				$s = tsr_runner_seconds( (string) ( $r['finish_time'] ?? '' ) );
				if ( null === $s || $s < 300 ) {
					continue;
				}
				$g = 'M' === $gender ? 0 : ( 'F' === $gender ? 1 : 2 );
				$out[ $slug ]['years'][ $year ][ $g ] = ( $out[ $slug ]['years'][ $year ][ $g ] ?? 0 ) + 1;
				$times[ $slug ][] = $s;
			}
			if ( (int) $year > ( $latest[ $slug ][0] ?? 0 ) ) {
				$latest[ $slug ] = array( (int) $year, $base );
			}
		}
		foreach ( $times as $slug => $list ) {
			sort( $list );
			$n = count( $list );
			ksort( $out[ $slug ]['years'] );
			foreach ( $out[ $slug ]['years'] as &$counts ) {
				$counts = array( $counts[0] ?? 0, $counts[1] ?? 0, $counts[2] ?? 0 );
			}
			unset( $counts );
			$out[ $slug ] += array(
				'n'       => $n,
				'p10'     => $list[ intdiv( $n, 10 ) ],
				'p50'     => $list[ intdiv( $n, 2 ) ],
				'p90'     => $list[ min( $n - 1, intdiv( $n * 9, 10 ) ) ],
				'results' => isset( $latest[ $slug ] ) ? tsr_event_results_url( $latest[ $slug ][1], $latest[ $slug ][0] ) : '',
				'year'    => $latest[ $slug ][0] ?? 0,
			);
		}
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}
}

/**
 * First season whose results count towards a track's records: the admin value
 * (Tools → Трасета — етикети, option 'tsr_track_records_from'), else the
 * track's own "year" in tracks.json, else 0 (all seasons).
 *
 * @param array<string, mixed> $track Track entry from tracks.json.
 */
function tsr_track_records_from( array $track ): int {
	static $years = null;
	if ( null === $years ) {
		$years = (array) get_option( 'tsr_track_records_from', array() );
	}
	$y = (int) ( $years[ $track['slug'] ?? '' ] ?? 0 );
	return $y > 0 ? $y : (int) ( $track['year'] ?? 0 );
}

/**
 * Course records (KOM / QOM) of every current track, computed from results.
 *
 * A results table counts for the current track of the same race whose
 * nominal distance is within 1 km of the table's, from the track's
 * records-from season on. Results data has broken tables (shortened
 * courses, wrong columns), so three filters keep impossible times out —
 * all on "effort pace": seconds per (km + D+ / 100), the same effort
 * measure as the difficulty stars:
 *   1. a whole table is dropped when its median effort pace is more than
 *      20 % faster than the median of the track's other tables (same gender);
 *   2. single times faster than 150 s per effort-km are dropped;
 *   3. a leader more than 15 % faster than the next time is dropped.
 * Dropped tables and times are kept in 'dropped' for the admin page.
 *
 * @return array{tracks: array<string, array<string, array{s: int, name: string, year: int}>>, dropped: list<array{id: int, year: int, slug: string, gender: string, s: int, name: string, why: string}>}
 */
function tsr_track_records(): array {
	$from_opt = (array) get_option( 'tsr_track_records_from', array() );
	$key      = 'tsr_track_rec_g' . tsr_cache_gen() . '_' . (int) @filemtime( get_stylesheet_directory() . '/data/tracks.json' ) . '_' . md5( (string) wp_json_encode( array( $from_opt, get_option( 'tsr_track_status', array() ) ) ) );
	$out      = get_transient( $key );
	if ( is_array( $out ) ) {
		return $out;
	}
	$tracks = array(); // race => list of [track, nominal km]
	foreach ( tsr_tracks_events() as $event ) {
		foreach ( (array) $event['tracks'] as $tr ) {
			$km = tsr_track_nominal_km( $tr );
			if ( null !== $km && 'current' === tsr_track_status( $tr ) && ! empty( $tr['distance_km'] ) ) {
				$tracks[ tsr_runner_event_name( (string) $event['name'] ) ][] = array( $tr, $km );
			}
		}
	}
	$tables = array(); // slug|gender => list of [id, year, rows [s, name], median effort pace]
	foreach ( tsr_results_index() as $id => $e ) {
		$gender = (string) $e['gender'];
		$race   = tsr_runner_event_name( (string) $e['event_base'] );
		$km     = (float) $e['km'];
		if ( ( 'M' !== $gender && 'F' !== $gender ) || $km <= 0 || empty( $tracks[ $race ] ) ) {
			continue;
		}
		$pick = null;
		foreach ( $tracks[ $race ] as [ $tr, $tkm ] ) {
			$diff = abs( $tkm - $km );
			if ( $diff <= 1.0 && ( null === $pick || $diff < $pick[1] ) ) {
				$pick = array( $tr, $diff );
			}
		}
		if ( null === $pick || (int) $e['season'] < tsr_track_records_from( $pick[0] ) ) {
			continue;
		}
		$tr     = $pick[0];
		$effort = (float) $tr['distance_km'] + (int) ( $tr['ascent_m'] ?? 0 ) / 100;
		$set    = json_decode( (string) get_post_meta( (int) $id, '_tsr_result_set', true ), true );
		$rows   = array();
		foreach ( (array) ( $set['rows'] ?? array() ) as $r ) {
			$s = tsr_runner_seconds( (string) ( $r['finish_time'] ?? '' ) );
			if ( null !== $s && $s >= 300 ) {
				$rows[] = array( $s, trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ) );
			}
		}
		if ( ! $rows ) {
			continue;
		}
		$secs = array_column( $rows, 0 );
		sort( $secs );
		$tables[ $tr['slug'] . '|' . $gender ][] = array( (int) $id, (int) $e['season'], $rows, $secs[ intdiv( count( $secs ), 2 ) ] / $effort, $effort );
	}
	$out = array( 'tracks' => array(), 'dropped' => array() );
	foreach ( $tables as $k => $list ) {
		[ $slug, $gender ] = explode( '|', $k );
		$cands = array();
		foreach ( $list as $i => [ $id, $year, $rows, $med, $effort ] ) {
			$others = array();
			foreach ( $list as $j => $t ) {
				if ( $j !== $i ) {
					$others[] = $t[3];
				}
			}
			sort( $others );
			if ( $others && $med < 0.8 * $others[ intdiv( count( $others ), 2 ) ] ) {
				$best = min( array_column( $rows, 0 ) );
				$out['dropped'][] = array( 'id' => $id, 'year' => $year, 'slug' => $slug, 'gender' => $gender, 's' => $best, 'name' => '', 'why' => 'table' );
				continue;
			}
			foreach ( $rows as [ $s, $name ] ) {
				if ( $s / $effort < 150 ) {
					$out['dropped'][] = array( 'id' => $id, 'year' => $year, 'slug' => $slug, 'gender' => $gender, 's' => $s, 'name' => $name, 'why' => 'pace' );
				} else {
					$cands[] = array( $s, $name, $year, $id );
				}
			}
		}
		usort( $cands, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		while ( count( $cands ) > 1 && $cands[0][0] < 0.85 * $cands[1][0] ) {
			$x = array_shift( $cands );
			$out['dropped'][] = array( 'id' => $x[3], 'year' => $x[2], 'slug' => $slug, 'gender' => $gender, 's' => $x[0], 'name' => $x[1], 'why' => 'gap' );
		}
		if ( $cands ) {
			$out['tracks'][ $slug ][ $gender ] = array( 's' => $cands[0][0], 'name' => tsr_runner_canonical( $cands[0][1] ), 'year' => $cands[0][2] );
		}
	}
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

if ( ! function_exists( 'tsr_track_spark' ) ) {
	/** Small elevation profile (SVG, stroke and fill from currentColor). */
	function tsr_track_spark( array $p ): string {
		if ( count( $p ) < 2 ) {
			return '';
		}
		$lo  = min( $p );
		$rng = max( max( $p ) - $lo, 60 ); // flat courses stay flat-looking
		$w   = 300;
		$h   = 30;
		$d   = '';
		foreach ( $p as $i => $v ) {
			$d .= ( $i ? 'L' : 'M' ) . round( $i * $w / ( count( $p ) - 1 ), 1 ) . ' ' . round( 3 + ( $h - 6 ) * ( 1 - ( $v - $lo ) / $rng ), 1 );
		}
		return '<svg class="tsr-trk__spark" viewBox="0 0 300 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
			. '<path d="' . $d . 'L300 30L0 30Z" fill="currentColor" opacity=".15"/>'
			. '<path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>';
	}
}

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
		$posted_garmin = (array) wp_unslash( $_POST['tsr_track_garmin'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by tsr_garmin_url_clean().
		$posted_strava = (array) wp_unslash( $_POST['tsr_track_strava'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by tsr_strava_url_clean().
		$posted_from   = (array) wp_unslash( $_POST['tsr_track_records_from'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to int and range-checked below.
		$clean_from    = array();
		$clean_strava  = array();
		$bad_strava    = array();
		$clean_status  = array();
		$clean_laps    = array();
		$clean_garmin  = array();
		$bad_garmin    = array();
		foreach ( $events as $event ) {
			foreach ( $event['tracks'] as $track ) {
				$slug = $track['slug'];

				$status = isset( $posted_status[ $slug ] ) && 'legacy' === $posted_status[ $slug ] ? 'legacy' : 'current';
				$clean_status[ $slug ] = $status;

				// Records from season: stored only when it differs from tracks.json's year.
				$from = (int) ( $posted_from[ $slug ] ?? 0 );
				if ( $from >= 2010 && $from <= 2100 && $from !== (int) ( $track['year'] ?? 0 ) ) {
					$clean_from[ $slug ] = $from;
				}

				$laps = isset( $posted_laps[ $slug ] ) ? (int) $posted_laps[ $slug ] : 1;
				$clean_laps[ $slug ] = ( $laps >= 1 && $laps <= 3 ) ? $laps : 1;

				// Strava: store only what differs from tracks.json; an emptied
				// field over a JSON link means "hide it" ('-').
				$raw  = trim( (string) ( $posted_strava[ $slug ] ?? '' ) );
				$json = (string) ( $track['strava_route_url'] ?? '' );
				if ( '' === $raw ) {
					if ( '' !== $json ) {
						$clean_strava[ $slug ] = '-';
					}
				} elseif ( $raw !== $json ) {
					$url = tsr_strava_url_clean( $raw );
					if ( '' !== $url ) {
						$clean_strava[ $slug ] = $url;
					} else {
						$bad_strava[] = $track['title'];
					}
				}

				$raw = trim( (string) ( $posted_garmin[ $slug ] ?? '' ) );
				if ( '' !== $raw ) {
					$url = tsr_garmin_url_clean( $raw );
					if ( '' !== $url ) {
						$clean_garmin[ $slug ] = $url;
					} else {
						$bad_garmin[] = $track['title'];
					}
				}
			}
		}
		update_option( 'tsr_track_garmin', $clean_garmin, false );
		update_option( 'tsr_track_strava', $clean_strava, false );
		update_option( 'tsr_track_status', $clean_status, false );
		update_option( 'tsr_track_laps', $clean_laps, false );
		update_option( 'tsr_track_records_from', $clean_from, false );
		echo '<div class="notice notice-success is-dismissible"><p>Етикетите са запазени.</p></div>';
		if ( $bad_strava ) {
			echo '<div class="notice notice-warning"><p>Тези Strava линкове не са запазени — трябва да са от strava.com (https): '
				. esc_html( implode( ', ', $bad_strava ) ) . '</p></div>';
		}
		if ( $bad_garmin ) {
			echo '<div class="notice notice-warning"><p>Тези Garmin линкове не са запазени — трябва да са от connect.garmin.com (https): '
				. esc_html( implode( ', ', $bad_garmin ) ) . '</p></div>';
		}
	}

	echo '<div class="wrap"><h1>Трасета — етикети (Актуално / Легаси)</h1>';

	if ( empty( $events ) ) {
		echo '<p>data/tracks.json не е намерен или е празен.</p></div>';
		return;
	}

	echo '<p>Етикетът определя подредбата на страницата „Трасета“: актуалните трасета са отгоре, легаси версиите — в свито поле отдолу. Броят обиколки се показва като значка до дистанцията, когато е повече от 1. Strava / Garmin линк: отвори трасето в Strava (Routes) или Garmin Connect (Courses), натисни „Сподели“ и постави линка тук — в прозореца на трасето се появява бутон „Strava“ / „Garmin“. Празно поле = без бутон. „Рекорди от“: от коя година трасето е същото — рекордите (KOM / QOM) на страницата на събитието се смятат само от тази година нататък. Празно = всички години.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'tsr_track_labels' );

	foreach ( $events as $event ) {
		echo '<h2 style="margin:1.5em 0 0.4em">' . esc_html( $event['name'] ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:1400px">';
		echo '<thead><tr><th>Трасе</th><th style="width:110px">Дистанция</th><th style="width:180px">Етикет</th><th style="width:150px">Брой обиколки</th><th style="width:110px">Рекорди от</th><th>Strava линк</th><th>Garmin линк</th></tr></thead><tbody>';
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
			echo '</select></td>';
			$from = (int) ( ( (array) get_option( 'tsr_track_records_from', array() ) )[ $track['slug'] ] ?? 0 );
			$from = $from > 0 ? $from : (int) ( $track['year'] ?? 0 );
			echo '<td><input type="number" min="2010" max="2100" step="1" style="width:90px" placeholder="всички" name="tsr_track_records_from[' . esc_attr( $track['slug'] ) . ']" value="' . esc_attr( $from > 0 ? (string) $from : '' ) . '"></td>';
			echo '<td><input type="url" class="regular-text" style="width:100%" placeholder="https://www.strava.com/routes/…" name="tsr_track_strava[' . esc_attr( $track['slug'] ) . ']" value="' . esc_attr( tsr_track_strava( $track ) ) . '"></td>';
			echo '<td><input type="url" class="regular-text" style="width:100%" placeholder="https://connect.garmin.com/modern/course/…" name="tsr_track_garmin[' . esc_attr( $track['slug'] ) . ']" value="' . esc_attr( tsr_track_garmin( $track ) ) . '"></td></tr>';
		}
		echo '</tbody></table>';
	}

	submit_button( 'Запази етикетите' );
	echo '</form>';

	// Times the records filter left out (tsr_track_records()): broken tables
	// to fix at the source, or a genuine record to check.
	$rec = tsr_track_records();
	if ( ! empty( $rec['dropped'] ) ) {
		$names = array();
		foreach ( $events as $event ) {
			foreach ( $event['tracks'] as $track ) {
				$names[ $track['slug'] ] = $event['name'] . ' ' . $track['variant'];
			}
		}
		$why = array(
			'table' => 'цялото класиране е много по-бързо от другите години',
			'pace'  => 'физически невъзможно темпо',
			'gap'   => 'много по-бързо от второто време — провери',
		);
		$seen = array();
		echo '<h2 style="margin-top:2em">Времена, скрити от рекордите</h2>';
		echo '<p>Рекордите се смятат автоматично. Тези класирания / времена изглеждат грешни и не се показват. Поправи класирането (линк) — рекордът се обновява сам.</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Трасе</th><th>Година</th><th>Пол</th><th>Време</th><th>Бегач</th><th>Защо</th></tr></thead><tbody>';
		foreach ( $rec['dropped'] as $d ) {
			if ( 'table' === $d['why'] ) {
				if ( isset( $seen[ $d['id'] ] ) ) {
					continue;
				}
				$seen[ $d['id'] ] = true;
			}
			echo '<tr><td>' . esc_html( $names[ $d['slug'] ] ?? $d['slug'] ) . '</td><td><a href="' . esc_url( (string) get_permalink( $d['id'] ) ) . '">' . esc_html( (string) $d['year'] ) . '</a></td>';
			echo '<td>' . ( 'M' === $d['gender'] ? 'Мъже' : 'Жени' ) . '</td><td>' . esc_html( tsr_runner_hms( $d['s'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $d['name'] ? $d['name'] : '—' ) . '</td><td>' . esc_html( $why[ $d['why'] ] ?? $d['why'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
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

	// Finishes the series counts that are not in the imported results (races
	// without a results page). Owner's total on 2026-11-14: 19 299, against
	// 17 863 in the data → 1 436. New results add to the total on their own.
	$total += (int) get_option( 'tsr_finishers_offset', 1436 );

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
 * Small per-post index of every published ts_result: the scalar meta the
 * listing pages need, WITHOUT the `_tsr_result_set` blobs (audit H3).
 *
 * Reading any meta key of a post loads all of that post's meta, blob
 * included (≈2.8 MB across the corpus), which is what made /rezultati/,
 * /klasiraniya/ and /runner/ heavy. One SQL query, cached per results cache
 * generation (any results write makes a fresh key), plus a static copy per
 * request.
 *
 * @return array<int, array{slug: string, season: string, event_base: string, km: string, gender: string, hash: string}>
 */
function tsr_results_index(): array {
	static $index = null;
	if ( null !== $index ) {
		return $index;
	}
	$key    = 'tsr_index2_g' . tsr_cache_gen(); // v2: + series season (audit H1)
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		$index = $cached;
		return $index;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows  = $wpdb->get_results(
		"SELECT p.ID, p.post_name,
		        MAX( CASE WHEN m.meta_key = '_tsr_season'       THEN m.meta_value END ) AS season,
		        MAX( CASE WHEN m.meta_key = '_tsr_event_base'   THEN m.meta_value END ) AS event_base,
		        MAX( CASE WHEN m.meta_key = '_tsr_distance_km'  THEN m.meta_value END ) AS km,
		        MAX( CASE WHEN m.meta_key = '_tsr_gender'       THEN m.meta_value END ) AS gender,
		        MAX( CASE WHEN m.meta_key = '_tsr_names_sha256' THEN m.meta_value END ) AS hash,
		        MAX( CASE WHEN m.meta_key = '_tsr_series_season' THEN m.meta_value END ) AS series
		   FROM {$wpdb->posts} p
		   LEFT JOIN {$wpdb->postmeta} m
		          ON m.post_id = p.ID
		         AND m.meta_key IN ( '_tsr_season', '_tsr_event_base', '_tsr_distance_km', '_tsr_gender', '_tsr_names_sha256', '_tsr_series_season' )
		  WHERE p.post_type = 'ts_result' AND p.post_status = 'publish'
		  GROUP BY p.ID, p.post_name"
	);
	$index = array();
	foreach ( $rows as $row ) {
		$index[ (int) $row->ID ] = array(
			'slug'       => (string) $row->post_name,
			'season'     => (string) $row->season,
			'event_base' => (string) $row->event_base,
			'km'         => (string) $row->km,
			'gender'     => (string) $row->gender,
			'hash'       => (string) $row->hash,
			// Season the race counts for (audit H1): the stored series season
			// for 2012–2020 races, the race year from 2021 on; '' = none
			// (not a series race, or no year at all).
			'series'     => (string) ( TSR_Series_Seasons::season_key( (string) $row->season, (string) $row->series ) ?? '' ),
		);
	}
	set_transient( $key, $index, 12 * HOUR_IN_SECONDS );
	return $index;
}

/**
 * tsr_sort_results_by_distance_gender() (ADR-004) for posts that are in
 * tsr_results_index(): same comparator (via the scalar variant), but km and
 * gender come from the index instead of one meta load per post — without
 * meta priming, each of those loads also pulls the post's results blob.
 * Gender resolves exactly like tsr_race_gender(): _tsr_gender M/F wins,
 * 'mixed' is undetermined, otherwise the slug/title rules.
 *
 * @param list<WP_Post> $posts
 * @return list<WP_Post>
 */
function tsr_sort_results_indexed( array $posts ): array {
	$index = tsr_results_index();
	$items = array();
	foreach ( $posts as $p ) {
		$entry  = $index[ $p->ID ] ?? array( 'km' => '', 'gender' => '' );
		$gender = in_array( $entry['gender'], array( 'M', 'F' ), true )
			? $entry['gender']
			: ( 'mixed' === $entry['gender'] ? '' : tsr_race_gender_from_text( $p ) );
		$items[] = array( 'km' => (float) $entry['km'], 'gender' => $gender, 'post' => $p );
	}
	return array_column( tsr_sort_by_distance_gender_scalars( $items ), 'post' );
}

/**
 * Runner-name index for /runner/ (audit H3): post ID → every row's
 * "first last" and "last first", lowercased, one per line. A search tests
 * this string first and decodes only the posts that can match; the exact
 * per-row matching in page-runner.php is unchanged. Lines are separated by
 * "\n", which sanitize_text_field() strips from the query, so a substring
 * hit is always within one row's name.
 *
 * Built once per results cache generation, reading the blobs in batches of
 * 100 so the build itself never holds the whole corpus in memory.
 *
 * @return array<int, string>
 */
/**
 * Canonical full name of a runner: the owner-confirmed alias target, or the
 * name itself (audit M2; map managed with `wp tsr alias`).
 */
function tsr_runner_canonical( string $full_name ): string {
	$map = tsr_runner_alias_map();
	return array() === $map ? $full_name : TSR_Runner_Alias::canonical( $full_name, $map );
}

/**
 * Spelling → canonical name: the automatic Cyrillic / Latin merges
 * (`wp tsr alias-auto`), overridden by the owner's aliases (`wp tsr alias`).
 *
 * @return array<string, string>
 */
function tsr_runner_alias_map(): array {
	static $map = null;
	if ( null === $map ) {
		$map = TSR_Runner_Merge::effective(
			(array) get_option( TSR_Runner_Alias::OPTION, array() ),
			(array) get_option( TSR_Runner_Merge::OPTION, array() )
		);
	}
	return $map;
}

/**
 * Every spelling a canonical name is known under, lower case, in both word
 * orders ("гергана ангелова", "ангелова гергана") — so a search typed in
 * the other script still finds the person. Empty for unmerged names.
 *
 * @return list<string>
 */
function tsr_runner_spellings( string $canonical ): array {
	static $by = null;
	if ( null === $by ) {
		$by = array();
		foreach ( tsr_runner_alias_map() as $key => $name ) {
			$key = (string) $key;
			$by[ $name ][ $key ] = true;
			$by[ $name ][ implode( ' ', array_reverse( explode( ' ', $key ) ) ) ] = true;
		}
	}
	return isset( $by[ $canonical ] ) ? array_map( 'strval', array_keys( $by[ $canonical ] ) ) : array();
}

function tsr_runner_name_index(): array {
	$key    = 'tsr_runner_names3_g' . tsr_cache_gen(); // v3: + every spelling of a merged runner
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	global $wpdb;
	$ids   = array_keys( tsr_results_index() );
	$index = array();
	foreach ( array_chunk( $ids, 100 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blobs = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tsr_result_set' AND post_id IN ( {$in} )" );
		foreach ( $blobs as $blob ) {
			$data = json_decode( (string) $blob->meta_value, true );
			if ( ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
				continue;
			}
			$lines = array();
			foreach ( $data['rows'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$fn      = (string) ( $row['first_name'] ?? '' );
				$ln      = (string) ( $row['last_name'] ?? '' );
				$lines[] = mb_strtolower( trim( $fn . ' ' . $ln ) );
				$lines[] = mb_strtolower( trim( $ln . ' ' . $fn ) );
				$canon   = tsr_runner_canonical( trim( $fn . ' ' . $ln ) );
				if ( trim( $fn . ' ' . $ln ) !== $canon ) {
					$lines[] = mb_strtolower( $canon ); // searchable under the canonical name
				}
				foreach ( tsr_runner_spellings( $canon ) as $spelling ) {
					$lines[] = $spelling; // …and under the person's other spellings
				}
			}
			$index[ (int) $blob->post_id ] = implode( "\n", $lines );
		}
	}
	set_transient( $key, $index, 12 * HOUR_IN_SECONDS );
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
			? sprintf( 'Ранкинг Сезон %d — точки по сезон, мъже и жени.', $season )
			: 'Ранкинг по сезони — точки, мъже и жени.';
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

// Runner profile and search (/runner/).
require_once get_stylesheet_directory() . '/inc/runner.php';
