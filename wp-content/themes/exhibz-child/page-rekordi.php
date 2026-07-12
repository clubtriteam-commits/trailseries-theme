<?php
declare( strict_types=1 );
/**
 * Template Name: Рекорди
 *
 * Template for the Рекорди page (slug: rekordi).
 *
 * Course records — fastest recorded finish time per event name + distance.
 *
 * The record search groups ts_result posts by the meta value
 * `_tsr_event_base` (canonical event name, e.g. "Иран Ран") and
 * `_tsr_distance_km` (populated by `wp tsr backfill-meta`). When that meta
 * is absent the post title is used as a fallback grouping key, which is
 * less reliable but still functional.
 *
 * Row access uses the STORAGE keys of `_tsr_result_set` rows — snake_case
 * per TSR_Schema::CORE_COLUMNS ('status', 'finish_time', 'first_name',
 * 'last_name'), status code 'FIN'. The display labels ("Status",
 * "Finish Time", …) are NOT row keys; reading them silently matches
 * nothing and leaves the page permanently empty.
 *
 * Record year comes from `_tsr_season` meta — NEVER from post_date, which
 * is the import date, not the race year (see page-rezultati.php).
 *
 * @package exhibz-child
 */

get_header();

// tsr_time_to_seconds() comes from the trailseries-results plugin
// (includes/event-heuristics.php) — the local copy that also accepted
// "M:SS" is gone; TSR_Schema::TIME_PATTERN guarantees stored finish
// times are H:MM:SS, so the laxer parse could only misread bad data.
// (A tsr_seconds_to_time() formatter defined here was never called
// anywhere and was removed outright.)

// ── Scan all published ts_result posts for fastest finish times ───────────────

/**
 * Records array shape:
 *   $records[ 'Event Base Name' ][ distance_km_or_label ] = array(
 *     'time_sec' => int,
 *     'time_str' => string,
 *     'name'     => string,
 *     'year'     => int,
 *     'post_id'  => int,
 *   )
 *
 * @var array<string, array<string, array{time_sec:int, time_str:string, name:string, year:int, post_id:int}>>
 */
$records = array();

// Transient-cache the scan for 6 hours (records rarely change). The key
// embeds the results cache generation (tsr_cache_gen(), functions.php),
// so any results write — import, admin edit, post deletion — invalidates
// it without waiting out the TTL.
$tsr_records_key = 'tsr_course_records_g' . tsr_cache_gen();
$cached          = get_transient( $tsr_records_key );
if ( false !== $cached ) {
	$records = $cached;
} else {
	$all_posts = get_posts(
		array(
			'post_type'   => 'ts_result',
			'numberposts' => -1,
			'post_status' => 'publish',
			'fields'      => 'ids',
		)
	);

	foreach ( $all_posts as $pid ) {
		// Grouping key: prefer explicit meta, fall back to post title.
		$event_base  = (string) ( get_post_meta( $pid, '_tsr_event_base', true ) ?: get_the_title( $pid ) );
		$distance_km = (string) ( get_post_meta( $pid, '_tsr_distance_km', true ) ?: '' );
		$dist_key    = '' !== $distance_km ? $distance_km . ' км' : 'н/д';
		$year        = (int) get_post_meta( $pid, '_tsr_season', true );

		// Load stored result set JSON.
		$json_raw = get_post_meta( $pid, '_tsr_result_set', true );
		if ( ! is_string( $json_raw ) || '' === $json_raw ) {
			continue;
		}
		$data = json_decode( $json_raw, true );
		if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
			continue;
		}

		foreach ( $data['rows'] as $row ) {
			// Only confirmed finishers hold records — DNF/DSQ rows can carry
			// a time yet must never beat a finisher, so filter on status.
			if ( ! is_array( $row ) || 'FIN' !== ( $row['status'] ?? '' ) ) {
				continue;
			}

			$time_str = trim( (string) ( $row['finish_time'] ?? '' ) );
			if ( '' === $time_str ) {
				continue;
			}

			$time_sec = tsr_time_to_seconds( $time_str );
			if ( PHP_INT_MAX === $time_sec ) {
				continue;
			}

			$name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );

			if (
				! isset( $records[ $event_base ][ $dist_key ] ) ||
				$time_sec < $records[ $event_base ][ $dist_key ]['time_sec']
			) {
				$records[ $event_base ][ $dist_key ] = array(
					'time_sec' => $time_sec,
					'time_str' => $time_str,
					'name'     => $name,
					'year'     => $year,
					'post_id'  => $pid,
				);
			}
		}
	}

	ksort( $records );
	set_transient( $tsr_records_key, $records, 6 * HOUR_IN_SECONDS );
}
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Рекорди на трасетата</h1>
		<p class="tsr-page-hero__subtitle">
			Най-бързи времена по трасе и дистанция — всички сезони
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Рекорди' ); ?>

		<?php if ( empty( $records ) ) : ?>
			<section class="tsr-prose-section">
				<p class="tsr-empty">
					Все още няма импортирани резултати или рекордите се изчисляват.
					Опитайте отново след малко.
				</p>
			</section>
		<?php else : ?>

			<section class="tsr-prose-section">
				<p>
					Рекорд = най-бързо регистрирано финишно време за дадено трасе и
					дистанция. Едно и също име на събитие се счита за едно и също
					трасе, независимо от годината.
				</p>
			</section>

			<?php foreach ( $records as $event_name => $distances ) :
				ksort( $distances );
				?>
				<section class="tsr-records-event">
					<h2 class="tsr-records-event__title">
						<?php echo esc_html( $event_name ); ?>
					</h2>
					<div class="tsr-results-wrap">
						<table class="tsr-results">
							<thead>
								<tr>
									<th>Дистанция</th>
									<th>Рекордно време</th>
									<th>Атлет</th>
									<th>Година</th>
									<th>Резултати</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $distances as $dist_label => $rec ) : ?>
									<tr>
										<td><?php echo esc_html( $dist_label ); ?></td>
										<td><strong><?php echo esc_html( $rec['time_str'] ); ?></strong></td>
										<td><?php echo esc_html( $rec['name'] ); ?></td>
										<td><?php echo $rec['year'] > 0 ? esc_html( (string) $rec['year'] ) : '—'; ?></td>
										<td>
											<a href="<?php echo esc_url( get_permalink( $rec['post_id'] ) ); ?>">
												виж
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endforeach; ?>

		<?php endif; ?>

	</div>
</main>

<?php get_footer(); ?>
