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

/**
 * Extract a 4-digit year from a ts_result post_title.
 *
 * Handles apostrophe-year ("Run'25" → 2025, "ranking'16" → 2016) and
 * literal 4-digit years ("7 Hills Run 2024" → 2024).
 * The " — {category}" suffix is stripped before matching.
 *
 * @param string $title Full post_title.
 * @return int|null 4-digit year, or null when none is found.
 */
if ( ! function_exists( 'tsr_title_year' ) ) {
function tsr_title_year( string $title ): ?int {
	$pos = mb_strpos( $title, ' — ' );
	$raw = false !== $pos ? mb_substr( $title, 0, $pos ) : $title;

	// Apostrophe-year: Run'25, ranking'16, Run\u{2019}23.
	if ( preg_match( "/['\x{2019}](\d{2})\b/u", $raw, $m ) ) {
		return 2000 + (int) $m[1];
	}
	// Literal 4-digit year: 2014 … 2029.
	if ( preg_match( '/\b(20\d{2})\b/', $raw, $m ) ) {
		return (int) $m[1];
	}
	return null;
}
}

/**
 * Extract a 4-digit year from a ts_result post_name (slug).
 *
 * Used as a fallback when the post_title has no year signal (old races whose
 * page titles were just "BuhovoRun класиране" without a year suffix).
 *
 * Recognises:
 *   1. 4-digit year as a hyphen-delimited slug segment:
 *      "heat-stroke-run-14-07-2013" → 2013, "buhovorun-ranking-2014" → 2014
 *   2. 2-digit year attached DIRECTLY to a Unicode word character (no hyphen
 *      before the digits), indicating it's a year suffix not a date component:
 *      "7-hills-run15-ranking" → 2015, "iran-run-results15" → 2015,
 *      "pancharevo-night-run-класиране16" → 2016, "golyam-sechko-run18" → 2018
 *   3. 2-digit year as a trailing segment AFTER the results/ranking label is
 *      stripped: "xmas-run-15-results" → strip "-results" → "xmas-run-15" → 2015
 *
 * Does NOT extract 2-digit numbers that are hyphen-separated date components
 * ("vladaya-21-april", "buhovo-26-may", "lokorsko-23-fevruari", "pasarel-run-16-06")
 * because those are day-of-month numbers with a month word immediately after.
 *
 * @param string $slug post_name.
 * @return int|null 4-digit year, or null when none is found.
 */
if ( ! function_exists( 'tsr_slug_year' ) ) {
function tsr_slug_year( string $slug ): ?int {
	$base = explode( '--', $slug )[0];

	// 1. 4-digit year as a hyphen segment.
	if ( preg_match( '/(?:^|-)(20\d{2})(?:-|$)/', $base, $m ) ) {
		return (int) $m[1];
	}

	// 2. 2-digit year attached directly to a Unicode word char (letter/digit).
	//    The /u flag enables Unicode \pL matching for Cyrillic slugs.
	if ( preg_match( '/[\pL\d](1[3-9]|2[0-9])(?:-|$)/u', $base, $m ) ) {
		return 2000 + (int) $m[1];
	}

	// 3. 2-digit year as trailing segment after stripping the results label.
	$stripped = (string) preg_replace(
		'/(?:^|-)(?:results?|ranking|класиране|резултати)\d*$/iu',
		'',
		$base
	);
	if ( $stripped !== $base && preg_match( '/-(1[3-9]|2[0-9])$/', $stripped, $m ) ) {
		return 2000 + (int) $m[1];
	}

	return null;
}
}

/**
 * Derive a clean event base name from a ts_result post_title.
 *
 * Strips (in order):
 *  1. The " — {category_raw}" suffix bulk-import appends.
 *  2. Apostrophe-year token and any trailing label  ("Run'25 - Results" → "Run").
 *  3. 4-digit year segment and any trailing label   ("Run 2024 - Ranking" → "Run").
 *  4. Any remaining results/ranking label with a separator.
 *  5. Trailing results/ranking word without a separator ("BuhovoRun класиране").
 *  6. Stray separators and whitespace.
 *
 * A leading label like "Класиране - 7 Hills Run" is intentionally preserved:
 * the regex requires a separator BEFORE the label word.
 *
 * Returns '' when the title has no usable base name (empty page_title).
 *
 * @param string $title Full post_title.
 * @return string Clean event name, possibly empty.
 */
if ( ! function_exists( 'tsr_event_base_name' ) ) {
function tsr_event_base_name( string $title ): string {
	$pos = mb_strpos( $title, ' — ' );
	if ( false !== $pos ) {
		$title = mb_substr( $title, 0, $pos );
	}
	$title = (string) preg_replace( "/['\x{2019}]\d{2}(?:\s*[-–—\s]\s*\S+.*)?\s*$/u", '', $title );
	$title = (string) preg_replace( '/\s+20\d{2}(?:\s*[-–—]\s*\S+.*)?\s*$/u', '', $title );
	$title = (string) preg_replace(
		'/\s*[-–—]\s*(?:results?|ranking|класиране|резултати)\b.*/iu',
		'',
		$title
	);
	$title = (string) preg_replace(
		'/\s+(?:класиране|резултати|results?|ranking)\s*$/iu',
		'',
		$title
	);
	$title = (string) preg_replace( '/[\s\-–—]+$/u', '', $title );
	$title = (string) preg_replace( '/\s{2,}/u', ' ', $title );
	return trim( $title );
}
}

/**
 * Derive a human-readable event name from the post_name (slug).
 *
 * Fallback used when tsr_event_base_name() returns '' (the WXR page had no
 * visible title — e.g. xmas-run-15-results → "Xmas Run").
 *
 * Returns '' for known-garbage slugs (pure digits, "untitled", "news", "page").
 *
 * @param string $slug post_name.
 * @return string Human-readable name, possibly empty.
 */
if ( ! function_exists( 'tsr_slug_event_name' ) ) {
function tsr_slug_event_name( string $slug ): string {
	$base = explode( '--', $slug )[0];

	// Strip 4-digit year segment FIRST so that "ranking-2014" becomes "ranking"
	// before the results/ranking label stripping runs.
	$base = (string) preg_replace( '/(?:^|-)20\d{2}(?:-|$)/', '-', $base );
	$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );

	// Strip results/ranking suffix (may include an attached 2-digit year).
	$base = (string) preg_replace(
		'/(?:^|-)(?:results?|ranking|класиране|резултати)\d*$/iu',
		'',
		$base
	);
	// Strip trailing 2-digit year segment: xmas-run-15 → xmas-run.
	$base = (string) preg_replace( '/-(1[3-9]|2[0-9])$/', '', $base );
	// Strip 2-digit year attached to word: run15 → run.
	$base = (string) preg_replace( '/([\pL\d])(1[3-9]|2[0-9])(?:-|$)/u', '$1', $base );
	$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );

	// Reject known-garbage slugs.
	$lower = mb_strtolower( $base );
	if ( $base === '' || ctype_digit( $base ) || in_array( $lower, [ 'untitled', 'news', 'page' ], true ) ) {
		return '';
	}

	// Title-case each hyphen-separated segment (works for Cyrillic).
	$words = array_map(
		fn( string $w ): string => mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ),
		explode( '-', $base )
	);
	return implode( ' ', $words );
}
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
			?? tsr_slug_year( $post->post_name )
			?? 0 );

	// Event name: title-derived first, slug-derived as fallback.
	$event_name = tsr_event_base_name( $post->post_title );
	if ( $event_name === '' ) {
		$event_name = tsr_slug_event_name( $post->post_name );
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
								// Fallback: all posts have "--" (shouldn't happen).
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
