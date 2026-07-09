<?php
declare( strict_types=1 );
/**
 * Template Name: История на събитие
 *
 * Shows the full history of one named event across all seasons:
 * course records per distance, every past edition linked to results.
 *
 * URL: /event/?name=7-hills-run
 * The "name" param is the sanitize_title() of the base event name, e.g.
 *   "7 Hills Run" → 7-hills-run
 *   "Buhovo Half Marathon" → buhovo-half-marathon
 *
 * Without a name param a browseable A–Z list of all events is shown.
 *
 * @package exhibz-child
 */

// ── Shared helpers (guarded against redeclaration) ────────────────────────────

if ( ! function_exists( 'tsr_title_year' ) ) {
	function tsr_title_year( string $title ): ?int {
		$pos = mb_strpos( $title, ' — ' );
		$raw = false !== $pos ? mb_substr( $title, 0, $pos ) : $title;
		if ( preg_match( "/['\x{2019}](\d{2})\b/u", $raw, $m ) ) {
			return 2000 + (int) $m[1];
		}
		if ( preg_match( '/\b(20\d{2})\b/', $raw, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}
}

if ( ! function_exists( 'tsr_slug_year' ) ) {
	function tsr_slug_year( string $slug ): ?int {
		$base = explode( '--', $slug )[0];
		if ( preg_match( '/(?:^|-)(20\d{2})(?:-|$)/', $base, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/[\pL\d](1[3-9]|2[0-9])(?:-|$)/u', $base, $m ) ) {
			return 2000 + (int) $m[1];
		}
		$stripped = (string) preg_replace(
			'/(?:^|-)(?:results?|ranking|класиране|резултати)\d*$/iu', '', $base
		);
		if ( $stripped !== $base && preg_match( '/-(1[3-9]|2[0-9])$/', $stripped, $m ) ) {
			return 2000 + (int) $m[1];
		}
		return null;
	}
}

if ( ! function_exists( 'tsr_event_base_name' ) ) {
	function tsr_event_base_name( string $title ): string {
		$pos = mb_strpos( $title, ' — ' );
		if ( false !== $pos ) {
			$title = mb_substr( $title, 0, $pos );
		}
		$title = (string) preg_replace( "/['\x{2019}]\d{2}(?:\s*[-–—\s]\s*\S+.*)?\s*$/u", '', $title );
		$title = (string) preg_replace( '/\s+20\d{2}(?:\s*[-–—]\s*\S+.*)?\s*$/u', '', $title );
		$title = (string) preg_replace( '/\s*[-–—]\s*(?:results?|ranking|класиране|резултати)\b.*/iu', '', $title );
		$title = (string) preg_replace( '/\s+(?:класиране|резултати|results?|ranking)\s*$/iu', '', $title );
		$title = (string) preg_replace( '/[\s\-–—]+$/u', '', $title );
		return trim( $title );
	}
}

if ( ! function_exists( 'tsr_dist_label_from_title' ) ) {
	function tsr_dist_label_from_title( string $title ): string {
		if ( preg_match( '/ — (.+)$/', $title, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}
}

if ( ! function_exists( 'tsr_time_to_seconds' ) ) {
	function tsr_time_to_seconds( string $time ): int {
		if ( '' === $time ) {
			return PHP_INT_MAX;
		}
		$p = explode( ':', $time );
		return 3 === count( $p )
			? (int) $p[0] * 3600 + (int) $p[1] * 60 + (int) $p[2]
			: PHP_INT_MAX;
	}
}

// ── Input ─────────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.Security.NonceVerification
$tsr_event_slug = sanitize_title( trim( sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ) ) );
$tsr_searching  = '' !== $tsr_event_slug;

// ── Data: fetch all ts_result posts once ──────────────────────────────────────

$tsr_all_posts = get_posts( array(
	'post_type'      => 'ts_result',
	'posts_per_page' => -1,
	'post_status'    => 'publish',
) );

// ── Branch A: browse all events ───────────────────────────────────────────────

$tsr_event_index = array(); // slug → display name

if ( ! $tsr_searching ) {
	foreach ( $tsr_all_posts as $tsr_p ) {
		$tsr_name = tsr_event_base_name( $tsr_p->post_title );
		if ( '' === $tsr_name ) {
			continue;
		}
		$tsr_slug = sanitize_title( $tsr_name );
		if ( ! isset( $tsr_event_index[ $tsr_slug ] ) ) {
			$tsr_event_index[ $tsr_slug ] = $tsr_name;
		}
	}
	asort( $tsr_event_index );
}

// ── Branch B: single event history ───────────────────────────────────────────

$tsr_event_display = '';          // human-readable name
$tsr_editions      = array();     // [ year => [ [post, dist], … ] ]
$tsr_records       = array();     // [ dist_key => [time, name, url, year] ]

if ( $tsr_searching ) {
	foreach ( $tsr_all_posts as $tsr_p ) {
		$tsr_name = tsr_event_base_name( $tsr_p->post_title );
		if ( sanitize_title( $tsr_name ) !== $tsr_event_slug ) {
			continue;
		}

		if ( '' === $tsr_event_display ) {
			$tsr_event_display = $tsr_name;
		}

		$tsr_year = tsr_title_year( $tsr_p->post_title )
			?? tsr_slug_year( $tsr_p->post_name )
			?? 0;
		$tsr_dist = tsr_dist_label_from_title( $tsr_p->post_title );
		$tsr_dk   = '' !== $tsr_dist ? $tsr_dist : 'Всички';

		$tsr_editions[ $tsr_year ][] = array(
			'post' => $tsr_p,
			'dist' => $tsr_dist,
			'url'  => get_permalink( $tsr_p ),
		);

		// Course record per distance category.
		$tsr_raw = get_post_meta( $tsr_p->ID, '_tsr_result_set', true );
		if ( '' === $tsr_raw ) {
			continue;
		}
		$tsr_data = json_decode( $tsr_raw, true );
		if ( ! isset( $tsr_data['rows'] ) ) {
			continue;
		}

		foreach ( $tsr_data['rows'] as $tsr_row ) {
			if ( ! is_array( $tsr_row ) || 'FIN' !== ( $tsr_row['status'] ?? '' ) ) {
				continue;
			}
			$tsr_t = (string) ( $tsr_row['finish_time'] ?? '' );
			if ( '' === $tsr_t ) {
				continue;
			}
			$tsr_secs = tsr_time_to_seconds( $tsr_t );
			if ( ! isset( $tsr_records[ $tsr_dk ] )
				|| $tsr_secs < tsr_time_to_seconds( $tsr_records[ $tsr_dk ]['time'] ) ) {
				$tsr_records[ $tsr_dk ] = array(
					'time' => $tsr_t,
					'name' => trim(
						( (string) ( $tsr_row['first_name'] ?? '' ) )
						. ' '
						. ( (string) ( $tsr_row['last_name'] ?? '' ) )
					),
					'url'  => get_permalink( $tsr_p ),
					'year' => $tsr_year,
				);
			}
		}
	}

	krsort( $tsr_editions, SORT_NUMERIC );
	ksort( $tsr_records );
}

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">
			<?php echo $tsr_searching && '' !== $tsr_event_display
				? esc_html( $tsr_event_display )
				: 'История на събитие'; ?>
		</h1>
		<p class="tsr-page-hero__subtitle">
			<?php if ( $tsr_searching && '' !== $tsr_event_display ) : ?>
				Всички издания и рекорди на трасето
			<?php elseif ( $tsr_searching ) : ?>
				Събитието „<?php echo esc_html( $tsr_event_slug ); ?>" не е намерено
			<?php else : ?>
				Изберете събитие, за да видите неговата история
			<?php endif; ?>
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php if ( ! $tsr_searching ) : ?>
			<!-- ── Browse: A–Z list of all events ──────────────────────────── -->
			<section class="tsr-prose-section">
				<h2>Всички трасета</h2>

				<?php if ( empty( $tsr_event_index ) ) : ?>
					<div class="tsr-notice tsr-notice--info">
						<p>Все още няма публикувани резултати.</p>
					</div>
				<?php else : ?>
					<ul class="tsr-event-index">
						<?php foreach ( $tsr_event_index as $tsr_sl => $tsr_nm ) : ?>
							<li>
								<a class="tsr-event-index__link"
								   href="<?php echo esc_url( add_query_arg( 'name', rawurlencode( $tsr_sl ), get_permalink() ) ); ?>">
									<?php echo esc_html( $tsr_nm ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

		<?php elseif ( empty( $tsr_editions ) ) : ?>
			<div class="tsr-notice tsr-notice--info">
				<p>Не е намерено събитие „<?php echo esc_html( $tsr_event_slug ); ?>". <a href="<?php echo esc_url( get_permalink() ); ?>">Вижте всички трасета</a>.</p>
			</div>

		<?php else : ?>

			<!-- ── Course records ──────────────────────────────────────────── -->
			<?php if ( ! empty( $tsr_records ) ) : ?>
				<section class="tsr-event-records">
					<h2 class="tsr-event-records__title">Рекорди на трасето</h2>

					<div class="tsr-compact-wrap">
						<table class="tsr-compact-table tsr-compact-table--records">
							<thead>
								<tr>
									<th>Дистанция</th>
									<th>Рекордьор</th>
									<th>Рекордно време</th>
									<th>Година</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $tsr_records as $tsr_dk => $tsr_rec ) : ?>
									<tr>
										<td><?php echo esc_html( $tsr_dk ); ?></td>
										<td>
											<a class="tsr-compact-table__link"
											   href="<?php echo esc_url( add_query_arg( 'name', rawurlencode( $tsr_rec['name'] ), home_url( '/runner/' ) ) ); ?>">
												<?php echo esc_html( $tsr_rec['name'] ); ?>
											</a>
										</td>
										<td class="tsr-compact-table__time"><?php echo esc_html( $tsr_rec['time'] ); ?></td>
										<td>
											<a class="tsr-compact-table__link"
											   href="<?php echo esc_url( $tsr_rec['url'] ); ?>">
												<?php echo $tsr_rec['year'] > 0 ? esc_html( (string) $tsr_rec['year'] ) : '—'; ?>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>

				<hr class="tsr-cal-divider">
			<?php endif; ?>

			<!-- ── All editions ────────────────────────────────────────────── -->
			<section class="tsr-prose-section">
				<h2>Издания</h2>

				<div class="tsr-editions">
					<?php foreach ( $tsr_editions as $tsr_yr => $tsr_eds ) : ?>
						<div class="tsr-edition-item <?php echo count( $tsr_eds ) > 1 ? 'tsr-edition-item--multi' : ''; ?>">
							<span class="tsr-edition-item__year">
								<?php echo $tsr_yr > 0 ? esc_html( (string) $tsr_yr ) : '—'; ?>
							</span>
							<div class="tsr-edition-item__links">
								<?php foreach ( $tsr_eds as $tsr_ed ) : ?>
									<a class="tsr-edition-item__link"
									   href="<?php echo esc_url( $tsr_ed['url'] ); ?>">
										<?php
										$tsr_label = '' !== $tsr_ed['dist']
											? $tsr_ed['dist']
											: esc_html( $tsr_event_display );
										echo esc_html( $tsr_label );
										?>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

		<?php endif; ?>

	</div><!-- .tsr-container -->
</main>

<?php get_footer(); ?>
