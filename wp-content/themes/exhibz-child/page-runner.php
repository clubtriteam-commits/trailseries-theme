<?php
declare( strict_types=1 );
/**
 * Template Name: Профил на бегач
 *
 * Shows a runner's full history across all TrailSeries races.
 * URL: /runner/?name=Ivan+Ivanov
 *
 * Query: searches every _tsr_result_set JSON for rows whose concatenated
 * first_name + last_name (or reversed) contains the query string.
 *
 * @package exhibz-child
 */

// ── Shared helpers ────────────────────────────────────────────────────────────
//
// tsr_title_year, tsr_slug_year, tsr_event_base_name and
// tsr_dist_label_from_title come from the trailseries-results plugin
// (includes/event-heuristics.php); the guarded private copies are gone —
// this template's tsr_slug_year still split slugs on '--', which
// sanitize_title() collapses before insert, so it ran against raw section
// slugs. tsr_slug_base() (functions.php) resolves the hub base slug instead.

// ── Input ─────────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.Security.NonceVerification
$tsr_runner_query = trim( sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ) );
$tsr_searching    = '' !== $tsr_runner_query;

// ── Data collection ───────────────────────────────────────────────────────────

$tsr_seasons      = array(); // [ year (int) => [ entry, … ] ]
$tsr_total_starts = 0;
$tsr_total_fin    = 0;
$tsr_best_place   = null;
$tsr_first_year   = null;

