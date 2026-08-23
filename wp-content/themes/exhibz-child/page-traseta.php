<?php
declare( strict_types=1 );
/**
 * Template Name: Трасета
 *
 * Template for the Трасета page (slug: traseta).
 *
 * Renders all race tracks grouped by event, from data/tracks.json (built by
 * migration/build_tracks_theme_data.py from the drace.bg GPX migration).
 *
 * Tracks carry a status: "current" (актуално трасе) or "legacy" (стара
 * версия, superseded by a newer edition). Current tracks render first with a
 * blue badge; legacy tracks sit below in a collapsed <details> with a gray
 * badge.
 *
 * Each track row: name, badge, distance, D+ / D-, highest/lowest point,
 * difficulty stars (1-5, km-effort = distance_km + D+/100), and a GPX
 * download link served from the theme's /gpx/ directory.
 *
 * @package exhibz-child
 */

// ── Load track data ───────────────────────────────────────────────────────────

$tsr_tracks_file = get_stylesheet_directory() . '/data/tracks.json';
$tsr_events      = array();

if ( is_readable( $tsr_tracks_file ) ) {
	$tsr_json = json_decode( (string) file_get_contents( $tsr_tracks_file ), true );
	if ( is_array( $tsr_json ) && ! empty( $tsr_json['events'] ) ) {
		$tsr_events = $tsr_json['events'];
	}
}

// Event groups (H2 headings) in alphabetical order — tracks.json otherwise
// lists them in migration/insertion order, unrelated to display order.
// Current-vs-legacy track order within a group (below) is unaffected.
usort(
	$tsr_events,
	static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] )
);

$tsr_gpx_base = get_stylesheet_directory_uri() . '/gpx/';

/**
 * Render a 1-5 star difficulty rating.
 *
 * @param int|null $stars Star count 1-5, or null when unknown.
 * @return string HTML markup, '' when unknown.
 */
if ( ! function_exists( 'tsr_star_rating' ) ) {
function tsr_star_rating( ?int $stars ): string {
	if ( null === $stars || $stars < 1 ) {
		return '';
	}
	$labels = array(
		1 => 'лесно',
		2 => 'умерено',
		3 => 'средно',
		4 => 'трудно',
		5 => 'много трудно',
	);
	$stars = min( $stars, 5 );
	$label = $labels[ $stars ];
	$html  = '<span class="tsr-stars" role="img" aria-label="'
		. esc_attr( sprintf( 'Трудност: %d от 5 (%s)', $stars, $label ) ) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$html .= '<span class="tsr-star' . ( $i <= $stars ? ' tsr-star--on' : '' ) . '" aria-hidden="true">&#9733;</span>';
	}
	$html .= '</span>';
	return $html;
}
}

/**
 * Inline SVG icon for one stat column. Same 14x14 outline style as the GPX
 * button glyph, colored via currentColor so CSS controls the tint.
 *
 * @param string $key 'distance'|'ascent'|'descent'|'elevation'.
 */
if ( ! function_exists( 'tsr_track_stat_icon' ) ) {
function tsr_track_stat_icon( string $key ): string {
	$paths = array(
		'distance'  => '<circle cx="4" cy="12" r="2"/><circle cx="20" cy="12" r="2"/><path d="M6 12h12" stroke-dasharray="3 3"/>',
		'ascent'    => '<path d="M4 20 20 4M20 4H10M20 4v10"/>',
		'descent'   => '<path d="M4 4 20 20M20 20H10M20 20V10"/>',
		'elevation' => '<path d="M3 20 9 8l4 6 2-3 6 9H3z"/>',
	);
	if ( ! isset( $paths[ $key ] ) ) {
		return '';
	}
	return '<svg class="tsr-track__stat-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"'
		. ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
		. $paths[ $key ] . '</svg>';
}
}

/**
 * Render one track row (<li>).
 *
 * Rows carry data attributes consumed by js/traseta-modal.js: clicking a
 * row (or Enter/Space when focused) opens the detail modal with a Leaflet
 * map and elevation profile parsed from the local GPX file.
 *
 * @param array<string, mixed> $tr         Track entry from tracks.json.
 * @param string               $gpx_base   Base URL of the theme /gpx/ directory.
 * @param string               $event_name Parent event's display name (e.g. "7 Hills
 *                                         Run") — the modal splits it off $tr['title']
 *                                         to style the trailing distance/variant apart
 *                                         from the event name.
 */
