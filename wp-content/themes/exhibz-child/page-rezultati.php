<?php
declare( strict_types=1 );
/**
 * Template Name: Резултати
 *
 * Template for the Резултати archive page (slug: rezultati).
 *
 * Queries all published ts_result posts and groups them two levels deep:
 *
 *   Year  — extracted from post_title first (apostrophe-year 'YY → 20YY, or
 *            literal 20YY), then from post_name when the title has none (2-digit
 *            year attached directly to a word: run15, ranking14, класиране16;
 *            or 4-digit year as a slug segment: run-14-07-2013). Never falls
 *            back to post_date (which is the import date, not the race year).
 *            Posts with no year signal in either source are grouped under
 *            key 0 and shown last, labelled "Без година".
 *
 *   Event — post_title with the results label ("- Results", "– класиране", etc.)
 *            and the year token both stripped, plus the " — {category_raw}"
 *            suffix that bulk-import appends. When the derived name is empty
 *            (page_title was blank — e.g. xmas-run-15-results), the post_name
 *            is converted to a human-readable fallback ("xmas-run-15-results"
 *            → "Xmas Run"). Posts with no usable name from either source are
 *            skipped entirely (fixes empty bullets in old seasons).
 *
 * Permalinks use get_permalink() — the CPT rewrite slug is '' (no prefix),
 * so posts live at /{post_name}/ in line with the old site's URL structure.
 *
 * @package exhibz-child
 */

// ── Helpers ─────────────────────────────────────────────────────────────────
//
// Year/event-name heuristics (tsr_title_year, tsr_slug_year,
// tsr_event_base_name, tsr_slug_event_name) live in the trailseries-results
// plugin: includes/event-heuristics.php — the single shared definition.
// tsr_slug_base() (hub resolution) lives in functions.php. Slug-based
// helpers take the BASE slug from tsr_slug_base(), never a raw section
// post_name (its category suffix cannot be split off textually — the
// importer's '--' separator is collapsed by sanitize_title() on insert).

// ── 1. Fetch all published ts_result posts ──────────────────────────────────

get_header();

$all_posts = get_posts( array(
	'post_type'        => 'ts_result',
	'posts_per_page'   => -1,
	'post_status'      => 'publish',
	'orderby'          => array( 'date' => 'DESC', 'title' => 'ASC' ),
	'suppress_filters' => false,
) );

// ── 2. Group: year_key → event_name → [ WP_Post, … ] ────────────────────────

$grouped      = array();
$current_year = (int) gmdate( 'Y' );

foreach ( $all_posts as $post ) {
	// Year: _tsr_season meta first (most authoritative), then title heuristics,
	// then slug heuristics, 0 = "Без година".
	// NEVER use post_date — it is the import date, not the race year.
	$season_meta = get_post_meta( $post->ID, '_tsr_season', true );
	$year_key    = ( '' !== (string) $season_meta )
		? (int) $season_meta
		: ( tsr_title_year( $post->post_title )
			?? tsr_slug_year( tsr_slug_base( $post ) )
			?? 0 );

	// Event name: title-derived first, slug-derived as fallback.
	$event_name = tsr_event_base_name( $post->post_title );
	if ( $event_name === '' ) {
		$event_name = tsr_slug_event_name( tsr_slug_base( $post ) );
	}
	// Skip entries with no usable name — avoids empty bullets (issue 4).
	if ( $event_name === '' ) {
		continue;
	}

	$grouped[ $year_key ][ $event_name ][] = $post;
}

// Sort years newest-first. Year key 0 ("Без година") is the lowest, so it
// lands last after krsort — correct position at the bottom of the list.
krsort( $grouped, SORT_NUMERIC );

// ── 2b. Within each year, order races by actual calendar date ───────────────
//
// $grouped[$year] is keyed by event name in whatever order the date-DESC
// post fetch happened to first encounter each event's posts — arbitrary
// relative to the actual race calendar (results-derived, not date-derived).
// The requested order is reverse-chronological within a season: the year's
// LAST race at the top, its FIRST race at the bottom. ts_result slugs for
// these events carry no day/month token to derive that from, so the real
// signal is the ajde_events calendar (evcal_srow) — same event_base/year
// match front-page.php's "Последни резултати" section already uses.
//
// Events with no calendar match (older seasons that predate the EventON
// calendar) have no date signal at all; they sort after every dated event
// in their year, keeping their prior relative order among themselves
// (usort is stable in PHP 8) rather than being scrambled.
if ( post_type_exists( 'ajde_events' ) ) {
	$tsr_event_dates = array(); // [ year => [ event_base => evcal_srow ] ]
	foreach ( get_posts( array(
		'post_type'      => 'ajde_events',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	) ) as $tsr_ev_post ) {
		$tsr_ev_title = html_entity_decode( $tsr_ev_post->post_title, ENT_QUOTES, 'UTF-8' );
		$tsr_ev_base  = tsr_event_base_name( $tsr_ev_title );
		if ( '' === $tsr_ev_base ) {
			continue;
		}
		$tsr_ev_ts   = (int) get_post_meta( $tsr_ev_post->ID, 'evcal_srow', true );
		$tsr_ev_year = tsr_title_year( $tsr_ev_title ) ?? (int) date_i18n( 'Y', $tsr_ev_ts );
		// A later calendar entry for the same event+year (edit, duplicate)
		// wins — last one processed simply overwrites, no ordering assumed.
		$tsr_event_dates[ $tsr_ev_year ][ $tsr_ev_base ] = $tsr_ev_ts;
	}

	foreach ( $grouped as $tsr_year => &$tsr_events_for_year ) {
		$tsr_dates_this_year = $tsr_event_dates[ $tsr_year ] ?? array();
		uksort(
			$tsr_events_for_year,
			static function ( string $tsr_a, string $tsr_b ) use ( $tsr_dates_this_year ): int {
				$tsr_ts_a = $tsr_dates_this_year[ $tsr_a ] ?? null;
				$tsr_ts_b = $tsr_dates_this_year[ $tsr_b ] ?? null;
				if ( null === $tsr_ts_a && null === $tsr_ts_b ) {
					return 0; // neither dated — keep relative order (stable sort).
				}
				if ( null === $tsr_ts_a ) {
					return 1; // undated sorts after dated.
				}
				if ( null === $tsr_ts_b ) {
					return -1;
				}
				return $tsr_ts_b <=> $tsr_ts_a; // descending: latest race first.
			}
		);
	}
	unset( $tsr_events_for_year );
}

