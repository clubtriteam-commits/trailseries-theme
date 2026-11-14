<?php
declare( strict_types=1 );
/**
 * Homepage template — TrailSeries.bg.
 *
 * Sections (in order):
 *   1. Hero with countdown to the 15th anniversary (1 October 2027)
 *   2. Upcoming events — next 3 from EventON plugin (post type ajde_events)
 *   3. Past events   — all ts_result posts belonging to the last 1 PAST
 *                      calendar event (ajde_events with evcal_srow < now),
 *                      matched via _tsr_event_base + _tsr_season — not a
 *                      flat "last N published" query, since import order
 *                      isn't race order
 *   4. Latest news   — last 3 standard WP posts
 *   5. Zero to HERO  — story cards + CSS slideshow
 *   6. Map           — Leaflet map of the tracks
 *   7. Партньори     — partner logo strip linking to /partniori/
 *   8. Quick stats   — seasons (hardcoded 14), total finishers, total races
 *
 * Everything here is display-only. No results logic lives in the theme.
 *
 * @package exhibz-child
 */

// ── Data collection (before any HTML output) ──────────────────────────────────

// 1. Upcoming events via EventON (graceful fallback when plugin inactive).
$tsr_upcoming = array();
if ( post_type_exists( 'ajde_events' ) ) {
	$tsr_eq = new WP_Query(
		array(
			'post_type'      => 'ajde_events',
			'posts_per_page' => 3,
			'post_status'    => 'publish',
			'meta_key'       => 'evcal_srow',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => 'evcal_srow',
					'value'   => time(),
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
			'no_found_rows'  => true,
		)
	);
	while ( $tsr_eq->have_posts() ) {
		$tsr_eq->the_post();
		$tsr_id         = get_the_ID();
		$tsr_upcoming[] = array(
			'title'         => get_the_title(),
			'url'           => get_permalink(),
			'start_ts'      => (int) get_post_meta( $tsr_id, 'evcal_srow', true ),
			'time_ts'       => (int) get_post_meta( $tsr_id, '_evcal_etime', true ),
			'location'      => (string) ( get_post_meta( $tsr_id, 'evcal_location_raw', true ) ?: '' ),
			'image'         => tsr_card_image( $tsr_id, 'tsr-event-card__img', '(max-width: 768px) calc(100vw - 2.5rem), 380px' ),
		);
	}
	wp_reset_postdata();
}

// 2. All ts_result posts for the last 1 PAST calendar event.
//
// "Recent results" means results from races that have actually happened,
// per the calendar — not "the 5 most recently published ts_result posts"
// (that order tracks import/edit time, not race date, and could easily show
// a 2019 result re-imported yesterday ahead of last week's real race).
// Matched via _tsr_event_base + _tsr_season, same fields page-event.php
// uses; cached like it (functions.php tsr_cache_gen()) since this scans
// every ts_result post per target event when the cache is cold.
//
// One event, not two: two events' worth of categories (up to a dozen rows)
// crowded this teaser section — see commit history if that's ever revisited.
$tsr_past_results = array();
$tsr_past_key      = 'tsr_home_past_results_g' . tsr_cache_gen();
$tsr_past_cached   = get_transient( $tsr_past_key );

if ( is_array( $tsr_past_cached ) ) {
	$tsr_past_results = array_values( array_filter( array_map( 'get_post', $tsr_past_cached ) ) );
} elseif ( post_type_exists( 'ajde_events' ) ) {
	$tsr_past_events_q = new WP_Query(
		array(
			'post_type'      => 'ajde_events',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_key'       => 'evcal_srow',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => 'evcal_srow',
					'value'   => time(),
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
			'no_found_rows'  => true,
		)
	);

	while ( $tsr_past_events_q->have_posts() ) {
		$tsr_past_events_q->the_post();
		// EventON stores the title with the apostrophe HTML-entity-encoded
		// ("Run&#8217;26"), unlike ts_result post_titles — decode first or
		// tsr_event_base_name()'s apostrophe-year regex never matches and
		// the whole event silently yields zero results.
		$tsr_ev_title = html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' );
		$tsr_ev_base  = tsr_event_base_name( $tsr_ev_title );
		if ( '' === $tsr_ev_base ) {
			continue;
		}
		$tsr_ev_year = tsr_title_year( $tsr_ev_title )
			?? (int) date_i18n( 'Y', (int) get_post_meta( get_the_ID(), 'evcal_srow', true ) );

		$tsr_matches = get_posts(
			array(
				'post_type'   => 'ts_result',
				'numberposts' => -1,
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
				'meta_query'  => array(
					array(
						'key'   => '_tsr_event_base',
						'value' => $tsr_ev_base,
					),
					array(
						'key'   => '_tsr_season',
						'value' => (string) $tsr_ev_year,
					),
				),
			)
		);
		$tsr_past_results = array_merge( $tsr_past_results, $tsr_matches );
	}
	wp_reset_postdata();

	// Site-wide ordering rule (ADR-004): longest distance first, men before
	// women within a distance.
	$tsr_past_results = tsr_sort_results_by_distance_gender( $tsr_past_results );

	set_transient( $tsr_past_key, wp_list_pluck( $tsr_past_results, 'ID' ), 12 * HOUR_IN_SECONDS );
}

