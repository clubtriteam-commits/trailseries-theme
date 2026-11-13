<?php
declare( strict_types=1 );
/**
 * Single calendar event (EventON `ajde_events`) — the site's own layout.
 *
 * Replaces EventON's single-event card (white-on-grey header, English
 * labels, empty "Event Details", "GMT+00:00"). Loaded through the
 * template_include filter in functions.php, which runs after EventON's own
 * template loader. Layout chosen by the owner 2026-11-13: photo hero with
 * date badge, three fact cards (Дата / Час / Място), the race's tracks.
 *
 * Times: EventON stores the wall-clock time the organiser typed ("11:00")
 * as a UTC timestamp (_evo_tz = UTC), so it is formatted with gmdate(),
 * never wp_date() — that would shift it once the site timezone is set to
 * Europe/Sofia.
 *
 * @package exhibz-child
 */

get_header();

while ( have_posts() ) :
	the_post();

	$tsr_ev_id    = get_the_ID();
	$tsr_ev_title = html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' );
	$tsr_start    = (int) get_post_meta( $tsr_ev_id, 'evcal_srow', true );
	$tsr_end      = (int) get_post_meta( $tsr_ev_id, 'evcal_erow', true );
	$tsr_hide_end = 'yes' === get_post_meta( $tsr_ev_id, 'evo_hide_endtime', true );
	$tsr_location = trim( (string) get_post_meta( $tsr_ev_id, 'evcal_location_raw', true ) );
	$tsr_base     = tsr_event_base_name( $tsr_ev_title );

	$tsr_months_short = array( 1 => 'ян.', 'февр.', 'март', 'апр.', 'май', 'юни', 'юли', 'авг.', 'септ.', 'окт.', 'ноем.', 'дек.' );
	$tsr_months       = array( 1 => 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември' );
	$tsr_weekdays     = array( 'неделя', 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота' );

	$tsr_date_long = $tsr_start > 0
		? $tsr_weekdays[ (int) gmdate( 'w', $tsr_start ) ] . ', ' . gmdate( 'j', $tsr_start ) . ' ' . $tsr_months[ (int) gmdate( 'n', $tsr_start ) ] . ' ' . gmdate( 'Y', $tsr_start )
		: '';
	$tsr_time = $tsr_start > 0 ? gmdate( 'H:i', $tsr_start ) : '';
	if ( '' !== $tsr_time && ! $tsr_hide_end && $tsr_end > $tsr_start && gmdate( 'Y-m-d', $tsr_end ) === gmdate( 'Y-m-d', $tsr_start ) ) {
		$tsr_time .= '–' . gmdate( 'H:i', $tsr_end );
	}

	// Description: EventON saves an empty placeholder paragraph by default.
	$tsr_desc = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $tsr_ev_id ) ) );

	// Tracks for this race (same data and status rules as /traseta/),
	// longest first (ADR-004).
	$tsr_tracks = array();
	foreach ( tsr_tracks_events() as $tsr_te ) {
		if ( '' !== $tsr_base && 0 === strcasecmp( (string) ( $tsr_te['name'] ?? '' ), $tsr_base ) ) {
			foreach ( (array) ( $tsr_te['tracks'] ?? array() ) as $tsr_tr ) {
				if ( 'current' === tsr_track_status( $tsr_tr ) ) {
					$tsr_tracks[] = $tsr_tr;
				}
			}
		}
	}
	usort( $tsr_tracks, static fn( array $a, array $b ): int => ( (float) ( $b['distance_km'] ?? 0 ) ) <=> ( (float) ( $a['distance_km'] ?? 0 ) ) );
	$tsr_gpx_base = get_stylesheet_directory_uri() . '/gpx/';

	// schema.org Event — wall-clock time in Sofia with its real offset.
	if ( $tsr_start > 0 ) {
		$tsr_tz     = new DateTimeZone( 'Europe/Sofia' );
		$tsr_ld_iso = static fn( int $ts ): string => ( new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $ts ), $tsr_tz ) )->format( 'c' );
		$tsr_ld     = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'SportsEvent',
			'name'                => $tsr_ev_title,
			'startDate'           => $tsr_ld_iso( $tsr_start ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'url'                 => get_permalink(),
			'organizer'           => array(
				'@type' => 'Organization',
				'name'  => 'TrailSeries.bg',
				'url'   => home_url( '/' ),
			),
		);
		if ( $tsr_end > $tsr_start ) {
			$tsr_ld['endDate'] = $tsr_ld_iso( $tsr_end );
		}
		if ( '' !== $tsr_location ) {
			$tsr_ld['location'] = array(
				'@type'   => 'Place',
				'name'    => $tsr_location,
				'address' => $tsr_location . ', България',
			);
		}
		if ( has_post_thumbnail() ) {
			$tsr_ld['image'] = array( (string) get_the_post_thumbnail_url( null, 'large' ) );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $tsr_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
	?>

<div class="tsr-ev-hero<?php echo has_post_thumbnail() ? ' tsr-ev-hero--photo' : ''; ?>">
	<?php
	if ( has_post_thumbnail() ) {
		// The hero photo is the LCP element: eager + high priority.
		the_post_thumbnail(
			'full',
			array(
				'class'         => 'tsr-ev-hero__img',
				'alt'           => '',
				'sizes'         => '100vw',
				'loading'       => 'eager',
				'fetchpriority' => 'high',
			)
		);
	}
	?>
	<div class="tsr-container tsr-ev-hero__inner">
		<?php if ( $tsr_start > 0 ) : ?>
			<p class="tsr-ev-hero__date" aria-hidden="true">
				<span class="tsr-ev-hero__day"><?php echo esc_html( gmdate( 'j', $tsr_start ) ); ?></span>
				<span class="tsr-ev-hero__month"><?php echo esc_html( $tsr_months_short[ (int) gmdate( 'n', $tsr_start ) ] ); ?></span>
			</p>
		<?php endif; ?>
		<div class="tsr-ev-hero__text">
			<p class="tsr-page-hero__kicker">Състезание</p>
			<h1 class="tsr-ev-hero__title"><?php echo esc_html( $tsr_ev_title ); ?></h1>
		</div>
	</div>
</div>

<main id="main" class="tsr-page-content tsr-ev">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( $tsr_ev_title, array( array( 'label' => 'Календар', 'url' => home_url( '/calendar/' ) ) ) ); ?>

		<dl class="tsr-ev-facts">
			<?php if ( '' !== $tsr_date_long ) : ?>
				<div class="tsr-ev-fact">
					<dt class="tsr-ev-fact__label">Дата</dt>
					<dd class="tsr-ev-fact__value">
						<time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $tsr_start ) ); ?>"><?php echo esc_html( $tsr_date_long ); ?></time>
					</dd>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $tsr_time ) : ?>
				<div class="tsr-ev-fact">
					<dt class="tsr-ev-fact__label">Час</dt>
					<dd class="tsr-ev-fact__value"><?php echo esc_html( $tsr_time ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $tsr_location ) : ?>
				<div class="tsr-ev-fact">
					<dt class="tsr-ev-fact__label">Място</dt>
					<dd class="tsr-ev-fact__value">
						<?php echo esc_html( $tsr_location ); ?>
						<a class="tsr-ev-fact__link" href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $tsr_location ) ); ?>" target="_blank" rel="noopener">Виж на картата<span class="screen-reader-text"> (Google Maps, нов прозорец)</span> ›</a>
					</dd>
				</div>
			<?php endif; ?>
		</dl>

		<?php if ( '' !== $tsr_desc ) : ?>
			<div class="tsr-ev-desc tsr-single-content">
				<?php echo wp_kses_post( do_blocks( (string) get_post_field( 'post_content', $tsr_ev_id ) ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $tsr_tracks ) ) : ?>
			<section class="tsr-ev-tracks" aria-labelledby="tsr-ev-tracks-title">
				<h2 class="tsr-ev-tracks__title" id="tsr-ev-tracks-title">Трасета</h2>
				<ul class="tsr-ev-track-list">
					<?php foreach ( $tsr_tracks as $tsr_tr ) : ?>
						<?php $tsr_laps = tsr_track_laps( $tsr_tr ); ?>
						<li class="tsr-ev-track">
							<h3 class="tsr-ev-track__name"><?php echo esc_html( (string) ( $tsr_tr['variant'] ?? $tsr_tr['title'] ?? '' ) ); ?></h3>
							<dl class="tsr-ev-track__stats">
								<?php if ( ! empty( $tsr_tr['distance_km'] ) ) : ?>
									<div><dt>Дистанция</dt><dd><?php echo esc_html( number_format_i18n( (float) $tsr_tr['distance_km'], 1 ) ); ?> км<?php echo $tsr_laps > 1 ? ' · ' . esc_html( (string) $tsr_laps ) . ' обиколки' : ''; ?></dd></div>
								<?php endif; ?>
								<?php if ( isset( $tsr_tr['ascent_m'] ) && null !== $tsr_tr['ascent_m'] ) : ?>
									<div><dt>Изкачване</dt><dd>D+ <?php echo esc_html( number_format_i18n( (int) $tsr_tr['ascent_m'] ) ); ?> м</dd></div>
								<?php endif; ?>
								<?php if ( isset( $tsr_tr['highest_m'], $tsr_tr['lowest_m'] ) && null !== $tsr_tr['highest_m'] && null !== $tsr_tr['lowest_m'] ) : ?>
									<div><dt>Височина</dt><dd><?php echo esc_html( number_format_i18n( (int) $tsr_tr['lowest_m'] ) . '–' . number_format_i18n( (int) $tsr_tr['highest_m'] ) ); ?> м</dd></div>
								<?php endif; ?>
							</dl>
							<?php if ( ! empty( $tsr_tr['gpx_file'] ) ) : ?>
								<a class="tsr-ev-track__gpx" href="<?php echo esc_url( $tsr_gpx_base . $tsr_tr['gpx_file'] ); ?>" download>GPX<span class="screen-reader-text"> — <?php echo esc_html( (string) ( $tsr_tr['variant'] ?? '' ) ); ?></span></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="tsr-ev-tracks__more">
					<a href="<?php echo esc_url( home_url( '/traseta/' ) ); ?>">Карта и профил на трасетата ›</a>
				</p>
			</section>
		<?php endif; ?>

	</div>
</main>

	<?php
endwhile;

get_footer();
