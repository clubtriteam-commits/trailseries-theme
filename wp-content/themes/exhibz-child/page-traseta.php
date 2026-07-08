<?php
/**
 * Template Name: Трасета
 *
 * Template for the Трасета page (slug: traseta).
 *
 * Renders all race tracks grouped by event, from data/tracks.json (built by
 * migration/build_tracks_theme_data.py from the drace.bg GPX migration).
 *
 * Each track row: name, distance, D+ / D-, highest/lowest point, difficulty
 * stars (1-5, km-effort = distance_km + D+/100), and a GPX download link
 * served from the theme's /gpx/ directory.
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
					<section class="tsr-track-group">
						<h2 class="tsr-track-group__title"><?php echo esc_html( $tsr_event['name'] ); ?></h2>

						<ul class="tsr-track-list">
							<?php foreach ( $tsr_event['tracks'] as $tsr_tr ) : ?>
								<li class="tsr-track">

									<div class="tsr-track__head">
										<span class="tsr-track__name"><?php echo esc_html( $tsr_tr['title'] ); ?></span>
										<?php echo tsr_star_rating( isset( $tsr_tr['stars'] ) ? (int) $tsr_tr['stars'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from ints + esc_attr. ?>
									</div>

									<div class="tsr-track__meta">
										<?php if ( ! empty( $tsr_tr['distance_km'] ) ) : ?>
											<span class="tsr-track__stat">
												<span class="tsr-track__stat-label">Дистанция</span>
												<span class="tsr-track__stat-value"><?php echo esc_html( number_format_i18n( (float) $tsr_tr['distance_km'], 1 ) ); ?> км</span>
											</span>
										<?php endif; ?>

										<?php if ( isset( $tsr_tr['ascent_m'] ) && null !== $tsr_tr['ascent_m'] ) : ?>
											<span class="tsr-track__stat">
												<span class="tsr-track__stat-label">Изкачване</span>
												<span class="tsr-track__stat-value">D+ <?php echo esc_html( number_format_i18n( (int) $tsr_tr['ascent_m'] ) ); ?> м</span>
											</span>
										<?php endif; ?>

										<?php if ( isset( $tsr_tr['descent_m'] ) && null !== $tsr_tr['descent_m'] ) : ?>
											<span class="tsr-track__stat">
												<span class="tsr-track__stat-label">Спускане</span>
												<span class="tsr-track__stat-value">D- <?php echo esc_html( number_format_i18n( (int) $tsr_tr['descent_m'] ) ); ?> м</span>
											</span>
										<?php endif; ?>

										<?php if ( isset( $tsr_tr['highest_m'], $tsr_tr['lowest_m'] ) && null !== $tsr_tr['highest_m'] && null !== $tsr_tr['lowest_m'] ) : ?>
											<span class="tsr-track__stat">
												<span class="tsr-track__stat-label">Височина</span>
												<span class="tsr-track__stat-value"><?php echo esc_html( number_format_i18n( (int) $tsr_tr['lowest_m'] ) ); ?>&ndash;<?php echo esc_html( number_format_i18n( (int) $tsr_tr['highest_m'] ) ); ?> м</span>
											</span>
										<?php endif; ?>
									</div>

									<?php if ( ! empty( $tsr_tr['gpx_file'] ) ) : ?>
										<a class="tsr-track__gpx"
										   href="<?php echo esc_url( $tsr_gpx_base . $tsr_tr['gpx_file'] ); ?>"
										   download>
											<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
											GPX
										</a>
									<?php endif; ?>

								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endforeach; ?>

			</div><!-- .tsr-tracks-archive -->

		<?php endif; ?>

	</div>
</main>

<?php get_footer(); ?>