// 3. Last 3 news posts — exclude Zero to HERO category.
$tsr_zero_cat    = get_category_by_slug( 'zero-to-hero' );
$tsr_zero_cat_id = $tsr_zero_cat ? (int) $tsr_zero_cat->term_id : 0;

$tsr_news = get_posts(
	array(
		'post_type'        => 'post',
		'numberposts'      => 3,
		'post_status'      => 'publish',
		'orderby'          => 'date',
		'order'            => 'DESC',
		'category__not_in' => $tsr_zero_cat_id ? array( $tsr_zero_cat_id ) : array(),
	)
);

// 4. Zero to HERO stories — all posts from the "zero-to-hero" category.
$tsr_zero = get_posts(
	array(
		'post_type'     => 'post',
		'numberposts'   => -1,
		'post_status'   => 'publish',
		'category_name' => 'zero-to-hero',
		'orderby'       => 'date',
		'order'         => 'DESC',
		'no_found_rows' => true,
	)
);

// 5. Partners — same source as page-partniori.php (ts_partner CPT).
$tsr_partners = get_posts(
	array(
		'post_type'   => 'ts_partner',
		'numberposts' => -1,
		'post_status' => 'publish',
		'orderby'     => array(
			'menu_order' => 'ASC',
			'title'      => 'ASC',
		),
	)
);

// 6. Stats.
$tsr_total_races     = tsr_homepage_total_races();
$tsr_total_finishers = tsr_homepage_total_finishers();

// 7. Map pins — one per event from data/tracks.json, positioned at the
// MEDIAN of the event's current tracks' GPX start points. Median, not
// first-file: one mislabeled GPX (pancharevo_night_run_19km starts at the
// 7 Hills trailhead, 23 km away) must not drag the pin. The previous pins
// were hand-guessed mountain coordinates, off by 3.5-43 km. Events whose
// tracks have no GPX start data get no pin — no guessed coordinates.
// Months / "последно издание" notes are curated display metadata that
// tracks.json does not carry; events absent from this array simply render
// without that popup line.
$tsr_map_meta = array(
	'Golyam Sechko Run'    => array( 'month' => 'Януари' ),
	'Malak Sechko Run'     => array( 'month' => 'Февруари' ),
	'Baba Marta Run'       => array( 'month' => 'Март' ),
	'Lyulin Trail Run'     => array( 'month' => 'Май' ),
	'7 Hills Run'          => array( 'month' => 'Септември' ),
	'Buhovo Half Marathon' => array( 'month' => 'Октомври' ),
	'The Cactus Run'       => array( 'month' => 'Ноември' ),
	'The Christmas Run'    => array( 'month' => 'Декември' ),
	'Simeonovo Run'        => array( 'note' => 'последно издание 2023' ),
	'Birthday Run'         => array( 'note' => 'последно издание 2024' ),
	'Pancharevo Night Run' => array( 'note' => 'последно издание 2021' ),
	'iRan Run'             => array( 'note' => 'последно издание 2019' ),
);

$tsr_median = static function ( array $values ): float {
	sort( $values );
	$n   = count( $values );
	$mid = intdiv( $n, 2 );
	return 1 === $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
};

$tsr_map_pins = array();
foreach ( tsr_tracks_events() as $tsr_map_ev ) {
	// Pin position/distances follow the CURRENT course versions (admin can
	// re-label via Tools → Трасета — етикети); an all-legacy event still
	// gets a pin from its legacy tracks.
	$tsr_map_tracks = array_filter(
		$tsr_map_ev['tracks'],
		static fn( array $t ): bool => 'current' === tsr_track_status( $t )
	);
	if ( empty( $tsr_map_tracks ) ) {
		$tsr_map_tracks = $tsr_map_ev['tracks'];
	}

	$tsr_map_lats  = array();
	$tsr_map_lngs  = array();
	$tsr_map_dists = array();
	foreach ( $tsr_map_tracks as $tsr_map_t ) {
		if ( isset( $tsr_map_t['start_lat'], $tsr_map_t['start_lng'] ) ) {
			$tsr_map_lats[] = (float) $tsr_map_t['start_lat'];
			$tsr_map_lngs[] = (float) $tsr_map_t['start_lng'];
		}
		if ( ! empty( $tsr_map_t['distance_km'] ) ) {
			$tsr_map_dists[] = (int) round( (float) $tsr_map_t['distance_km'] );
		}
	}
	if ( empty( $tsr_map_lats ) ) {
		continue;
	}

	$tsr_map_dists = array_values( array_unique( $tsr_map_dists ) );
	sort( $tsr_map_dists );
	$tsr_map_pin_meta = $tsr_map_meta[ $tsr_map_ev['name'] ] ?? array();

	$tsr_map_pins[] = array(
		'lat'   => round( $tsr_median( $tsr_map_lats ), 5 ),
		'lng'   => round( $tsr_median( $tsr_map_lngs ), 5 ),
		'name'  => $tsr_map_ev['name'],
		'dist'  => implode( ' / ', $tsr_map_dists ) . ' км',
		'month' => $tsr_map_pin_meta['month'] ?? '',
		'note'  => $tsr_map_pin_meta['note'] ?? '',
	);
}

