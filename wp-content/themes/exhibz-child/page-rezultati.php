<?php
/**
 * Template Name: Резултати
 *
 * Template for the Резултати archive page (slug: rezultati).
 *
 * Queries all published ts_result posts and groups them two levels deep:
 *   Year  → extracted from post_title (apostrophe-year 'YY → 20YY, or 20YY
 *            literal), falling back to post_date year when the title has none.
 *   Event → post_title with both the year token and the results label
 *            ("- Results", "– класиране", etc.) stripped, and the
 *            " — {category_raw}" suffix that bulk-import appends removed.
 *
 * Years are sorted newest-first (krsort on the string year key).
 * The most recent year's <details> is open; all others are collapsed.
 *
 * Permalinks use home_url( '/' . post_name . '/' ) — direct slug construction
 * — because the CPT's rewrite slug is still a placeholder ('results') from
 * ADR-003 and does not yet match the legacy URL structure. Fix class-post-types.php
 * rewrite slug before going live.
 *
 * @package exhibz-child
 */

declare( strict_types=1 );

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Extract the 4-digit year from a ts_result post title.
 *
 * Handles two written conventions used across the old site:
 *   "Run'25 - Results"          → 2025   (apostrophe + 2-digit year)
 *   "7 Hills Run 2024 - Ranking" → 2024   (literal 4-digit year)
 *   "ranking'16"                → 2016   (year after the results label)
 *
 * The bulk-import " — {category}" suffix is stripped before matching so that
 * a category label that happens to contain a year doesn't poison the result.
 *
 * @param string $title Full post_title.
 * @return int|null 4-digit year, or null when none is found.
 */
function tsr_title_year( string $title ): ?int {
	// Ignore everything after the bulk-import " — category" separator.
	$pos = mb_strpos( $title, ' — ' );
	$raw = false !== $pos ? mb_substr( $title, 0, $pos ) : $title;

	// Apostrophe-year: Run'25, ranking'16, Run\u{2019}23 (right-single-quote).
	if ( preg_match( "/['\x{2019}](\d{2})\b/u", $raw, $m ) ) {
		return 2000 + (int) $m[1];
	}
	// Literal 4-digit year: 2014, 2016, 2020 …
	if ( preg_match( '/\b(20\d{2})\b/', $raw, $m ) ) {
		return (int) $m[1];
	}
	return null;
}

/**
 * Derive a clean event base name from a ts_result post_title.
 *
 * Strips (in order):
 *  1. The " — {category_raw}" suffix that bulk-import appends.
 *  2. The results/ranking label together with its year when the year is an
 *     apostrophe-style token ("Run'25 - Results", "ranking'16", "Results'14").
 *  3. Standalone 4-digit year with any trailing label ("Run 2024 - Ranking").
 *  4. Any remaining results/ranking label preceded by a separator.
 *  5. Trailing-only results/ranking label with no separator (old-format titles
 *     like "MalakSechkoRun класиране", "GolyamSechkoRun - класиране").
 *  6. Trailing whitespace and stray separators.
 *
 * A leading label like "Класиране - 7 Hills Run" is intentionally preserved:
 * the regex anchors on a separator *before* the label, so a title that starts
 * with the label word is left untouched.
 *
 * @param string $title Full post_title.
 * @return string Clean event name, never empty (falls back to the raw title).
 */
function tsr_event_base_name( string $title ): string {
	// 1. Strip " — {category}" suffix.
	$pos = mb_strpos( $title, ' — ' );
	if ( false !== $pos ) {
		$title = mb_substr( $title, 0, $pos );
	}

	// 2. Strip apostrophe-year + anything after it (results label):
	//    "Run'25 - Results" → "Run", "ranking'16" → removed entirely.
	$title = preg_replace( "/['\x{2019}]\d{2}(?:\s*[-–—\s]\s*\S+.*)?$/u", '', $title );

	// 3. Strip 4-digit year + any trailing label:
	//    "7 Hills Run 2024 - Ranking" → "7 Hills Run".
	$title = preg_replace( '/\s+20\d{2}(?:\s*[-–—]\s*\S+.*)?\s*$/u', '', $title );

	// 4. Strip remaining "- Results", "– класиране", etc. (any separator style).
	$title = preg_replace(
		'/\s*[-–—]\s*(?:results?|ranking|класиране|резултати)\b.*/iu',
		'',
		$title
	);

	// 5. Strip trailing results/ranking word without separator ("BuhovoRun класиране").
	$title = preg_replace(
		'/\s+(?:класиране|резултати|results?|ranking)\s*$/iu',
		'',
		$title
	);

	// 6. Clean up stray separators and collapse internal whitespace.
	$title = preg_replace( '/[\s\-–—]+$/u', '', $title );
	$title = preg_replace( '/\s{2,}/u', ' ', $title );
	$title = trim( $title );

	return $title !== '' ? $title : $title; // keep original if all steps returned ''.
}

// ── 1. Fetch all published ts_result posts ──────────────────────────────────

get_header();

$all_posts = get_posts( array(
	'post_type'        => 'ts_result',
	'posts_per_page'   => -1,
	'post_status'      => 'publish',
	'orderby'          => array( 'date' => 'DESC', 'title' => 'ASC' ),
	'suppress_filters' => false,
) );

// ── 2. Group: year → event_name → [ WP_Post, … ] ───────────────────────────

$grouped      = array();
$current_year = (int) gmdate( 'Y' );

foreach ( $all_posts as $post ) {
	// Year: prefer title-embedded year, fall back to post_date.
	$year = tsr_title_year( $post->post_title )
		?? (int) get_the_date( 'Y', $post );

	$event_name = tsr_event_base_name( $post->post_title );

	$grouped[ $year ][ $event_name ][] = $post;
}

// Sort years newest-first. krsort() on integer-keyed array is numeric-descending.
krsort( $grouped, SORT_NUMERIC );
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

		<?php if ( empty( $grouped ) ) : ?>
			<div class="tsr-notice">
				<p>Все още няма публикувани резултати. Проверете отново след първото състезание.</p>
			</div>

		<?php else : ?>

			<div class="tsr-results-archive">

				<?php foreach ( $grouped as $year => $events ) : ?>
					<?php $is_current = ( (int) $year === $current_year ); ?>

					<details class="tsr-year-group" <?php echo $is_current ? 'open' : ''; ?>>
						<summary class="tsr-year-group__summary">
							<span class="tsr-year-group__year"><?php echo esc_html( (string) $year ); ?></span>
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
								// Primary post: bare slug without "--" = the SEO-preserved legacy URL.
								$primary = null;
								$cats    = array();

								foreach ( $event_posts as $p ) {
									if ( false === strpos( $p->post_name, '--' ) ) {
										$primary = $p;
									} else {
										$cats[] = $p;
									}
								}
								// Fallback: if all posts have "--" (shouldn't happen), use the first.
								if ( null === $primary ) {
									$primary = array_shift( $event_posts );
								}
								usort( $cats, static fn( $a, $b ) => strcmp( $a->post_title, $b->post_title ) );

								// Build the permalink directly from the post_name so the URL is
								// always /{slug}/, regardless of the CPT rewrite placeholder.
								$primary_url = home_url( '/' . $primary->post_name . '/' );
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
												// Category label: everything after " — " in the title.
												$cat_sep   = mb_strpos( $cat_post->post_title, ' — ' );
												$cat_label = false !== $cat_sep
													? mb_substr( $cat_post->post_title, $cat_sep + 3 )
													: $cat_post->post_title;
												$cat_url   = home_url( '/' . $cat_post->post_name . '/' );
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
