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
$tsr_today      = strtotime( wp_date( 'Y-m-d', $tsr_now, new DateTimeZone( 'Europe/Sofia' ) ) . ' UTC' );

/**
 * Card data for one event post (call inside the loop).
 *
 * @return array<string, mixed>
 */
$tsr_cal_card = static function ( bool $past ) use ( $tsr_today ): array {
	$id    = get_the_ID();
	$title = html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' );
	$ts    = (int) get_post_meta( $id, 'evcal_srow', true );
	$base  = tsr_event_base_name( $title );
	$url   = (string) get_permalink();

	if ( $past ) {
		$year        = tsr_title_year( $title ) ?? (int) gmdate( 'Y', $ts );
		$results_url = tsr_event_results_url( $base, $year );
		if ( '' !== $results_url ) {
			$url = $results_url;
		}
	}

	return array(
		'title'    => $title,
		'url'      => $url,
		'ts'       => $ts,
		'days'     => (int) round( ( strtotime( gmdate( 'Y-m-d', $ts ) . ' UTC' ) - $tsr_today ) / DAY_IN_SECONDS ),
		'location' => trim( (string) get_post_meta( $id, 'evcal_location_raw', true ) ),
		'image'    => tsr_card_image( $id, 'tsr-cal-card__img', '(max-width: 600px) calc(100vw - 2.5rem), (max-width: 1100px) 50vw, 280px' ),
		'dist'     => tsr_event_distance_labels( $base ),
		'results'  => $past && isset( $results_url ) && '' !== $results_url,
	);
};

$tsr_query = static function ( array $meta_query, string $order ) use ( $tsr_cal_card ): array {
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
		$cards[] = $tsr_cal_card( 'DESC' === $order );
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

/** "след 30 дни" / "утре" / "днес". */
$tsr_in_days = static function ( int $days ): string {
	if ( $days <= 0 ) {
		return 'днес';
	}
	return 1 === $days ? 'утре' : sprintf( 'след %d дни', $days );
};

/**
 * Print one card.
 *
 * @param array<string, mixed> $c    Card data.
 * @param bool                 $past Past event (label + results link).
 */
$tsr_print_card = static function ( array $c, bool $past ) use ( $tsr_in_days ): void {
	$classes = 'tsr-cal-card' . ( '' !== $c['image'] ? ' tsr-cal-card--photo' : '' ) . ( $past ? ' tsr-cal-card--past' : '' );
	$time    = gmdate( 'H:i', $c['ts'] );
	$meta    = trim( $c['location'] . ( '' !== $c['location'] ? ' · ' : '' ) . $time );
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
				<?php echo esc_html( $past ? 'преминало' : $tsr_in_days( (int) $c['days'] ) ); ?>
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
};

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
					echo esc_html( $tsr_count_label( count( $tsr_upcoming ) ) . ' · следващото е ' . $tsr_in_days( (int) $tsr_upcoming[0]['days'] ) );
					?>
				</p>
				<ul class="tsr-cal-cards">
					<?php
					foreach ( $tsr_upcoming as $tsr_c ) {
						$tsr_print_card( $tsr_c, false );
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
						$tsr_print_card( $tsr_c, true );
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