get_header();
?>

<main id="main">

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 1 — HERO + COUNTDOWN
     ════════════════════════════════════════════════════════════════════════ -->
<?php
// Hero photo (owner-approved 2026-11-14): one of two, picked per page load.
// Decorative — the title carries the meaning; it is the LCP image, so eager.
$tsr_hero_photos = array(
	array( 'zhelyava', '50% 60%' ),  // Желява
	array( 'palakaria', '50% 40%' ), // Палакария
);
$tsr_hero_pick = $tsr_hero_photos[ wp_rand( 0, count( $tsr_hero_photos ) - 1 ) ];
$tsr_hero_uri  = get_stylesheet_directory_uri() . '/images/hero/' . $tsr_hero_pick[0];
?>
<section class="tsr-hero tsr-hero--photo" aria-label="Начало">
	<img class="tsr-hero__photo"
	     src="<?php echo esc_url( $tsr_hero_uri . '-1920.jpg' ); ?>"
	     srcset="<?php echo esc_url( $tsr_hero_uri . '-960.jpg' ); ?> 960w, <?php echo esc_url( $tsr_hero_uri . '-1920.jpg' ); ?> 1920w"
	     sizes="100vw" width="1920" height="1280" alt=""
	     style="object-position: <?php echo esc_attr( $tsr_hero_pick[1] ); ?>"
	     fetchpriority="high" decoding="async">
	<div class="tsr-hero__inner">
		<p class="tsr-hero__eyebrow">Серия планинско бягане &middot; България</p>

		<h1 class="tsr-hero__title">Trail<span>Series</span>.bg</h1>

		<p class="tsr-hero__subtitle">14 сезона по планините на България</p>

		<div class="tsr-countdown"
		     id="tsrCountdown"
		     aria-live="off"
		     aria-label="Обратно броене до 15-ия юбилей">
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrDays">--</span>
				<span class="tsr-countdown__label">дни</span>
			</div>
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrHours">--</span>
				<span class="tsr-countdown__label">часа</span>
			</div>
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrMins">--</span>
				<span class="tsr-countdown__label">минути</span>
			</div>
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrSecs">--</span>
				<span class="tsr-countdown__label">секунди</span>
			</div>
		</div>

		<p class="tsr-hero__anniversary">до 15-ия юбилей &mdash; октомври 2027</p>
	</div>
</section>