if ( $tsr_searching ) {
	$tsr_q_lower = mb_strtolower( $tsr_runner_query );

	$tsr_all = get_posts( array(
		'post_type'      => 'ts_result',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	) );

	foreach ( $tsr_all as $tsr_post ) {
		$tsr_raw = get_post_meta( $tsr_post->ID, '_tsr_result_set', true );
		if ( '' === $tsr_raw ) {
			continue;
		}
		$tsr_data = json_decode( $tsr_raw, true );
		if ( ! isset( $tsr_data['rows'] ) || ! is_array( $tsr_data['rows'] ) ) {
			continue;
		}

		$tsr_year  = tsr_title_year( $tsr_post->post_title )
			?? tsr_slug_year( tsr_slug_base( $tsr_post ) )
			?? 0;
		$tsr_event = tsr_event_base_name( $tsr_post->post_title ) ?: $tsr_post->post_title;
		$tsr_dist  = tsr_dist_label_from_title( $tsr_post->post_title );

		foreach ( $tsr_data['rows'] as $tsr_row ) {
			if ( ! is_array( $tsr_row ) ) {
				continue;
			}
			$tsr_fn  = (string) ( $tsr_row['first_name'] ?? '' );
			$tsr_ln  = (string) ( $tsr_row['last_name']  ?? '' );
			$tsr_fl  = mb_strtolower( trim( $tsr_fn . ' ' . $tsr_ln ) );
			$tsr_lf  = mb_strtolower( trim( $tsr_ln . ' ' . $tsr_fn ) );

			if ( false === mb_strpos( $tsr_fl, $tsr_q_lower )
				&& false === mb_strpos( $tsr_lf, $tsr_q_lower ) ) {
				continue;
			}

			$tsr_status = (string) ( $tsr_row['status'] ?? '' );
			$tsr_place  = ( isset( $tsr_row['place'] ) && is_int( $tsr_row['place'] ) )
				? $tsr_row['place']
				: null;
			$tsr_time   = (string) ( $tsr_row['finish_time'] ?? '' );

			$tsr_total_starts++;

			if ( 'FIN' === $tsr_status ) {
				$tsr_total_fin++;
				if ( null !== $tsr_place
					&& ( null === $tsr_best_place || $tsr_place < $tsr_best_place ) ) {
					$tsr_best_place = $tsr_place;
				}
			}

			if ( $tsr_year > 0
				&& ( null === $tsr_first_year || $tsr_year < $tsr_first_year ) ) {
				$tsr_first_year = $tsr_year;
			}

			$tsr_seasons[ $tsr_year ][] = array(
				'event'  => $tsr_event,
				'dist'   => $tsr_dist,
				'place'  => $tsr_place,
				'time'   => $tsr_time,
				'status' => $tsr_status,
				'url'    => get_permalink( $tsr_post ),
			);
		}
	}

	krsort( $tsr_seasons, SORT_NUMERIC );
}

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Профил на бегач</h1>
		<p class="tsr-page-hero__subtitle">
			<?php if ( $tsr_searching ) : ?>
				<?php echo esc_html( $tsr_runner_query ); ?>
			<?php else : ?>
				Търсете бегач по трите имена
			<?php endif; ?>
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<form class="tsr-runner-search" method="get" action="">
			<label class="screen-reader-text" for="tsr-runner-name">Имена на бегача</label>
			<input
				id="tsr-runner-name"
				class="tsr-runner-search__input"
				type="search"
				name="name"
				placeholder="Например: Иван Иванов"
				value="<?php echo esc_attr( $tsr_runner_query ); ?>"
				autocomplete="off"
				spellcheck="false">
			<button class="tsr-runner-search__btn" type="submit">Търси</button>
		</form>

		<?php if ( $tsr_searching ) : ?>

			<?php if ( empty( $tsr_seasons ) ) : ?>

				<div class="tsr-notice tsr-notice--info">
					<p>Не е намерен бегач с имена „<?php echo esc_html( $tsr_runner_query ); ?>" в базата с резултати. Проверете правописа или опитайте само с фамилното име.</p>
				</div>

			<?php else : ?>

				<!-- ── Stats strip ──────────────────────────────────────────── -->
				<div class="tsr-runner-stats">
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num"><?php echo esc_html( (string) $tsr_total_starts ); ?></div>
						<div class="tsr-runner-stat__label">старта</div>
					</div>
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num"><?php echo esc_html( (string) $tsr_total_fin ); ?></div>
						<div class="tsr-runner-stat__label">финиша</div>
					</div>
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num">
							<?php echo null !== $tsr_best_place ? esc_html( (string) $tsr_best_place ) : '—'; ?>
						</div>
						<div class="tsr-runner-stat__label">най-добро място</div>
					</div>
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num">
							<?php echo null !== $tsr_first_year ? esc_html( (string) $tsr_first_year ) : '—'; ?>
						</div>
						<div class="tsr-runner-stat__label">първи сезон</div>
					</div>
				</div>

				<!-- ── Results per season ──────────────────────────────────── -->
				<?php foreach ( $tsr_seasons as $tsr_yr => $tsr_entries ) : ?>
					<div class="tsr-runner-season">
						<h2 class="tsr-runner-season__heading">
							<?php echo $tsr_yr > 0 ? esc_html( (string) $tsr_yr ) : 'Без година'; ?>
							<span class="tsr-runner-season__count">
								— <?php echo esc_html( (string) count( $tsr_entries ) ); ?>&nbsp;старт<?php echo count( $tsr_entries ) !== 1 ? 'а' : ''; ?>
							</span>
						</h2>

						<div class="tsr-compact-wrap">
							<table class="tsr-compact-table">
								<thead>
									<tr>
										<th>Събитие</th>
										<th>Дистанция</th>
										<th>Място</th>
										<th>Финишно време</th>
										<th>Статус</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $tsr_entries as $tsr_e ) : ?>
										<tr class="<?php echo 'FIN' !== $tsr_e['status'] ? 'tsr-runner-row--dnx' : ''; ?>">
											<td>
												<a class="tsr-compact-table__link"
												   href="<?php echo esc_url( $tsr_e['url'] ); ?>">
													<?php echo esc_html( $tsr_e['event'] ); ?>
												</a>
											</td>
											<td><?php echo esc_html( $tsr_e['dist'] ); ?></td>
											<td class="tsr-compact-table__place">
												<?php echo null !== $tsr_e['place'] ? esc_html( (string) $tsr_e['place'] ) : '—'; ?>
											</td>
											<td class="tsr-compact-table__time">
												<?php echo '' !== $tsr_e['time'] ? esc_html( $tsr_e['time'] ) : '—'; ?>
											</td>
											<td><?php echo esc_html( $tsr_e['status'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div><!-- .tsr-compact-wrap -->
					</div><!-- .tsr-runner-season -->
				<?php endforeach; ?>

			<?php endif; ?>

		<?php endif; ?>

	</div><!-- .tsr-container -->
</main>

<?php get_footer(); ?>
