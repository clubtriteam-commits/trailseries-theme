<?php
declare( strict_types=1 );
/**
 * Template Name: История на събитие
 *
 * Shows the full history of one named event across all seasons:
 * course records per distance, every past edition linked to results.
 *
 * URL: /event/?sabitie=7-hills-run
 * The "sabitie" param is the sanitize_title() of the base event name, e.g.
 *   "7 Hills Run" → 7-hills-run
 *   "Buhovo Half Marathon" → buhovo-half-marathon
 *
 * The param is deliberately NOT "name": that is a reserved WordPress public
 * query var (post slug) — WP_Query::parse_query() checks it before
 * pagename, so /event/?name=X hijacks the main query into a single-post
 * lookup for slug "x" and 404s before this template ever runs.
 *
 * Without a sabitie param a browseable A–Z list of all events is shown.
 *
 * @package exhibz-child
 */

// ── Shared helpers ────────────────────────────────────────────────────────────
//
// tsr_title_year, tsr_slug_year, tsr_event_base_name,
// tsr_dist_label_from_title and tsr_time_to_seconds come from the
// trailseries-results plugin (includes/event-heuristics.php); the private
// copies this template used to carry are gone — one of them still split
// slugs on '--', a separator that never survives sanitize_title(), so its
// year detection ran against the raw section slug, category suffix and all.
// tsr_slug_base() (functions.php) resolves the hub base slug instead.

// ── Input ─────────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.Security.NonceVerification
$tsr_event_slug = sanitize_title( trim( sanitize_text_field( wp_unslash( $_GET['sabitie'] ?? '' ) ) ) );
$tsr_searching  = '' !== $tsr_event_slug;

// ── Data ──────────────────────────────────────────────────────────────────────
//
// Results-derived transients (key embeds tsr_cache_gen(), functions.php —
// any results write mints a new key). Audit H5: only a KNOWN event slug may
// compute or cache anything; `?sabitie=<anything else>` renders the normal
// "not found" view without scanning the corpus or writing a transient.
// Audit H3: posts are listed without meta priming (priming loads every
// results blob); event names come from tsr_results_index(), and only the
// matching event's posts load their blobs.

$tsr_all_posts = null; // ordered list, fetched at most once
$tsr_list_posts = static function () use ( &$tsr_all_posts ): array {
	if ( null === $tsr_all_posts ) {
		$tsr_all_posts = get_posts( array(
			'post_type'              => 'ts_result',
			'posts_per_page'         => -1,
			'post_status'            => 'publish',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
	}
	return $tsr_all_posts;
};
$tsr_rindex = tsr_results_index();
// Meta first (canonical, set by backfill-meta), title heuristic as fallback —
// same precedence as page-rezultati.php.
$tsr_name_of = static function ( WP_Post $p ) use ( $tsr_rindex ): string {
	return ( $tsr_rindex[ $p->ID ]['event_base'] ?? '' ) ?: tsr_event_base_name( $p->post_title );
};

// ── Event index (slug → display name): browse list, and the H5 whitelist ──────

$tsr_index_key   = 'tsr_event_index_g' . tsr_cache_gen();
$tsr_event_index = get_transient( $tsr_index_key );
if ( ! is_array( $tsr_event_index ) ) {
	$tsr_event_index = array();
	foreach ( $tsr_list_posts() as $tsr_p ) {
		$tsr_name = $tsr_name_of( $tsr_p );
		if ( '' === $tsr_name ) {
			continue;
		}
		$tsr_slug = sanitize_title( $tsr_name );
		if ( ! isset( $tsr_event_index[ $tsr_slug ] ) ) {
			$tsr_event_index[ $tsr_slug ] = $tsr_name;
		}
	}
	asort( $tsr_event_index );
	set_transient( $tsr_index_key, $tsr_event_index, 12 * HOUR_IN_SECONDS );
}
$tsr_known_event = $tsr_searching && isset( $tsr_event_index[ $tsr_event_slug ] );

$tsr_event_key = 'tsr_event_g' . tsr_cache_gen() . '_' . md5( $tsr_event_slug );
$tsr_event_hit = $tsr_known_event ? get_transient( $tsr_event_key ) : false;

// ── Branch B: single event history ───────────────────────────────────────────

$tsr_event_display = '';          // human-readable name
$tsr_editions      = array();     // [ year => [ [post, dist], … ] ]
$tsr_records       = array();     // [ dist_key => [time, name, url, year] ]

if ( $tsr_known_event && ! is_array( $tsr_event_hit ) ) {
	$tsr_event_posts = array_values( array_filter(
		$tsr_list_posts(),
		static fn( WP_Post $p ): bool => sanitize_title( $tsr_name_of( $p ) ) === $tsr_event_slug
	) );
	_prime_post_caches( wp_list_pluck( $tsr_event_posts, 'ID' ), false, true );
	foreach ( $tsr_event_posts as $tsr_p ) {
		$tsr_name = $tsr_name_of( $tsr_p );

		if ( '' === $tsr_event_display ) {
			$tsr_event_display = $tsr_name;
		}

		// _tsr_season is authoritative (race year, set by backfill-meta);
		// title/slug parsing is the fallback for posts without it.
		$tsr_year = (int) get_post_meta( $tsr_p->ID, '_tsr_season', true )
			?: ( tsr_title_year( $tsr_p->post_title )
				?? tsr_slug_year( tsr_slug_base( $tsr_p ) )
				?? 0 );
		$tsr_dist = tsr_dist_label_from_title( $tsr_p->post_title );
		$tsr_dk   = '' !== $tsr_dist ? $tsr_dist : 'Всички';

		// Scalars only — this array is transient-cached below. 'km' and
		// 'gender' exist only to sort (ADR-004; tsr_sort_by_distance_gender_scalars()),
		// never rendered.
		$tsr_editions[ $tsr_year ][] = array(
			'dist'   => $tsr_dist,
			'url'    => get_permalink( $tsr_p ),
			'km'     => (float) get_post_meta( $tsr_p->ID, '_tsr_distance_km', true ),
			'gender' => tsr_race_gender( $tsr_p ),
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

	// Site-wide ordering rule (ADR-004): longest distance first, men
	// before women within a distance, applied within each year.
	foreach ( $tsr_editions as &$tsr_eds_for_year ) {
		$tsr_eds_for_year = tsr_sort_by_distance_gender_scalars( $tsr_eds_for_year );
	}
	unset( $tsr_eds_for_year );

	set_transient(
		$tsr_event_key,
		array(
			'display'  => $tsr_event_display,
			'editions' => $tsr_editions,
			'records'  => $tsr_records,
		),
		12 * HOUR_IN_SECONDS
	);
} elseif ( is_array( $tsr_event_hit ) ) {
	$tsr_event_display = (string) $tsr_event_hit['display'];
	$tsr_editions      = $tsr_event_hit['editions'];
	$tsr_records       = $tsr_event_hit['records'];
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

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'История на събитие' ); ?>

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
								   href="<?php echo esc_url( add_query_arg( 'sabitie', rawurlencode( $tsr_sl ), get_permalink() ) ); ?>">
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
											   href="<?php echo esc_url( add_query_arg( 'runner', rawurlencode( $tsr_rec['name'] ), home_url( '/runner/' ) ) ); ?>">
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
