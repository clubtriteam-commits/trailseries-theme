<?php
declare( strict_types=1 );
/**
 * Shared event/year/time heuristics for ts_result titles and slugs.
 *
 * THE single definition of these functions. They previously existed as four
 * divergent copies (page-rezultati.php, page-event.php, page-runner.php and
 * private methods in class-cli.php) behind function_exists guards, which let
 * the copies drift apart silently: two still split slugs on '--' (a
 * separator sanitize_title() collapses before anything reaches the
 * database, so the split never matched and category suffixes leaked into
 * year detection), and two lacked the whitespace-collapse step of the
 * others. Do not re-inline these into a template.
 *
 * The slug-based helpers take a legacy-page BASE slug — the hub post's
 * post_name, already free of any category suffix. Resolve it with the
 * theme's tsr_slug_base( WP_Post ) (which prefix-matches against sibling
 * posts); never pass a raw section post_name.
 *
 * All functions are pure string transforms with no WordPress dependencies,
 * usable from templates, the CLI and (eventually) tests alike.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

/**
 * Extract a 4-digit year from a ts_result legacy-page base slug.
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
 * @param string $base Legacy-page base slug (from tsr_slug_base()).
 * @return int|null 4-digit year, or null when none is found.
 */
function tsr_slug_year( string $base ): ?int {
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

/**
 * Derive a clean event base name from a ts_result post_title.
 *
 * Strips (in order):
 *  1. The " — {category_raw}" suffix bulk-import appends.
 *  2. Apostrophe-year token and any trailing label  ("Run'25 - Results" → "Run").
 *  3. 4-digit year segment and any trailing label   ("Run 2024 - Ranking" → "Run").
 *  4. Any remaining results/ranking label with a separator.
 *  5. Trailing results/ranking word without a separator ("BuhovoRun класиране").
 *  6. Stray separators and repeated whitespace.
 *
 * A leading label like "Класиране - 7 Hills Run" is intentionally preserved:
 * the regex requires a separator BEFORE the label word.
 *
 * Returns '' when the title has no usable base name (empty page_title).
 *
 * @param string $title Full post_title.
 * @return string Clean event name, possibly empty.
 */
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

/**
 * Derive a human-readable event name from a legacy-page base slug.
 *
 * Fallback used when tsr_event_base_name() returns '' (the WXR page had no
 * visible title — e.g. xmas-run-15-results → "Xmas Run").
 *
 * Returns '' for known-garbage slugs (pure digits, "untitled", "news", "page").
 *
 * @param string $base Legacy-page base slug (from tsr_slug_base()).
 * @return string Human-readable name, possibly empty.
 */
function tsr_slug_event_name( string $base ): string {
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
	if ( '' === $base || ctype_digit( $base ) || in_array( $lower, array( 'untitled', 'news', 'page' ), true ) ) {
		return '';
	}

	// Title-case each hyphen-separated segment (works for Cyrillic).
	$words = array_map(
		static fn( string $w ): string => mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ),
		explode( '-', $base )
	);
	return implode( ' ', $words );
}

/**
 * Extract the " — 21 км" category/distance suffix from a ts_result post_title.
 *
 * @param string $title Full post_title.
 * @return string The suffix, or '' when the title has none.
 */
