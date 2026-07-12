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

<main class="tsr-page-content">
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
								usort( $cats, static fn( $a, $b ) => strcmp( $a->post_title, $b->post_title ) );

								$primary_url = get_permalink( $primary );
								?>

								<li class="tsr-event-item">

									<a class="tsr-event-item__name"
									   href="<?php echo esc_url( $primary_url ); ?>">
										<?php echo esc_html( $event_name ); ?>
									</a>

									<?php if ( ! empty( $cats ) ) : ?>
										<ul class="tsr-cat-list">
											<?php foreach ( $cats as $cat_post ) : ?>
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