// ── Season display labels ─────────────────────────────────────────────────────
$season_labels = array(
	2012 => 'Сезон 1 (2012–2013)',
	2013 => 'Сезон 2 (2013–2014)',
	2014 => 'Сезон 3 (2014–2015)',
	2015 => 'Сезон 4 (2015–2016)',
	2016 => 'Сезон 5 (2016–2017)',
	2017 => 'Сезон 6 (2017–2018)',
	2018 => 'Сезон 7 (2018–2019)',
	2019 => 'Сезон 8 (2019–2020)',
	2020 => 'Сезон 8 (2019–2020)',
	2021 => 'Сезон 9 (2021)',
	2022 => 'Сезон 10 (2022)',
	2023 => 'Сезон 11 (2023)',
	2024 => 'Сезон 12 (2024)',
	2025 => 'Сезон 13 (2025)',
	2026 => 'Сезон 14 (2026)',
);
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Резултати</h1>
		<p class="tsr-page-hero__subtitle">
			Всички резултати от сезоните — по година и дистанция
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Резултати' ); ?>

		<?php if ( empty( $grouped ) ) : ?>
			<div class="tsr-notice">
				<p>Все още няма публикувани резултати. Проверете отново след първото състезание.</p>
			</div>

		<?php else : ?>

			<div class="tsr-results-archive">

				<?php foreach ( $grouped as $year => $events ) : ?>
					<?php
					$year_label = $year > 0
						? ( $season_labels[ $year ] ?? (string) $year )
						: __( 'Без година', 'exhibz-child' );
					$is_current = ( $year === $current_year );
					?>

					<details class="tsr-year-group" <?php echo $is_current ? 'open' : ''; ?>>
						<summary class="tsr-year-group__summary">
							<span class="tsr-year-group__year"><?php echo esc_html( $year_label ); ?></span>
							<span class="tsr-year-group__count">
								<?php
								$n = 0;
								foreach ( $events as $event_posts ) {
									$n += count( $event_posts );
								}
								/* translators: %d = number of result sets in this season */
								printf( esc_html( _n( '%d класиране', '%d класирания', $n, 'exhibz-child' ) ), $n );
								?>
							</span>
						</summary>

						<ul class="tsr-event-list">

							<?php foreach ( $events as $event_name => $event_posts ) : ?>

								<?php
								// Primary post: the hub (no other post's slug + '-' prefixes
								// it) = the SEO-preserved legacy URL. tsr_hub_head_for()
								// (functions.php) returns null for hubs/standalone posts —
								// see tsr_slug_base() above for why a strpos('--') check
								// never matches any real stored slug.
								$primary = null;
								$cats    = array();

								foreach ( $event_posts as $p ) {
									if ( null === tsr_hub_head_for( $p ) ) {
										$primary = $p;
									} else {
										$cats[] = $p;
									}
								}
								// Fallback: no post in this group resolved as a hub (shouldn't happen).
								if ( null === $primary ) {
									$primary = array_shift( $event_posts );
								}
								// Every available category gets a pill — including the hub
								// category itself, which otherwise has no visible label at
								// all (it's only reachable via the bare event-name link).
								// Site-wide ordering rule (ADR-004) governs the combined
								// list: longest distance first, men before women.
								$pills = tsr_sort_results_by_distance_gender( array_merge( array( $primary ), $cats ) );

								$primary_url = get_permalink( $primary );
								?>

								<li class="tsr-event-item">

									<a class="tsr-event-item__name"
									   href="<?php echo esc_url( $primary_url ); ?>">
										<?php echo esc_html( $event_name ); ?>
									</a>

									<?php if ( ! empty( $pills ) ) : ?>
										<ul class="tsr-cat-list">
											<?php foreach ( $pills as $cat_post ) : ?>
												<?php
												$cat_sep   = mb_strpos( $cat_post->post_title, ' — ' );
												$cat_label = false !== $cat_sep
													? mb_substr( $cat_post->post_title, $cat_sep + 3 )
													: $cat_post->post_title;
												$cat_url   = get_permalink( $cat_post );
												?>
												<li>
													<a class="tsr-cat-pill"
													   href="<?php echo esc_url( $cat_url ); ?>">
														<?php echo esc_html( $cat_label ); ?>
													</a>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>

								</li>

							<?php endforeach; ?>

						</ul><!-- .tsr-event-list -->

					</details><!-- .tsr-year-group -->

				<?php endforeach; ?>

			</div><!-- .tsr-results-archive -->

		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
