<?php
declare( strict_types=1 );
/**
 * Template Name: Трасета
 *
 * Template for the Трасета page (slug: traseta). Design A (owner-approved
 * 2026-11-19): one card per race in calendar order — the race's calendar photo,
 * month and place on top, then one row per distance (category colour, GPX
 * distance, D+, highest point, difficulty, a small elevation profile). Races no
 * longer on the calendar follow under "Други трасета". Filter chips show one
 * distance category at a time (js/traseta-filter.js).
 *
 * Track data: data/tracks.json (migration/build_tracks_theme_data.py), with the
 * admin status override from Tools → Трасета — етикети (tsr_track_status()).
 * Clicking a row opens the detail modal (js/traseta-modal.js): map, profile,
 * GPX / KML / Strava.
 *
 * Category colours: short green, medium blue, long red; the 7 Hills Run
 * Hard Core 26 km is dark red (owner's choice — TSR_TRACK_XL_SLUGS).
 *
 * @package exhibz-child
 */

// Track helpers (tsr_track_nominal_km, tsr_track_cat, tsr_track_profiles,
// tsr_track_result_stats, tsr_track_spark, TSR_TRACK_XL_SLUGS …) live in
// functions.php — the event page uses them too.

if ( ! function_exists( 'tsr_trk_row' ) ) {
	/**
	 * One distance row. The data-* attributes feed the modal (js/traseta-modal.js).
	 *
	 * @param array<string, mixed>     $tr       Track entry from tracks.json.
	 * @param string                   $event    Race name.
	 * @param array<string, list<int>> $profiles tsr_track_profiles() output.
	 * @param array<string, mixed>     $ctx      Modal extras: 'kicker' (race · month · place),
	 *                                           'race_url', 'stats' (tsr_track_result_stats()).
	 */
	function tsr_trk_row( array $tr, string $event, array $profiles, array $ctx = array() ): void {
		$gpx_base    = get_stylesheet_directory_uri() . '/gpx/';
		$points_base = get_stylesheet_directory_uri() . '/data/tracks/';
		$nominal     = tsr_track_nominal_km( $tr );
		$cat         = tsr_track_cat( $nominal );
		$colour      = in_array( $tr['slug'] ?? '', TSR_TRACK_XL_SLUGS, true ) ? 'xl' : $cat;
		$cat_label   = array( 'short' => 'къса', 'medium' => 'средна', 'long' => 'дълга' )[ $cat ] ?? '';
		$label       = null !== $nominal ? str_replace( '.', ',', (string) $nominal ) . ' км' : (string) ( $tr['variant'] ?? $tr['title'] );
		$laps        = tsr_track_laps( $tr );
		$legacy      = 'legacy' === tsr_track_status( $tr );
		$prof        = $profiles[ (string) ( $tr['points_file'] ?? '' ) ] ?? array();
		$stats       = $legacy ? null : ( $ctx['stats'][ $tr['slug'] ] ?? null );
		$modal_stats = null;
		if ( $stats && $stats['n'] >= 20 ) { // too few finishes say nothing about a typical time
			$modal_stats = array(
				'n'     => $stats['n'],
				'p'     => array( $stats['p10'], $stats['p50'], $stats['p90'] ),
				'years' => $stats['years'],
			);
		}
		?>
		<li class="tsr-trk tsr-trk--<?php echo esc_attr( '' !== $colour ? $colour : 'none' ); ?>"
			data-cat="<?php echo esc_attr( $cat ); ?>"
			data-title="<?php echo esc_attr( $tr['title'] ); ?>"
			data-variant="<?php echo esc_attr( $tr['variant'] ?? '' ); ?>"
			data-event="<?php echo esc_attr( $event ); ?>"
			data-gpx="<?php echo esc_attr( ! empty( $tr['gpx_file'] ) ? $gpx_base . $tr['gpx_file'] : '' ); ?>"
			data-kml="<?php echo esc_attr( ! empty( $tr['kml_file'] ) ? $gpx_base . $tr['kml_file'] : '' ); ?>"
			data-points="<?php echo esc_attr( ! empty( $tr['points_file'] ) ? $points_base . $tr['points_file'] : '' ); ?>"
			data-strava="<?php echo esc_attr( tsr_track_strava( $tr ) ); ?>"
			data-distance="<?php echo esc_attr( (string) ( $tr['distance_km'] ?? '' ) ); ?>"
			data-ascent="<?php echo esc_attr( (string) ( $tr['ascent_m'] ?? '' ) ); ?>"
			data-descent="<?php echo esc_attr( (string) ( $tr['descent_m'] ?? '' ) ); ?>"
			data-highest="<?php echo esc_attr( (string) ( $tr['highest_m'] ?? '' ) ); ?>"
			data-lowest="<?php echo esc_attr( (string) ( $tr['lowest_m'] ?? '' ) ); ?>"
			data-slug="<?php echo esc_attr( (string) $tr['slug'] ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			data-catname="<?php echo esc_attr( $cat_label ); ?>"
			data-colour="<?php echo esc_attr( $colour ); ?>"
			data-stars="<?php echo esc_attr( (string) ( $tr['stars'] ?? '' ) ); ?>"
			data-laps="<?php echo esc_attr( (string) $laps ); ?>"
			data-kicker="<?php echo esc_attr( $legacy && ! empty( $tr['year'] ) ? $event . ' · стара версия ' . $tr['year'] : ( $ctx['kicker'] ?? $event ) ); ?>"
			data-garmin="<?php echo esc_attr( tsr_track_garmin( $tr ) ); ?>"
			data-race-url="<?php echo esc_attr( (string) ( $ctx['race_url'] ?? '' ) ); ?>"
			data-results="<?php echo esc_attr( $stats['results'] ?? '' ); ?>"
			data-results-year="<?php echo esc_attr( (string) ( $stats['year'] ?? '' ) ); ?>"
			<?php if ( $modal_stats ) : ?>data-stats="<?php echo esc_attr( (string) wp_json_encode( $modal_stats ) ); ?>"<?php endif; ?>>
			<div class="tsr-trk__km">
				<?php // The button's name starts with the visible text; the full variant and race follow for screen readers. ?>
				<button class="tsr-trk__open" type="button" aria-haspopup="dialog"><?php echo esc_html( $label ); ?><span class="screen-reader-text"> — <?php echo esc_html( $event . ', ' . ( $tr['variant'] ?? '' ) ); ?>, детайли за трасето</span></button>
				<?php if ( '' !== $cat_label ) : ?>
					<small><?php echo esc_html( $cat_label ); ?></small>
				<?php endif; ?>
			</div>
			<div class="tsr-trk__mid">
				<div class="tsr-trk__stats">
					<?php if ( ! empty( $tr['distance_km'] ) ) : ?>
						<span title="Дължина по GPX"><b><?php echo esc_html( number_format_i18n( (float) $tr['distance_km'], 1 ) ); ?> км</b></span>
					<?php endif; ?>
					<?php if ( isset( $tr['ascent_m'] ) ) : ?>
						<span title="Изкачване"><span aria-hidden="true">↗</span><span class="screen-reader-text">Изкачване</span> <b><?php echo esc_html( number_format_i18n( (int) $tr['ascent_m'] ) ); ?> м</b></span>
					<?php endif; ?>
					<?php if ( isset( $tr['highest_m'] ) ) : ?>
						<span title="Най-висока точка"><span aria-hidden="true">▲</span><span class="screen-reader-text">Най-висока точка</span> <b><?php echo esc_html( number_format_i18n( (int) $tr['highest_m'] ) ); ?> м</b></span>
					<?php endif; ?>
					<?php if ( $laps > 1 ) : ?>
						<span class="tsr-trk__laps"><?php echo esc_html( $laps ); ?> обиколки</span>
					<?php endif; ?>
					<?php if ( $legacy && ! empty( $tr['year'] ) ) : ?>
						<span><?php echo esc_html( (string) $tr['year'] ); ?></span>
					<?php endif; ?>
					<?php echo tsr_star_rating( isset( $tr['stars'] ) ? (int) $tr['stars'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from ints + esc_attr. ?>
				</div>
				<?php echo tsr_track_spark( $prof ); // phpcs:ignore WordPress.Security.EscapeOutput -- numbers only. ?>
			</div>
			<span class="tsr-trk__go" aria-hidden="true">›</span>
		</li>
		<?php
	}
}

if ( ! function_exists( 'tsr_trk_card' ) ) {
	/**
	 * One race card.
	 *
	 * @param array<string, mixed>      $event    tracks.json event.
	 * @param array<string, mixed>|null $cal      Calendar data (month, place, image) or null.
	 * @param array<string, list<int>>  $profiles tsr_track_profiles() output.
	 * @param array<string, mixed>      $stats    tsr_track_result_stats() output.
	 */
	function tsr_trk_card( array $event, ?array $cal, array $profiles, array $stats = array() ): void {
		$current = array();
		$legacy  = array();
		foreach ( (array) $event['tracks'] as $tr ) {
			if ( 'legacy' === tsr_track_status( $tr ) ) {
				$legacy[] = $tr;
			} else {
				$current[] = $tr;
			}
		}
		$by_km = static fn( array $a, array $b ): int => ( $a['distance_km'] ?? 0 ) <=> ( $b['distance_km'] ?? 0 );
		usort( $current, $by_km );
		usort( $legacy, $by_km );
		// A race whose tracks are all old versions shows them directly — a card
		// with only a folded "Стари версии" looks empty.
		if ( ! $current ) {
			$current = $legacy;
			$legacy  = array();
		}
		$name  = (string) $event['name'];
		$image = $cal['image'] ?? '';
		$ctx   = array(
			'kicker'   => implode( ' · ', array_filter( array( $name, $cal['month'] ?? '', $cal['place'] ?? '' ) ) ),
			'race_url' => $cal['url'] ?? '',
			'stats'    => $stats,
		);
		?>
		<article class="tsr-tc__card<?php echo '' !== $image ? ' tsr-tc__card--photo' : ''; ?>">
			<header class="tsr-tc__top">
				<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image(). ?>
				<?php if ( ! empty( $cal['month'] ) ) : ?>
					<span class="tsr-tc__month"><?php echo esc_html( $cal['month'] ); ?></span>
				<?php endif; ?>
				<div>
					<h2 class="tsr-tc__name"><?php echo esc_html( $name ); ?></h2>
					<?php if ( ! empty( $cal['place'] ) ) : ?>
						<p class="tsr-tc__place"><?php echo esc_html( $cal['place'] ); ?></p>
					<?php endif; ?>
				</div>
			</header>
			<?php if ( $current ) : ?>
				<ul class="tsr-tc__rows">
					<?php
					foreach ( $current as $tr ) {
						tsr_trk_row( $tr, $name, $profiles, $ctx );
					}
					?>
				</ul>
			<?php endif; ?>
			<?php if ( $legacy ) : ?>
				<details class="tsr-tc__old">
					<summary>Стари версии (<?php echo esc_html( (string) count( $legacy ) ); ?>)</summary>
					<ul class="tsr-tc__rows">
						<?php
						foreach ( $legacy as $tr ) {
							tsr_trk_row( $tr, $name, $profiles, $ctx );
						}
						?>
					</ul>
				</details>
			<?php endif; ?>
		</article>
		<?php
	}
}

// ── Data ─────────────────────────────────────────────────────────────────────

$tsr_events   = tsr_tracks_events();
$tsr_profiles = tsr_track_profiles( $tsr_events );
$tsr_stats    = tsr_track_result_stats( $tsr_events );

// Calendar: races with an event in the last 14 months or ahead (same window as
// the "Серия" menu). Per race the next upcoming event wins, else the latest;
// the photo falls back to any event of that race that has one.
$tsr_cal = array(); // race name => [ts, month, place, image, order]
if ( post_type_exists( 'ajde_events' ) ) {
	$tsr_now   = time();
	$tsr_since = $tsr_now - 425 * DAY_IN_SECONDS;
	$tsr_pick  = array(); // race name => event id
	$tsr_photo = array(); // race name => event id with a featured image
	foreach ( get_posts( array( 'post_type' => 'ajde_events', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $tsr_eid ) {
		$tsr_name = tsr_runner_event_name( tsr_event_base_name( html_entity_decode( get_the_title( $tsr_eid ), ENT_QUOTES, 'UTF-8' ) ) );
		$tsr_ts   = (int) get_post_meta( $tsr_eid, 'evcal_srow', true );
		if ( has_post_thumbnail( $tsr_eid ) && ( ! isset( $tsr_photo[ $tsr_name ] ) || $tsr_ts > $tsr_photo[ $tsr_name ][1] ) ) {
			$tsr_photo[ $tsr_name ] = array( $tsr_eid, $tsr_ts );
		}
		if ( $tsr_ts < $tsr_since ) {
			continue;
		}
		$tsr_prev = $tsr_pick[ $tsr_name ] ?? null;
		// Upcoming beats past; among upcoming the soonest, among past the latest.
		$tsr_better = null === $tsr_prev
			|| ( $tsr_ts >= $tsr_now && ( $tsr_prev[1] < $tsr_now || $tsr_ts < $tsr_prev[1] ) )
			|| ( $tsr_ts < $tsr_now && $tsr_prev[1] < $tsr_now && $tsr_ts > $tsr_prev[1] );
		if ( $tsr_better ) {
			$tsr_pick[ $tsr_name ] = array( $tsr_eid, $tsr_ts );
		}
	}
	foreach ( $tsr_pick as $tsr_name => [ $tsr_eid, $tsr_ts ] ) {
		$tsr_img_id = has_post_thumbnail( $tsr_eid ) ? $tsr_eid : ( $tsr_photo[ $tsr_name ][0] ?? 0 );
		$tsr_cal[ $tsr_name ] = array(
			'month' => tsr_bg_month( (int) gmdate( 'n', $tsr_ts ) ),
			'place' => trim( (string) get_post_meta( $tsr_eid, 'evcal_location_raw', true ) ),
			'image' => $tsr_img_id ? tsr_card_image( $tsr_img_id, 'tsr-tc__img', '(max-width: 900px) calc(100vw - 2.5rem), 560px' ) : '',
			'order' => (int) gmdate( 'n', $tsr_ts ) * 100 + (int) gmdate( 'j', $tsr_ts ),
			// Same race page link as the "Серия" menu (tsr_menu_races()).
			'url'   => add_query_arg( 'sabitie', sanitize_title( tsr_event_base_name( html_entity_decode( get_the_title( $tsr_eid ), ENT_QUOTES, 'UTF-8' ) ) ), home_url( '/event/' ) ),
		);
	}
}

$tsr_on_cal = array();
$tsr_other  = array();
foreach ( $tsr_events as $tsr_event ) {
	if ( isset( $tsr_cal[ $tsr_event['name'] ] ) ) {
		$tsr_on_cal[] = $tsr_event;
	} else {
		$tsr_other[] = $tsr_event;
	}
}
usort( $tsr_on_cal, static fn( array $a, array $b ): int => $tsr_cal[ $a['name'] ]['order'] <=> $tsr_cal[ $b['name'] ]['order'] );
usort( $tsr_other, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );

$tsr_count_current = static function ( array $events ): int {
	$n = 0;
	foreach ( $events as $e ) {
		foreach ( (array) $e['tracks'] as $tr ) {
			$n += 'legacy' === tsr_track_status( $tr ) ? 0 : 1;
		}
	}
	return $n;
};

get_header();
tsr_track_icon_defs();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Трасета</h1>
		<p class="tsr-page-hero__subtitle">
			Всички маршрути от сериите — дистанция, денивелация и GPX за изтегляне
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Трасета' ); ?>

		<?php if ( empty( $tsr_events ) ) : ?>
			<p class="tsr-empty">Няма налични трасета в момента.</p>
		<?php else : ?>

			<div class="tsr-tc" data-filter="all">
				<div class="tsr-tc__bar" role="group" aria-label="Покажи дистанции">
					<button type="button" class="tsr-tc__chip" data-f="all" aria-pressed="true">Всички</button>
					<button type="button" class="tsr-tc__chip" data-f="short" aria-pressed="false"><i class="tsr-tc__dot tsr-tc__dot--short" aria-hidden="true"></i>Къса <small>под 8 км</small></button>
					<button type="button" class="tsr-tc__chip" data-f="medium" aria-pressed="false"><i class="tsr-tc__dot tsr-tc__dot--medium" aria-hidden="true"></i>Средна <small>8–13,9 км</small></button>
					<button type="button" class="tsr-tc__chip" data-f="long" aria-pressed="false"><i class="tsr-tc__dot tsr-tc__dot--long" aria-hidden="true"></i>Дълга <small>14+ км</small></button>
				</div>

				<?php if ( $tsr_on_cal ) : ?>
					<p class="tsr-tc__sub">
						<?php echo esc_html( sprintf( '%s в календара · %d трасета. Избери дистанция за карта, профил и GPX.', tsr_races_label( count( $tsr_on_cal ) ), $tsr_count_current( $tsr_on_cal ) ) ); ?>
					</p>
					<div class="tsr-tc__grid">
						<?php
						foreach ( $tsr_on_cal as $tsr_event ) {
							tsr_trk_card( $tsr_event, $tsr_cal[ $tsr_event['name'] ], $tsr_profiles, $tsr_stats );
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( $tsr_other ) : ?>
					<section class="tsr-tc__more" aria-labelledby="tsr-tc-other">
						<h2 class="tsr-tc__h2" id="tsr-tc-other">Други трасета</h2>
						<p class="tsr-tc__sub">Състезания, които в момента не са в календара. Трасетата и GPX файловете остават.</p>
						<div class="tsr-tc__grid tsr-tc__grid--plain">
							<?php
							foreach ( $tsr_other as $tsr_event ) {
								tsr_trk_card( $tsr_event, null, $tsr_profiles, $tsr_stats );
							}
							?>
						</div>
					</section>
				<?php endif; ?>

				<p class="tsr-tc__none" hidden>Няма трасета в тази категория.</p>
			</div>

		<?php endif; ?>

	</div>
</main>

<!-- Track detail modal — populated by js/traseta-modal.js on row click
     (design approved 2026-11-19): heading, key numbers, map + profile,
     typical finish times and finishers per year, sticky action bar. -->
<div class="tsr-modal" id="tsr-track-modal" hidden>
	<div class="tsr-modal__overlay" data-close></div>
	<div class="tsr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tsr-modal-title">
		<button class="tsr-modal__close" type="button" data-close aria-label="Затвори">&times;</button>
		<header class="tsr-md__head">
			<p class="tsr-md__kicker" id="tsr-modal-kicker"></p>
			<h2 class="tsr-modal__title" id="tsr-modal-title"></h2>
		</header>
		<div class="tsr-md__kpis" id="tsr-modal-stats"></div>
		<!-- Fetch status: spinner while the GPX loads, an error line when it
		     fails. aria-live so screen readers hear the state change. -->
		<div class="tsr-modal__status" id="tsr-modal-status" role="status" aria-live="polite" hidden></div>
		<div class="tsr-modal__map" id="tsr-modal-map"></div>
		<div class="tsr-modal__chart-wrap" id="tsr-modal-chart-wrap" hidden>
			<svg class="tsr-modal__chart" id="tsr-modal-chart"
			     viewBox="0 0 800 240" preserveAspectRatio="xMidYMid meet"
			     role="img" aria-label="Профил на изкачването"></svg>
			<div class="tsr-chart-tooltip" id="tsr-modal-chart-tooltip" hidden></div>
			<div class="tsr-chart-legend">
				<span class="tsr-chart-legend__item"><span class="tsr-chart-legend__swatch tsr-chart-legend__swatch--climb"></span>Изкачване</span>
				<span class="tsr-chart-legend__item"><span class="tsr-chart-legend__swatch tsr-chart-legend__swatch--flat"></span>Равно</span>
				<span class="tsr-chart-legend__item"><span class="tsr-chart-legend__swatch tsr-chart-legend__swatch--descent"></span>Спускане</span>
			</div>
		</div>
		<div class="tsr-md__sec" id="tsr-modal-results" hidden></div>
		<div class="tsr-md__acts">
			<a class="tsr-md__btn tsr-md__btn--gpx" id="tsr-modal-gpx" href="#" download hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-download"/></svg>
				GPX
			</a>
			<a class="tsr-md__btn" id="tsr-modal-kml" href="#" download hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-download"/></svg>
				KML
			</a>
			<a class="tsr-md__btn tsr-md__btn--strava" id="tsr-modal-strava" href="#" target="_blank" rel="noopener" hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-strava"/></svg>
				Strava
			</a>
			<a class="tsr-md__btn tsr-md__btn--garmin" id="tsr-modal-garmin" href="#" target="_blank" rel="noopener" hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-garmin"/></svg>
				Garmin
			</a>
			<span class="tsr-md__sp"></span>
			<button class="tsr-md__btn" id="tsr-modal-copy" type="button">
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><use href="#tsr-i-link"/></svg>
				<span>Копирай линк</span>
			</button>
			<a class="tsr-md__btn" id="tsr-modal-results-link" href="#" hidden></a>
			<a class="tsr-md__btn" id="tsr-modal-race" href="#" hidden>За състезанието ›</a>
		</div>
	</div>
</div>

<?php get_footer(); ?>