<script>
/* global clearInterval */
(function () {
	'use strict';

	// Target: 1 October 2027, midnight (browser local time).
	// Adjust the ISO string to a specific hour if a precise Sofia-time
	// midnight is needed: '2027-10-01T00:00:00+03:00'.
	var TARGET = new Date('2027-10-01T00:00:00');

	var wrap  = document.getElementById('tsrCountdown');
	var elD   = document.getElementById('tsrDays');
	var elH   = document.getElementById('tsrHours');
	var elM   = document.getElementById('tsrMins');
	var elS   = document.getElementById('tsrSecs');

	function pad(n) {
		return String(n).padStart(2, '0');
	}

	function tick() {
		var diff = TARGET.getTime() - Date.now();

		if (diff <= 0) {
			wrap.innerHTML = '<p class="tsr-countdown__done">15 години TrailSeries.bg!</p>';
			clearInterval(timer); // eslint-disable-line no-use-before-define
			return;
		}

		var totalSec = Math.floor(diff / 1000);
		var d = Math.floor(totalSec / 86400);
		var h = Math.floor((totalSec % 86400) / 3600);
		var m = Math.floor((totalSec % 3600) / 60);
		var s = totalSec % 60;

		elD.textContent = d;
		elH.textContent = pad(h);
		elM.textContent = pad(m);
		elS.textContent = pad(s);
	}

	tick();
	var timer = setInterval(tick, 1000);
}());
</script>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 2 — UPCOMING EVENTS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-upcoming" aria-labelledby="tsr-upcoming-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-upcoming-title">Предстоящи събития</h2>

		<?php if ( ! empty( $tsr_upcoming ) ) : ?>
			<div class="tsr-event-cards">
				<?php foreach ( $tsr_upcoming as $ev ) :
					$tsr_day        = date_i18n( 'j', $ev['start_ts'] );
					$tsr_month      = date_i18n( 'M', $ev['start_ts'] );
					$tsr_time       = $ev['time_ts'] > 0 ? date_i18n( 'H:i', $ev['time_ts'] ) . 'ч' : '';
					?>
					<article class="tsr-event-card">
						<?php echo $ev['image']; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image() output. ?>
						<a class="tsr-event-card__link"
						   href="<?php echo esc_url( $ev['url'] ); ?>"
						   aria-label="<?php echo esc_attr( $ev['title'] ); ?>"></a>

						<div class="tsr-event-card__date">
							<span class="tsr-event-card__day"><?php echo esc_html( $tsr_day ); ?></span>
							<span class="tsr-event-card__month"><?php echo esc_html( $tsr_month ); ?></span>
						</div>

						<div class="tsr-event-card__content">
							<h3 class="tsr-event-card__title"><?php echo esc_html( $ev['title'] ); ?></h3>
							<?php if ( '' !== $tsr_time ) : ?>
								<p class="tsr-event-card__meta-row">
									<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
									<?php echo esc_html( $tsr_time ); ?>
								</p>
							<?php endif; ?>
							<?php if ( '' !== $ev['location'] ) : ?>
								<p class="tsr-event-card__meta-row">
									<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
									<?php echo esc_html( $ev['location'] ); ?>
								</p>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="tsr-empty">
				Няма предстоящи събития в момента &mdash; следете за обновления!
			</p>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link" href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>">
				Пълен календар
			</a>
		</p>
	</div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 3 — PAST EVENTS / RESULTS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-past" aria-labelledby="tsr-past-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-past-title">Последни резултати</h2>

		<?php if ( ! empty( $tsr_past_results ) ) : ?>
			<ul class="tsr-result-list">
				<?php foreach ( $tsr_past_results as $result_post ) : ?>
					<li class="tsr-result-list__item">
						<a class="tsr-result-list__title"
						   href="<?php echo esc_url( get_permalink( $result_post ) ); ?>">
							<?php echo esc_html( get_the_title( $result_post ) ); ?>
						</a>
						<span class="tsr-result-list__meta">
							<?php echo esc_html( get_the_date( 'j.m.Y', $result_post ) ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="tsr-empty">Все още няма публикувани резултати.</p>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link"
			   href="<?php echo esc_url( home_url( '/rezultati/' ) ); ?>">
				Всички резултати
			</a>
		</p>
	</div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 4 — LATEST NEWS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-news" aria-labelledby="tsr-news-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-news-title">Новини</h2>

		<?php if ( ! empty( $tsr_news ) ) : ?>
			<div class="tsr-grid">
				<?php foreach ( $tsr_news as $news_post ) :
					// width/height + srcset from WP (audit M18) — the plain <img> had
					// no dimensions (layout shift) and one fixed 300 px source.
					$tsr_thumb = tsr_card_image( $news_post, 'tsr-card__thumb', '(max-width: 768px) calc(100vw - 2.5rem), 380px' );
					?>
					<article class="tsr-card">
						<?php echo $tsr_thumb; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image() output. ?>
						<div class="tsr-card__body">
							<p class="tsr-card__meta">
								<?php echo esc_html( get_the_date( 'j F Y', $news_post ) ); ?>
							</p>
							<h3 class="tsr-card__title">
								<?php echo esc_html( get_the_title( $news_post ) ); ?>
							</h3>
							<p class="tsr-card__meta">
								<?php
								$tsr_excerpt = $news_post->post_excerpt
									?: wp_trim_words( strip_shortcodes( $news_post->post_content ), 20, '…' );
								echo esc_html( $tsr_excerpt );
								?>
							</p>
							<a class="tsr-card__link"
							   href="<?php echo esc_url( get_permalink( $news_post ) ); ?>">
								Прочети
							</a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="tsr-empty">Все още няма публикувани новини.</p>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link" href="<?php echo esc_url( home_url( '/novini/' ) ); ?>">
				Всички новини
			</a>
		</p>
	</div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 5 — ZERO TO HERO
     ════════════════════════════════════════════════════════════════════════ -->
<?php if ( ! empty( $tsr_zero ) ) :
	// Cards visible per "page" on desktop — never more than the actual post
	// count, so 1-2 stories render as 1-2 full-width cards instead of a
	// mostly-empty row. Exposed as a CSS var so the no-JS first paint (and
	// the mobile 1-per-row override below) size cards the same way the
	// carousel script will once it measures the live viewport.
	$tsr_zero_visible_n = min( 3, count( $tsr_zero ) );
?>
<section class="tsr-section tsr-zero-section" aria-labelledby="tsr-zero-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-zero-title"><span class="tsr-zero-title__accent">Zero</span> to HERO</h2>

		<!-- Single-row sliding carousel — every story is one .tsr-zero-card in
		     .tsr-zero-track; JS measures the viewport and slides the track in
		     real pixels so exactly N full cards are always on one row (never a
		     lone card wrapping to a second row). -->
		<div class="tsr-zero-carousel" id="tsr-zero-carousel" style="--tsr-zero-visible: <?php echo (int) $tsr_zero_visible_n; ?>">
			<div class="tsr-zero-viewport">
				<div class="tsr-zero-track">
					<?php foreach ( $tsr_zero as $tsr_zp ) :
						// <img> with srcset + lazy loading (audit M18), not a CSS background.
						$tsr_z_img = tsr_card_image( $tsr_zp, 'tsr-zero-card__img', '(max-width: 768px) calc(100vw - 2.5rem), 380px' );
						// A manual post_excerpt is used as-is by get_the_excerpt() — any
						// literal shortcode markup in it (e.g. a leftover [quote] tag) is
						// never stripped automatically, only auto-generated excerpts get
						// that treatment. Strip shortcodes/tags from whichever source we
						// use before trimming.
						$tsr_z_source  = '' !== trim( (string) $tsr_zp->post_excerpt ) ? $tsr_zp->post_excerpt : $tsr_zp->post_content;
						$tsr_z_excerpt = wp_trim_words( wp_strip_all_tags( tsr_strip_shortcode_syntax( strip_shortcodes( $tsr_z_source ) ) ), 18, '…' );
						// Publish month/year as the eyebrow — real metadata, not an
						// arbitrary "01/02/03" counter (page-calendar.php uses this
						// same date_i18n( 'F Y', ... ) pattern for month grouping).
						$tsr_z_eyebrow = date_i18n( 'F Y', strtotime( $tsr_zp->post_date ) );
						?>
						<article class="tsr-zero-card">
							<?php echo $tsr_z_img; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image() output. ?>
							<span class="tsr-zero-card__eyebrow"><?php echo esc_html( $tsr_z_eyebrow ); ?></span>
							<div class="tsr-zero-card__body">
								<h3 class="tsr-zero-card__title"><?php echo esc_html( get_the_title( $tsr_zp ) ); ?></h3>
								<p class="tsr-zero-card__excerpt"><?php echo esc_html( $tsr_z_excerpt ); ?></p>
								<a class="tsr-zero-card__link" href="<?php echo esc_url( get_permalink( $tsr_zp ) ); ?>">Прочети →</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( count( $tsr_zero ) > 1 ) : ?>
				<button class="tsr-zero-nav tsr-zero-nav--prev" type="button"
				        aria-label="Предишна история" aria-controls="tsr-zero-carousel">&lsaquo;</button>
				<button class="tsr-zero-nav tsr-zero-nav--next" type="button"
				        aria-label="Следваща история" aria-controls="tsr-zero-carousel">&rsaquo;</button>
				<div class="tsr-zero-dots" id="tsr-zero-dots"></div>
			<?php endif; ?>
		</div>

	</div>
</section>

<?php if ( count( $tsr_zero ) > 1 ) : ?>
<script>
(function () {
	'use strict';

	var root = document.getElementById( 'tsr-zero-carousel' );
	if ( ! root ) { return; }
	var viewport = root.querySelector( '.tsr-zero-viewport' );
	var track    = root.querySelector( '.tsr-zero-track' );
	var cards    = Array.prototype.slice.call( track.children );
	var dotsWrap = root.querySelector( '.tsr-zero-dots' );
	var prevBtn  = root.querySelector( '.tsr-zero-nav--prev' );
	var nextBtn  = root.querySelector( '.tsr-zero-nav--next' );
	var total    = cards.length;
	if ( total < 2 ) { return; }

	var index     = 0;
	var maxIndex  = 0;
	var cardWidth = 0;
	var gapPx     = 32;
	var timer     = null;
	var stopped   = false; // set forever on first manual interaction

	// ── Measure + size: exactly N full cards visible, never a clipped Nth
	//    card — recomputed on load and on resize (debounced) ─────────────
	function layout() {
		var cs = getComputedStyle( track );
		gapPx  = parseFloat( cs.columnGap || cs.gap ) || gapPx;

		var vpWidth = viewport.clientWidth;
		var visible = vpWidth <= 768 ? 1 : Math.min( 3, total );
		maxIndex    = Math.max( 0, total - visible );
		if ( index > maxIndex ) { index = maxIndex; }

		cardWidth = ( vpWidth - gapPx * ( visible - 1 ) ) / visible;
		cards.forEach( function ( card ) {
			card.style.flex = '0 0 ' + cardWidth + 'px';
		} );

		buildDots();
		move( false );
	}

	function buildDots() {
		var showControls = maxIndex > 0;
		if ( prevBtn )  { prevBtn.style.display  = showControls ? '' : 'none'; }
		if ( nextBtn )  { nextBtn.style.display  = showControls ? '' : 'none'; }
		if ( ! dotsWrap ) { return; }
		dotsWrap.style.display = showControls ? '' : 'none';
		dotsWrap.innerHTML = '';
		for ( var i = 0; i <= maxIndex; i++ ) {
			var dot = document.createElement( 'button' );
			dot.type      = 'button';
			dot.className = 'tsr-zero-dot';
			dot.setAttribute( 'aria-label', 'Покажи истории от позиция ' + ( i + 1 ) );
			dot.setAttribute( 'aria-controls', 'tsr-zero-carousel' );
			dot.dataset.index = String( i );
			dot.addEventListener( 'click', function () {
				stopAuto();
				go( parseInt( this.dataset.index, 10 ) );
			} );
			dotsWrap.appendChild( dot );
		}
	}

	function move( animate ) {
		if ( false === animate ) {
			track.style.transition = 'none';
			void track.offsetHeight; // force reflow so the transition:none actually applies
		} else {
			track.style.transition = '';
		}
		track.style.transform = 'translateX(-' + ( index * ( cardWidth + gapPx ) ) + 'px)';

		var dots = dotsWrap ? dotsWrap.querySelectorAll( '.tsr-zero-dot' ) : [];
		for ( var i = 0; i < dots.length; i++ ) {
			dots[ i ].classList.toggle( 'is-active', i === index );
			dots[ i ].setAttribute( 'aria-current', i === index ? 'true' : 'false' );
		}
	}

	function go( n ) {
		if ( 0 === maxIndex ) { return; }
		index = ( n + maxIndex + 1 ) % ( maxIndex + 1 );
		move( true );
	}

	function stopAuto() {
		stopped = true;
		if ( timer ) { clearInterval( timer ); timer = null; }
	}

	// ── Manual controls: any use pauses auto-rotation for good ────────────
	if ( prevBtn ) { prevBtn.addEventListener( 'click', function () { stopAuto(); go( index - 1 ); } ); }
	if ( nextBtn ) { nextBtn.addEventListener( 'click', function () { stopAuto(); go( index + 1 ); } ); }

	// ── Touch swipe (mobile) ───────────────────────────────────────────────
	var touchX = null;
	viewport.addEventListener( 'touchstart', function ( ev ) {
		touchX = ev.changedTouches[0].clientX;
	}, { passive: true } );
	viewport.addEventListener( 'touchend', function ( ev ) {
		if ( null === touchX ) { return; }
		var dx = ev.changedTouches[0].clientX - touchX;
		touchX = null;
		if ( Math.abs( dx ) < 40 ) { return; } // tap, not a swipe
		stopAuto();
		go( dx < 0 ? index + 1 : index - 1 );
	}, { passive: true } );

	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( layout, 150 );
	} );

	layout();

	// ── Auto-rotation: 5 s cadence, paused while hovered, none for
	//    prefers-reduced-motion users ─────────────────────────────────────
	if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}
	function startAuto() {
		if ( stopped || timer || 0 === maxIndex ) { return; }
		timer = setInterval( function () { go( index + 1 ); }, 5000 );
	}
	root.addEventListener( 'mouseenter', function () {
		if ( timer ) { clearInterval( timer ); timer = null; }
	} );
	root.addEventListener( 'mouseleave', startAuto );
	startAuto();
}());
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 6 — MAP: ТРАСЕТАТА (redesign 2026-11-14: dark basemap, numbered
     pins, list beside the map, zoomed to Sofia; Pirin behind a button)
     ════════════════════════════════════════════════════════════════════════ -->
