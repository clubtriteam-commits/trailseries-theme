<?php
/**
 * Template Name: Календар
 *
 * Template for the Календар page (slug: calendar).
 *
 * Two sections:
 *   Upcoming — ajde_events with evcal_srow >= now, ordered ASC, grouped by month.
 *   Past     — ajde_events with evcal_srow < now and >= Jan 1 current year, DESC.
 *
 * Pure WP_Query — no EventON shortcodes.
 *
 * @package exhibz-child
 */

$tsr_now        = time();
$tsr_year_start = mktime( 0, 0, 0, 1, 1, (int) gmdate( 'Y' ) );

// ── Upcoming events ───────────────────────────────────────────────────────────

$tsr_upcoming_q = new WP_Query(
	array(
		'post_type'      => 'ajde_events',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_key'       => 'evcal_srow',
		'orderby'        => 'meta_value_num',
		'order'          => 'ASC',
		'meta_query'     => array(
			array(
				'key'     => 'evcal_srow',
				'value'   => $tsr_now,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		),
		'no_found_rows'  => true,
	)
);

$tsr_upcoming = array();
while ( $tsr_upcoming_q->have_posts() ) {
	$tsr_upcoming_q->the_post();
	$tsr_id  = get_the_ID();
	$tsr_ts  = (int) get_post_meta( $tsr_id, 'evcal_srow', true );
	$tsr_key = date_i18n( 'F Y', $tsr_ts );
	$tsr_upcoming[ $tsr_key ][] = array(
		'title'    => get_the_title(),
		'url'      => get_permalink(),
		'ts'       => $tsr_ts,
		'location'      => (string) ( get_post_meta( $tsr_id, 'evcal_location_raw', true ) ?: '' ),
		'thumbnail_url' => (string) ( get_the_post_thumbnail_url( $tsr_id, 'thumbnail' ) ?: '' ),
	);
}
wp_reset_postdata();

// ── Past events (current year) ────────────────────────────────────────────────

$tsr_past_q = new WP_Query(
	array(
		'post_type'      => 'ajde_events',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_key'       => 'evcal_srow',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'evcal_srow',
				'value'   => $tsr_now,
				'compare' => '<',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => 'evcal_srow',
				'value'   => $tsr_year_start,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		),
		'no_found_rows'  => true,
	)
);

$tsr_past = array();
while ( $tsr_past_q->have_posts() ) {
	$tsr_past_q->the_post();
	$tsr_id  = get_the_ID();
	$tsr_ts  = (int) get_post_meta( $tsr_id, 'evcal_srow', true );
	$tsr_key = date_i18n( 'F Y', $tsr_ts );
	$tsr_past[ $tsr_key ][] = array(
		'title'    => get_the_title(),
		'url'      => get_permalink(),
		'ts'       => $tsr_ts,
		'location' => (string) ( get_post_meta( $tsr_id, 'evcal_location_raw', true ) ?: '' ),
	);
}
wp_reset_postdata();

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Календар</h1>
		<p class="tsr-page-hero__subtitle">
			Предстоящи състезания от Trail Series — дати и локации
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<!-- ─── Upcoming ───────────────────────────────────────────────────── -->
		<section class="tsr-prose-section tsr-cal-upcoming" aria-labelledby="tsr-cal-upcoming-h">
			<h2 class="tsr-cal-section__title" id="tsr-cal-upcoming-h">Предстоящи</h2>

			<?php if ( ! empty( $tsr_upcoming ) ) : ?>
				<?php foreach ( $tsr_upcoming as $tsr_month => $tsr_events ) : ?>
					<div class="tsr-cal-month">
						<h3 class="tsr-cal-month__heading"><?php echo esc_html( $tsr_month ); ?></h3>
						<ul class="tsr-cal-event-list">
							<?php foreach ( $tsr_events as $tsr_ev ) : ?>
								<li class="tsr-cal-event">
									<?php if ( '' !== $tsr_ev['thumbnail_url'] ) : ?>
									<img class="tsr-cal-event__thumb"
									     src="<?php echo esc_url( $tsr_ev['thumbnail_url'] ); ?>"
									     alt=""
									     width="80" height="80"
									     loading="lazy" decoding="async">
									<?php endif; ?>
									<div class="tsr-cal-event__date" aria-hidden="true">
										<span class="tsr-cal-event__day"><?php echo esc_html( date_i18n( 'j', $tsr_ev['ts'] ) ); ?></span>
										<span class="tsr-cal-event__mon"><?php echo esc_html( date_i18n( 'M', $tsr_ev['ts'] ) ); ?></span>
									</div>
									<div class="tsr-cal-event__body">
										<a class="tsr-cal-event__title"
										   href="<?php echo esc_url( $tsr_ev['url'] ); ?>">
											<?php echo esc_html( $tsr_ev['title'] ); ?>
										</a>
										<?php if ( '' !== $tsr_ev['location'] ) : ?>
											<p class="tsr-cal-event__location">
												<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
												<?php echo esc_html( $tsr_ev['location'] ); ?>
											</p>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="tsr-empty">Няма предстоящи събития в момента &mdash; следете за обновления!</p>
			<?php endif; ?>
		</section>

		<hr class="tsr-cal-divider">

		<!-- ─── Past (current year) ────────────────────────────────────────── -->
		<section class="tsr-prose-section tsr-cal-past" aria-labelledby="tsr-cal-past-h">
			<h2 class="tsr-cal-section__title tsr-cal-section__title--past" id="tsr-cal-past-h">Изминали тази година</h2>

			<?php if ( ! empty( $tsr_past ) ) : ?>
				<?php foreach ( $tsr_past as $tsr_month => $tsr_events ) : ?>
					<div class="tsr-cal-month">
						<h3 class="tsr-cal-month__heading"><?php echo esc_html( $tsr_month ); ?></h3>
						<ul class="tsr-cal-event-list">
							<?php foreach ( $tsr_events as $tsr_ev ) : ?>
								<li class="tsr-cal-event tsr-cal-event--past">
									<div class="tsr-cal-event__date" aria-hidden="true">
										<span class="tsr-cal-event__day"><?php echo esc_html( date_i18n( 'j', $tsr_ev['ts'] ) ); ?></span>
										<span class="tsr-cal-event__mon"><?php echo esc_html( date_i18n( 'M', $tsr_ev['ts'] ) ); ?></span>
									</div>
									<div class="tsr-cal-event__body">
										<a class="tsr-cal-event__title"
										   href="<?php echo esc_url( $tsr_ev['url'] ); ?>">
											<?php echo esc_html( $tsr_ev['title'] ); ?>
										</a>
										<?php if ( '' !== $tsr_ev['location'] ) : ?>
											<p class="tsr-cal-event__location">
												<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
												<?php echo esc_html( $tsr_ev['location'] ); ?>
											</p>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="tsr-empty">Няма изминали събития тази година.</p>
			<?php endif; ?>
		</section>

	</div>
</main>

<?php get_footer(); ?>
