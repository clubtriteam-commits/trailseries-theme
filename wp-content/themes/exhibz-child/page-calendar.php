<?php
declare( strict_types=1 );
/**
 * Template Name: Календар
 *
 * Template for the Календар page (slug: calendar).
 *
 * Two sections of cards (design pass 2, owner-approved mockup 2026-11-13):
 *   Upcoming — ajde_events with evcal_srow >= now, soonest first; each card
 *              shows "след N дни" and links to the event page.
 *   Past     — this calendar year's events, latest first; each card is
 *              labelled "преминало" and links to the race's results when
 *              they are published (the event page otherwise).
 *
 * Card: photo (or a navy card with the day as a watermark), date pill,
 * name, place · start time, distances from data/tracks.json.
 *
 * Times: EventON stores the wall-clock time the organiser typed as a UTC
 * timestamp, so dates and times are formatted with gmdate().
 *
 * Pure WP_Query — no EventON shortcodes.
 *
 * @package exhibz-child
 */

$tsr_now        = time();
$tsr_year_start = gmmktime( 0, 0, 0, 1, 1, (int) gmdate( 'Y' ) );

$tsr_query = static function ( array $meta_query, string $order ): array {
	$q = new WP_Query(
		array(
			'post_type'      => 'ajde_events',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_key'       => 'evcal_srow',
			'orderby'        => 'meta_value_num',
			'order'          => $order,
			'meta_query'     => $meta_query,
			'no_found_rows'  => true,
		)
	);
	$cards = array();
	while ( $q->have_posts() ) {
		$q->the_post();
		$cards[] = tsr_cal_card_data( get_the_ID(), 'DESC' === $order );
	}
	wp_reset_postdata();
	return $cards;
};

$tsr_upcoming = $tsr_query(
	array(
		array(
			'key'     => 'evcal_srow',
			'value'   => $tsr_now,
			'compare' => '>=',
			'type'    => 'NUMERIC',
		),
	),
	'ASC'
);

$tsr_past = $tsr_query(
	array(
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
	'DESC'
);

$tsr_count_label = static fn( int $n ): string => sprintf( '%d %s', $n, 1 === $n ? 'състезание' : 'състезания' );

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

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Календар' ); ?>

		<!-- ─── Upcoming ───────────────────────────────────────────────────── -->
		<section class="tsr-cal-upcoming" aria-labelledby="tsr-cal-upcoming-h">
			<h2 class="tsr-cal-section__title" id="tsr-cal-upcoming-h">Предстоящи</h2>

			<?php if ( array() !== $tsr_upcoming ) : ?>
				<p class="tsr-cal-count">
					<?php
					echo esc_html( $tsr_count_label( count( $tsr_upcoming ) ) . ' · следващото е ' . tsr_in_days( (int) $tsr_upcoming[0]['days'] ) );
					?>
				</p>
				<ul class="tsr-cal-cards">
					<?php
					foreach ( $tsr_upcoming as $tsr_c ) {
						tsr_cal_card_print( $tsr_c, false );
					}
					?>
				</ul>
			<?php else : ?>
				<p class="tsr-empty">Няма предстоящи събития в момента &mdash; следете за обновления!</p>
			<?php endif; ?>
		</section>

		<!-- ─── Past (current year) ────────────────────────────────────────── -->
		<section class="tsr-cal-past" aria-labelledby="tsr-cal-past-h">
			<h2 class="tsr-cal-section__title tsr-cal-section__title--past" id="tsr-cal-past-h">Изминали тази година</h2>

			<?php if ( array() !== $tsr_past ) : ?>
				<p class="tsr-cal-count"><?php echo esc_html( $tsr_count_label( count( $tsr_past ) ) ); ?></p>
				<ul class="tsr-cal-cards">
					<?php
					foreach ( $tsr_past as $tsr_c ) {
						tsr_cal_card_print( $tsr_c, true );
					}
					?>
				</ul>
			<?php else : ?>
				<p class="tsr-empty">Няма изминали събития тази година.</p>
			<?php endif; ?>
		</section>

	</div>
</main>

<?php get_footer(); ?>