<?php
// Group races that share a start (Бухово: three races; Кокаляне: two) into
// one numbered pin; split off the Pirin races (≈ 90 km south) so the map can
// zoom to Sofia.
$tsr_map_groups = array();
$tsr_pirin      = array();
foreach ( $tsr_map_pins as $tsr_pin ) {
	if ( $tsr_pin['lat'] < 42.2 ) {
		$tsr_pirin[] = $tsr_pin;
		continue;
	}
	$tsr_hit = null;
	foreach ( $tsr_map_groups as $tsr_gi => $tsr_g ) {
		if ( abs( $tsr_g['lat'] - $tsr_pin['lat'] ) < 0.004 && abs( $tsr_g['lng'] - $tsr_pin['lng'] ) < 0.004 ) {
			$tsr_hit = $tsr_gi;
			break;
		}
	}
	if ( null === $tsr_hit ) {
		$tsr_map_groups[] = array(
			'lat'   => $tsr_pin['lat'],
			'lng'   => $tsr_pin['lng'],
			'items' => array( $tsr_pin ),
		);
	} else {
		$tsr_map_groups[ $tsr_hit ]['items'][] = $tsr_pin;
	}
}
if ( array() !== $tsr_pirin ) {
	$tsr_map_groups[] = array(
		'lat'   => $tsr_pirin[0]['lat'],
		'lng'   => $tsr_pirin[0]['lng'],
		'items' => $tsr_pirin,
		'pirin' => true,
	);
}
foreach ( $tsr_map_groups as $tsr_gi => $tsr_g ) {
	$tsr_map_groups[ $tsr_gi ]['n']      = $tsr_gi + 1;
	$tsr_map_groups[ $tsr_gi ]['active'] = array() !== array_filter( $tsr_g['items'], static fn( array $e ): bool => '' === $e['note'] );
	$tsr_map_groups[ $tsr_gi ]['title']  = ! empty( $tsr_g['pirin'] ) ? 'Пирин' : implode( ' · ', array_column( $tsr_g['items'], 'name' ) );
}
?>
<section class="tsr-section tsr-map-section" aria-labelledby="tsr-map-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-map-title">Трасетата</h2>

		<div class="tsr-map-layout">
			<div class="tsr-map-wrap">
				<div id="tsr-map"
				     class="tsr-map"
				     role="application"
				     aria-label="Карта на трасетата около София"></div>
				<?php if ( array() !== $tsr_pirin ) : ?>
					<button class="tsr-map-pirin" type="button" data-tsr-pirin hidden>
						Пирин · <?php echo esc_html( tsr_races_label( count( $tsr_pirin ) ) ); ?> ↘
					</button>
				<?php endif; ?>
			</div>

			<ol class="tsr-map-list" aria-label="Състезания на картата">
				<?php foreach ( $tsr_map_groups as $tsr_g ) : ?>
					<li>
						<button class="tsr-map-list__item<?php echo $tsr_g['active'] ? '' : ' tsr-map-list__item--hist'; ?>" type="button" data-tsr-pin="<?php echo esc_attr( (string) $tsr_g['n'] ); ?>">
							<span class="tsr-map-list__num" aria-hidden="true"><?php echo esc_html( ! empty( $tsr_g['pirin'] ) ? 'П' : (string) $tsr_g['n'] ); ?></span>
							<span class="tsr-map-list__text">
								<strong><?php echo esc_html( $tsr_g['title'] ); ?></strong>
								<span>
									<?php
									if ( count( $tsr_g['items'] ) > 1 ) {
										echo esc_html( ! empty( $tsr_g['pirin'] ) ? implode( ', ', array_column( $tsr_g['items'], 'name' ) ) : tsr_races_label( count( $tsr_g['items'] ) ) . ' с общ старт' );
									} else {
										$tsr_e = $tsr_g['items'][0];
										echo esc_html( '' !== $tsr_e['note'] ? $tsr_e['note'] : ( '' !== $tsr_e['month'] ? $tsr_e['month'] . ' · ' . $tsr_e['dist'] : $tsr_e['dist'] ) );
									}
									?>
								</span>
							</span>
						</button>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</div>