if ( ! function_exists( 'tsr_track_row' ) ) {
function tsr_track_row( array $tr, string $gpx_base, string $event_name ): void {
	$is_legacy = ( 'legacy' === tsr_track_status( $tr ) );
	?>
	<li class="tsr-track<?php echo $is_legacy ? ' tsr-track--legacy' : ''; ?>"
	    role="button" tabindex="0" aria-haspopup="dialog"
	    aria-label="<?php echo esc_attr( $tr['title'] . ' — детайли за трасето' ); ?>"
	    data-title="<?php echo esc_attr( $tr['title'] ); ?>"
	    data-variant="<?php echo esc_attr( $tr['variant'] ?? '' ); ?>"
	    data-event="<?php echo esc_attr( $event_name ); ?>"
	    data-gpx="<?php echo esc_attr( ! empty( $tr['gpx_file'] ) ? $gpx_base . $tr['gpx_file'] : '' ); ?>"
	    data-kml="<?php echo esc_attr( ! empty( $tr['kml_file'] ) ? $gpx_base . $tr['kml_file'] : '' ); ?>"
	    data-strava="<?php echo esc_attr( $tr['strava_route_url'] ?? '' ); ?>"
	    data-distance="<?php echo esc_attr( (string) ( $tr['distance_km'] ?? '' ) ); ?>"
	    data-ascent="<?php echo esc_attr( (string) ( $tr['ascent_m'] ?? '' ) ); ?>"
	    data-descent="<?php echo esc_attr( (string) ( $tr['descent_m'] ?? '' ) ); ?>"
	    data-highest="<?php echo esc_attr( (string) ( $tr['highest_m'] ?? '' ) ); ?>"
	    data-lowest="<?php echo esc_attr( (string) ( $tr['lowest_m'] ?? '' ) ); ?>">

		<div class="tsr-track__head">
			<?php
			// The group heading (H2) already names the event — the row shows
			// only the distinguishing part ("6 км", "Hard Core Edition 26 км").
			// Full title stays in data-title/aria-label for the modal and AT.
			?>
			<span class="tsr-track__name"><?php echo esc_html( $tr['variant'] ?? $tr['title'] ); ?></span>
			<?php if ( $is_legacy ) : ?>
				<span class="tsr-track__badge tsr-track__badge--legacy">Легаси</span>
			<?php else : ?>
				<span class="tsr-track__badge tsr-track__badge--current">Актуално</span>
			<?php endif; ?>
			<?php echo tsr_star_rating( isset( $tr['stars'] ) ? (int) $tr['stars'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from ints + esc_attr. ?>
		</div>

		<?php
		// Always four stat cells, in the same fixed order, even when a value
		// is missing ('—' placeholder) — grid-based alignment (style.css)
		// depends on every row offering the same column layout; conditionally
		// omitting a cell would shift every following column out of line
		// with the rows above and below it.
		$tsr_laps = tsr_track_laps( $tr );
		?>
		<div class="tsr-track__meta">
			<span class="tsr-track__stat">
				<?php echo tsr_track_stat_icon( 'distance' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup, no user input. ?>
				<span class="tsr-track__stat-body">
					<span class="tsr-track__stat-label">Дистанция</span>
					<span class="tsr-track__stat-value">
						<?php echo ! empty( $tr['distance_km'] ) ? esc_html( number_format_i18n( (float) $tr['distance_km'], 1 ) ) . ' км' : '—'; ?>
					</span>
					<?php if ( $tsr_laps > 1 ) : ?>
						<span class="tsr-track__badge tsr-track__badge--laps">
							<?php echo esc_html( $tsr_laps ); ?> обиколки
						</span>
					<?php endif; ?>
				</span>
			</span>

			<span class="tsr-track__stat">
				<?php echo tsr_track_stat_icon( 'ascent' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup, no user input. ?>
				<span class="tsr-track__stat-body">
					<span class="tsr-track__stat-label">Изкачване</span>
					<span class="tsr-track__stat-value">
						<?php echo isset( $tr['ascent_m'] ) && null !== $tr['ascent_m'] ? 'D+ ' . esc_html( number_format_i18n( (int) $tr['ascent_m'] ) ) . ' м' : '—'; ?>
					</span>
				</span>
			</span>

			<span class="tsr-track__stat">
				<?php echo tsr_track_stat_icon( 'descent' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup, no user input. ?>
				<span class="tsr-track__stat-body">
					<span class="tsr-track__stat-label">Спускане</span>
					<span class="tsr-track__stat-value">
						<?php echo isset( $tr['descent_m'] ) && null !== $tr['descent_m'] ? 'D- ' . esc_html( number_format_i18n( (int) $tr['descent_m'] ) ) . ' м' : '—'; ?>
					</span>
				</span>
			</span>

			<span class="tsr-track__stat">
				<?php echo tsr_track_stat_icon( 'elevation' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup, no user input. ?>
				<span class="tsr-track__stat-body">
					<span class="tsr-track__stat-label">Височина</span>
					<span class="tsr-track__stat-value">
						<?php
						echo isset( $tr['highest_m'], $tr['lowest_m'] ) && null !== $tr['highest_m'] && null !== $tr['lowest_m']
							? esc_html( number_format_i18n( (int) $tr['lowest_m'] ) ) . '&ndash;' . esc_html( number_format_i18n( (int) $tr['highest_m'] ) ) . ' м'
							: '—';
						?>
					</span>
				</span>
			</span>
		</div>

		<?php if ( ! empty( $tr['gpx_file'] ) ) : ?>
			<a class="tsr-track__gpx"
			   href="<?php echo esc_url( $gpx_base . $tr['gpx_file'] ); ?>"
			   download>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
				GPX
			</a>
		<?php endif; ?>

		<?php if ( ! empty( $tr['strava_route_url'] ) ) : ?>
			<a class="tsr-track__gpx tsr-track__gpx--strava"
			   href="<?php echo esc_url( $tr['strava_route_url'] ); ?>"
			   target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M13 2 5 14h5l-2 8 9-13h-5l1-7z"/></svg>
				Strava Course
			</a>
		<?php endif; ?>

	</li>
	<?php
}
}

get_header();
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

			<div class="tsr-tracks-archive">

				<?php foreach ( $tsr_events as $tsr_event ) : ?>
					<?php
					// Effective status comes from tsr_track_status() — the admin
					// override (Tools → Трасета — етикети) wins over the JSON default.
					$tsr_current = array();
					$tsr_legacy  = array();
					foreach ( $tsr_event['tracks'] as $tsr_tr ) {
						if ( 'legacy' === tsr_track_status( $tsr_tr ) ) {
							$tsr_legacy[] = $tsr_tr;
						} else {
							$tsr_current[] = $tsr_tr;
						}
					}
					$tsr_by_distance = static function ( array $a, array $b ): int {
						return ( $a['distance_km'] ?? 0 ) <=> ( $b['distance_km'] ?? 0 );
					};
					usort( $tsr_current, $tsr_by_distance );
					usort( $tsr_legacy, $tsr_by_distance );
					?>
					<section class="tsr-track-group">
						<h2 class="tsr-track-group__title"><?php echo esc_html( $tsr_event['name'] ); ?></h2>

						<?php if ( ! empty( $tsr_current ) ) : ?>
							<ul class="tsr-track-list">
								<?php foreach ( $tsr_current as $tsr_tr ) : ?>
									<?php tsr_track_row( $tsr_tr, $tsr_gpx_base, $tsr_event['name'] ); ?>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( ! empty( $tsr_legacy ) ) : ?>
							<?php if ( ! empty( $tsr_current ) ) : ?>
								<hr class="tsr-track-divider">
							<?php endif; ?>
							<details class="tsr-track-legacy-group">
								<summary class="tsr-track-legacy-group__summary">
									<?php
									/* translators: %d = number of legacy track versions */
									printf( esc_html( _n( 'Стари версии (%d)', 'Стари версии (%d)', count( $tsr_legacy ), 'exhibz-child' ) ), count( $tsr_legacy ) );
									?>
								</summary>
								<ul class="tsr-track-list">
									<?php foreach ( $tsr_legacy as $tsr_tr ) : ?>
										<?php tsr_track_row( $tsr_tr, $tsr_gpx_base, $tsr_event['name'] ); ?>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>

			</div><!-- .tsr-tracks-archive -->

		<?php endif; ?>

	</div>
</main>

<!-- Track detail modal — populated by js/traseta-modal.js on row click. -->
<div class="tsr-modal" id="tsr-track-modal" hidden>
	<div class="tsr-modal__overlay" data-close></div>
	<div class="tsr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tsr-modal-title">
		<button class="tsr-modal__close" type="button" data-close aria-label="Затвори">&times;</button>
		<h2 class="tsr-modal__title" id="tsr-modal-title"></h2>
		<!-- Fetch status: spinner while the GPX loads, an error line when it
		     fails — populated/toggled by js/traseta-modal.js. aria-live so
		     screen readers hear the state change without focus moving. -->
		<div class="tsr-modal__status" id="tsr-modal-status" role="status" aria-live="polite" hidden></div>
		<div class="tsr-modal__map" id="tsr-modal-map"></div>
		<div class="tsr-modal__chart-wrap" id="tsr-modal-chart-wrap" hidden>
			<svg class="tsr-modal__chart" id="tsr-modal-chart"
			     viewBox="0 0 800 240" preserveAspectRatio="xMidYMid meet"
			     role="img" aria-label="Профил на изкачването"></svg>
			<div class="tsr-chart-tooltip" id="tsr-modal-chart-tooltip" hidden></div>
		</div>
		<div class="tsr-modal__stats tsr-track__meta" id="tsr-modal-stats"></div>
		<div class="tsr-modal__actions">
			<a class="tsr-track__gpx" id="tsr-modal-gpx" href="#" download hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
				GPX
			</a>
			<a class="tsr-track__gpx tsr-track__gpx--secondary" id="tsr-modal-kml" href="#" download hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
				KML
			</a>
			<a class="tsr-track__gpx tsr-track__gpx--strava" id="tsr-modal-strava" href="#" target="_blank" rel="noopener" hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M13 2 5 14h5l-2 8 9-13h-5l1-7z"/></svg>
				Strava Course
			</a>
		</div>
	</div>
</div>

<?php get_footer(); ?>