function tsr_dist_label_from_title( string $title ): string {
	if ( preg_match( '/ — (.+)$/', $title, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

/**
 * Detect race gender from a ts_result post's slug or title category suffix.
 *
 * Detection order:
 *   1. Latin -m / -f at the very end of the slug ("19km-m", "6km-f").
 *   2. Cyrillic мъже / жени anywhere in the slug (Unicode-slug sites).
 *   3. Cyrillic МЪЖЕ / ЖЕНИ anywhere in the post title (always reliable,
 *      including bare-slug first sections whose slug has no gender marker).
 *
 * @param WP_Post $post ts_result post.
 * @return string 'M', 'F', or '' when undetermined.
 */
function tsr_race_gender( WP_Post $post ): string {
	if ( preg_match( '/-m$/i', $post->post_name ) ) {
		return 'M';
	}
	if ( preg_match( '/-f$/i', $post->post_name ) ) {
		return 'F';
	}
	// Cyrillic slug parts are stored URL-encoded in post_name — decode first.
	$slug = mb_strtolower( urldecode( $post->post_name ), 'UTF-8' );
	if ( str_contains( $slug, 'мъже' ) ) {
		return 'M';
	}
	if ( str_contains( $slug, 'жени' ) ) {
		return 'F';
	}
	$title = mb_strtoupper( $post->post_title, 'UTF-8' );
	if ( str_contains( $title, 'МЪЖЕ' ) ) {
		return 'M';
	}
	if ( str_contains( $title, 'ЖЕНИ' ) ) {
		return 'F';
	}
	return '';
}

/**
 * Site-wide ordering rule for any listing of multiple ts_result posts side
 * by side (homepage teaser, event-history editions, category indexes, …):
 * longest distance first, and men before women within a distance — most of
 * the field is men, so their results lead (ADR-004). Sort key precedence:
 *
 *   1. _tsr_distance_km (backfill-meta), descending. Two posts with no
 *      distance meta both read 0.0 and fall through to rule 2.
 *   2. tsr_race_gender(): 'M' before 'F' before undetermined ('').
 *
 * Anything the rule can't order (equal distance, equal/undetermined gender)
 * keeps its incoming relative order — usort() in PHP 8 is stable, so this
 * never shuffles ties.
 *
 * @param list<WP_Post> $posts ts_result posts to order. Not mutated.
 * @return list<WP_Post> The same posts, re-ordered.
 */
function tsr_sort_results_by_distance_gender( array $posts ): array {
	static $gender_rank = array( 'M' => 0, 'F' => 1, '' => 2 );

	usort(
		$posts,
		static function ( WP_Post $a, WP_Post $b ) use ( $gender_rank ): int {
			$km_a = (float) get_post_meta( $a->ID, '_tsr_distance_km', true );
			$km_b = (float) get_post_meta( $b->ID, '_tsr_distance_km', true );
			if ( $km_a !== $km_b ) {
				return $km_b <=> $km_a; // descending: longest first.
			}
			return $gender_rank[ tsr_race_gender( $a ) ] <=> $gender_rank[ tsr_race_gender( $b ) ];
		}
	);

	return $posts;
}

/**
 * Same ordering rule as tsr_sort_results_by_distance_gender() (ADR-004), for
 * callers that already reduced posts to scalar km/gender pairs instead of
 * WP_Post objects — e.g. page-event.php's editions list, which is
 * transient-cached and deliberately holds scalars only, never post objects.
 *
 * @param list<array{km:float,gender:string}> $items Items to order. Not mutated.
 * @return list<array{km:float,gender:string}> The same items, re-ordered.
 */
function tsr_sort_by_distance_gender_scalars( array $items ): array {
	static $gender_rank = array( 'M' => 0, 'F' => 1, '' => 2 );

	usort(
		$items,
		static function ( array $a, array $b ) use ( $gender_rank ): int {
			if ( $a['km'] !== $b['km'] ) {
				return $b['km'] <=> $a['km']; // descending: longest first.
			}
			return $gender_rank[ $a['gender'] ] <=> $gender_rank[ $b['gender'] ];
		}
	);

	return $items;
}

/**
 * Convert a finish-time string to total seconds for comparison.
 *
 * Accepts H:MM:SS only — TSR_Schema::TIME_PATTERN guarantees every stored
 * finish_time is that shape (or ''), so a laxer parse can only ever
 * misread malformed data as a fast time.
 *
 * @param string $time Raw finish time from a result row.
 * @return int Total seconds, or PHP_INT_MAX when empty/unparseable (sorts last).
 */
function tsr_time_to_seconds( string $time ): int {
	$p = explode( ':', trim( $time ) );
	if ( 3 !== count( $p ) ) {
		return PHP_INT_MAX;
	}
	return (int) $p[0] * 3600 + (int) $p[1] * 60 + (int) $p[2];
}