</section>

<script>
/* global L */
(function () {
	'use strict';

	var initialized = false;
	var groups      = <?php echo wp_json_encode( array_values( $tsr_map_groups ) ); ?>;

	function initTsrMap() {
		if ( initialized || typeof L === 'undefined' ) { return; }
		initialized = true;

		var map = L.map( 'tsr-map', { scrollWheelZoom: false } );
		map.on( 'focus', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'blur',  function () { map.scrollWheelZoom.disable(); } );

		// Dark basemap in the site's colours (Esri Canvas, no API key).
		L.tileLayer(
			'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}',
			{
				attribution: 'Tiles &copy; <a href="https://www.esri.com" target="_blank" rel="noopener">Esri</a>',
				maxZoom: 16,
			}
		).addTo( map );

		var esc = function ( text ) {
			var d = document.createElement( 'div' );
			d.textContent = text;
			return d.innerHTML;
		};
		var markers = {};
		var sofia   = [];

		groups.forEach( function ( g ) {
			var label = g.pirin ? 'П' : String( g.n );
			var icon  = L.divIcon( {
				className:   '',
				html:        '<div class="tsr-pin' + ( g.active ? '' : ' tsr-pin--hist' ) + '" role="img" aria-label="' + esc( g.title ) + '"><b>' + label + '</b></div>',
				iconSize:    [ 34, 34 ],
				iconAnchor:  [ 17, 40 ],
				popupAnchor: [ 0, -38 ],
			} );
			var lines = g.items.map( function ( e ) {
				return '<strong>' + esc( e.name ) + '</strong>' +
					( e.month ? '<br>' + esc( e.month ) : '' ) +
					'<br><span class="tsr-popup-dist">' + esc( e.dist ) + '</span>' +
					( e.note ? '<br><em>' + esc( e.note ) + '</em>' : '' );
			} );
			markers[ g.n ] = L.marker( [ g.lat, g.lng ], { icon: icon, title: g.title, riseOnHover: true } )
				.bindPopup( lines.join( '<hr>' ) )
				.addTo( map );
			if ( ! g.pirin ) {
				sofia.push( [ g.lat, g.lng ] );
			}
		} );

		var showSofia = function () {
			map.fitBounds( sofia, { padding: [ 40, 40 ], maxZoom: 12 } );
		};
		showSofia();

		// List ↔ pins
		document.querySelectorAll( '[data-tsr-pin]' ).forEach( function ( btn ) {
			var m = markers[ btn.getAttribute( 'data-tsr-pin' ) ];
			if ( ! m ) { return; }
			btn.addEventListener( 'mouseenter', function () { m.getElement() && m.getElement().classList.add( 'is-on' ); } );
			btn.addEventListener( 'mouseleave', function () { m.getElement() && m.getElement().classList.remove( 'is-on' ); } );
			btn.addEventListener( 'click', function () {
				map.flyTo( m.getLatLng(), Math.max( map.getZoom(), 12 ), { duration: 0.6 } );
				m.openPopup();
			} );
		} );

		// Pirin is ≈ 90 km south: a button instead of zooming the map out.
		var pirinBtn = document.querySelector( '[data-tsr-pirin]' );
		var pirin    = groups.filter( function ( g ) { return g.pirin; } )[ 0 ];
		if ( pirinBtn && pirin ) {
			var atPirin = false;
			pirinBtn.hidden = false;
			pirinBtn.addEventListener( 'click', function () {
				atPirin = ! atPirin;
				if ( atPirin ) {
					map.flyTo( [ pirin.lat, pirin.lng ], 12, { duration: 0.8 } );
					pirinBtn.textContent = 'Обратно към София ↖';
				} else {
					showSofia();
					pirinBtn.textContent = 'Пирин · ' + pirin.items.length + ' състезания ↘';
				}
			} );
		}
	}

	// Audit M19: Leaflet + tiles load only when the map nears the viewport
	// (js/tsr-leaflet.js, deferred — hence the DOMContentLoaded wait).
	function start() {
		if ( window.tsrLeafletWhenVisible ) {
			window.tsrLeafletWhenVisible( document.getElementById( 'tsr-map' ), initTsrMap );
		}
	}
	if ( window.tsrLeafletWhenVisible ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
}() );
</script>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 7 — ПАРТНЬОРИ
     ════════════════════════════════════════════════════════════════════════ -->
<?php if ( ! empty( $tsr_partners ) ) :
	$tsr_ph_colors = array( '#00aadd', '#0a1628', '#e05c1e', '#0088bb', '#0d2040' );
	?>
<section class="tsr-section tsr-partners-home" aria-labelledby="tsr-partners-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-partners-title">Партньори</h2>

		<div class="tsr-partners-strip">
			<?php foreach ( $tsr_partners as $tsr_i => $tsr_partner ) : ?>
				<?php
				$tsr_url  = (string) get_post_meta( $tsr_partner->ID, '_tsr_partner_url', true );
				$tsr_name = get_the_title( $tsr_partner );
				$tsr_tag  = '' !== $tsr_url ? 'a' : 'div';
				?>
				<<?php echo $tsr_tag; // phpcs:ignore WordPress.Security.EscapeOutput -- 'a' or 'div' literal. ?>
					class="tsr-partner-card"
					<?php if ( '' !== $tsr_url ) : ?>
						href="<?php echo esc_url( $tsr_url ); ?>" target="_blank" rel="noopener sponsored"
					<?php endif; ?>
				>
					<?php if ( has_post_thumbnail( $tsr_partner ) ) : ?>
						<span class="tsr-partner-card__logo">
							<?php echo get_the_post_thumbnail( $tsr_partner, 'medium', array( 'alt' => $tsr_name, 'loading' => 'lazy' ) ); ?>
						</span>
					<?php else : ?>
						<span class="tsr-partner-card__logo tsr-partner-card__logo--ph"
							style="background: <?php echo esc_attr( $tsr_ph_colors[ $tsr_i % count( $tsr_ph_colors ) ] ); ?>">
							<?php echo esc_html( tsr_partner_initials( $tsr_name ) ); ?>
						</span>
					<?php endif; ?>
					<span class="tsr-partner-card__name"><?php echo esc_html( $tsr_name ); ?></span>
				</<?php echo $tsr_tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php endforeach; ?>
		</div>

		<p class="tsr-view-all">
			<a class="tsr-card__link" href="<?php echo esc_url( home_url( '/partniori/' ) ); ?>">
				Всички партньори
			</a>
		</p>
	</div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 8 — QUICK STATS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-stats" aria-label="Статистика на сезоните">
	<div class="tsr-container">
		<div class="tsr-stats__grid">

			<div class="tsr-stat">
				<div class="tsr-stat__num">14</div>
				<div class="tsr-stat__label">сезона</div>
			</div>

			<?php
			// Races organised by the series — the owner's count: the 149
			// races with published results plus The Christmas Run 2026, as
			// the site goes live after it (owner, 2026-11-14). Update after
			// each race: wp option update tsr_races_held 151
			$tsr_races_held = (int) get_option( 'tsr_races_held', 150 );
			?>
			<div class="tsr-stat">
				<div class="tsr-stat__num"><?php echo esc_html( (string) $tsr_races_held ); ?></div>
				<div class="tsr-stat__label">състезания</div>
			</div>

			<div class="tsr-stat">
				<div class="tsr-stat__num">
					<?php
					if ( $tsr_total_finishers > 0 ) {
						echo esc_html( number_format( $tsr_total_finishers, 0, ',', "\u{00A0}" ) );
					} else {
						echo '&mdash;';
					}
					?>
				</div>
				<div class="tsr-stat__label">финиширали</div>
			</div>

			<div class="tsr-stat">
				<div class="tsr-stat__num">
					<?php echo esc_html( $tsr_total_races > 0 ? $tsr_total_races : '—' ); ?>
				</div>
				<div class="tsr-stat__label">класирания</div>
			</div>

		</div>
	</div>
</section>

</main>

<?php get_footer(); ?>
