<?php
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
 * Render one track row (<li>).
 *
 * @param array<string, mixed> $tr       Track entry from tracks.json.
 * @param string               $gpx_base Base URL of the theme /gpx/ directory.
 */
if ( ! function_exists( 'tsr_track_row' ) ) {
function tsr_track_row( array $tr, string $gpx_base ): void {
	$is_legacy = ( 'legacy' === ( $tr['status'] ?? 'current' ) );
	?>
	<li class="tsr-track<?php echo $is_legacy ? ' tsr-track--legacy' : ''; ?>">

		<div class="tsr-track__head">
			<span class="tsr-track__name"><?php echo esc_html( $tr['title'] ); ?></span>
			<?php if ( $is_legacy ) : ?>
				<span class="tsr-track__badge tsr-track__badge--legacy">Легаси</span>
			<?php else : ?>
				<span class="tsr-track__badge tsr-track__badge--current">Актуално</span>
			<?php endif; ?>
			<?php echo tsr_star_rating( isset( $tr['stars'] ) ? (int) $tr['stars'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from ints + esc_attr. ?>
		</div>

		<div class="tsr-track__meta">
			<?php if ( ! empty( $tr['distance_km'] ) ) : ?>
				<span class="tsr-track__stat">
					<span class="tsr-track__stat-label">Дистанция</span>
					<span class="tsr-track__stat-value"><?php echo esc_html( number_format_i18n( (float) $tr['distance_km'], 1 ) ); ?> км</span>
				</span>
			<?php endif; ?>

			<?php if ( isset( $tr['ascent_m'] ) && null !== $tr['ascent_m'] ) : ?>
				<span class="tsr-track__stat">
					<span class="tsr-track__stat-label">Изкачване</span>
					<span class="tsr-track__stat-value">D+ <?php echo esc_html( number_format_i18n( (int) $tr['ascent_m'] ) ); ?> м</span>
				</span>
			<?php endif; ?>

			<?php if ( isset( $tr['descent_m'] ) && null !== $tr['descent_m'] ) : ?>
				<span class="tsr-track__stat">
					<span class="tsr-track__stat-label">Спускане</span>
					<span class="tsr-track__stat-value">D- <?php echo esc_html( number_format_i18n( (int) $tr['descent_m'] ) ); ?> м</span>
				</span>
			<?php endif; ?>

			<?php if ( isset( $tr['highest_m'], $tr['lowest_m'] ) && null !== $tr['highest_m'] && null !== $tr['lowest_m'] ) : ?>
				<span class="tsr-track__stat">
					<span class="tsr-track__stat-label">Височина</span>
					<span class="tsr-track__stat-value"><?php echo esc_html( number_format_i18n( (int) $tr['lowest_m'] ) ); ?>&ndash;<?php echo esc_html( number_format_i18n( (int) $tr['highest_m'] ) ); ?> м</span>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $tr['gpx_file'] ) ) : ?>
			<a class="tsr-track__gpx"
			   href="<?php echo esc_url( $gpx_base . $tr['gpx_file'] ); ?>"
			   download>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
				GPX
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

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php if ( empty( $tsr_events ) ) : ?>
			<p class="tsr-empty">Няма налични трасета в момента.</p>
		<?php else : ?>

			<div class="tsr-tracks-archive">

				<?php foreach ( $tsr_events as $tsr_event ) : ?>
					<?php
					$tsr_current = array();
					$tsr_legacy  = array();
					foreach ( $tsr_event['tracks'] as $tsr_tr ) {
						if ( 'legacy' === ( $tsr_tr['status'] ?? 'current' ) ) {
							$tsr_legacy[] = $tsr_tr;
						} else {
							$tsr_current[] = $tsr_tr;
						}
					}
					?>
					<section class="tsr-track-group">
						<h2 class="tsr-track-group__title"><?php echo esc_html( $tsr_event['name'] ); ?></h2>

						<?php if ( ! empty( $tsr_current ) ) : ?>
							<ul class="tsr-track-list">
								<?php foreach ( $tsr_current as $tsr_tr ) : ?>
									<?php tsr_track_row( $tsr_tr, $tsr_gpx_base ); ?>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( ! empty( $tsr_legacy ) ) : ?>
							<details class="tsr-track-legacy-group">
								<summary class="tsr-track-legacy-group__summary">
									<?php
									/* translators: %d = number of legacy track versions */
									printf( esc_html( _n( 'Стари версии (%d)', 'Стари версии (%d)', count( $tsr_legacy ), 'exhibz-child' ) ), count( $tsr_legacy ) );
									?>
								</summary>
								<ul class="tsr-track-list">
									<?php foreach ( $tsr_legacy as $tsr_tr ) : ?>
										<?php tsr_track_row( $tsr_tr, $tsr_gpx_base ); ?>
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

<?php get_footer(); ?>
