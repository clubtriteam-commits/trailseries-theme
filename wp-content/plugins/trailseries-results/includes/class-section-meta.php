<?php
declare( strict_types=1 );
/**
 * Scoring/grouping meta for one results section (audit B3/B4).
 *
 * Pure functions — no WordPress calls beyond the shared heuristics in
 * event-heuristics.php — so they are unit-tested (tests/php). Used by the
 * CLI's bulk-import and backfill-meta. Moved out of TSR_CLI unchanged on
 * 2026-11-13 (audit H10).
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Section_Meta {

	/**
	 * Keys bulk-import may DELETE when the derivation yields nothing: distance,
	 * category and gender come from the manifest, so a stale value there is
	 * wrong by definition. Season and event name are only ever written, never
	 * deleted — 143 sections from 2012–2016 carry a season that neither the
	 * page title nor the slug contains (dry run 2026-11-13).
	 */
	public const IMPORT_DELETABLE_META = array( '_tsr_distance_km', '_tsr_distance_cat', '_tsr_gender' );

	private function __construct() {}

	/**
	 * Derive the meta for one section.
	 *
	 *   label    — the corrected category: override_applied when present,
	 *              else category_raw (B3; the caller picks). Post title suffix.
	 *   km       — manifest distance_km → category map (normalised key) →
	 *              "NN km" in the label (or in the page title when the section
	 *              has no label). Never from the slug: sanitize_title() and the
	 *              Python slugify turn "11.5km" into "11-5km" / "115km" (B4).
	 *   gender   — relay/team label (щафета, отбор, relay, team) or kids'
	 *              label (деца, детско, kids, момчета, момичета…) → mixed;
	 *              else manifest gender → category map → МЪЖЕ / ЖЕНИ in the
	 *              label (both present → mixed). 'M', 'F', 'mixed' or ''.
	 *   season / event_base — from the PAGE title and slug only, never from the
	 *              label: override labels such as "Крайно класиране" must not
	 *              leak into the year or the event name.
	 *
	 * @param array<string, array{km: string, gender: string}> $map From read_category_map().
	 * @return array{label: string, km: ?string, cat: ?string, gender: string, season: ?string, event_base: ?string}
	 */
	public static function derive(
		string $page_title,
		string $page_slug,
		string $label,
		string $manifest_km,
		string $manifest_gender,
		array $map
	): array {
		$label  = trim( $label );
		$mapped = ( '' !== $label ) ? ( $map[ self::norm_cat_key( $label ) ] ?? null ) : null;

		$km = null;
		if ( is_numeric( trim( $manifest_km ) ) ) {
			$km = (float) trim( $manifest_km );
		} elseif ( null !== $mapped && is_numeric( $mapped['km'] ) ) {
			$km = (float) $mapped['km'];
		} else {
			$km = self::parse_km( '' !== $label ? $label : $page_title );
		}

		$valid  = array( 'M', 'F', 'mixed' );
		$gender = in_array( trim( $manifest_gender ), $valid, true ) ? trim( $manifest_gender ) : '';
		if ( '' === $gender && null !== $mapped && in_array( $mapped['gender'], $valid, true ) ) {
			$gender = $mapped['gender'];
		}
		// Relay / team sections list teams, not runners: never in the
		// individual standings, whatever gender the label names
		// ("ЩАФЕТА - ЖЕНИ" is a women's relay, still not individual results).
		if ( '' !== $label && preg_match( '/щафет|отбор|relay|team/iu', $label ) ) {
			$gender = 'mixed';
		}
		// Kids' races never count in the adult standings either (the
		// standings skip undetermined gender "kids, mixed, etc." —
		// page-klasiraniya.php), even when the heading says "деца Мъже".
		if ( '' !== $label && preg_match( '/деца|детск|kids|момчета|момичета|джудже|джуджан/iu', $label ) ) {
			$gender = 'mixed';
		}
		if ( '' === $gender && '' !== $label ) {
			$upper  = mb_strtoupper( $label, 'UTF-8' );
			$men    = str_contains( $upper, 'МЪЖЕ' );
			$women  = str_contains( $upper, 'ЖЕНИ' );
			$gender = $men && $women ? 'mixed' : ( $men ? 'M' : ( $women ? 'F' : '' ) );
		}

		$season     = tsr_title_year( $page_title ) ?? tsr_slug_year( $page_slug );
		$event_base = tsr_event_base_name( $page_title );
		if ( '' === $event_base ) {
			$event_base = tsr_slug_event_name( $page_slug );
		}

		return array(
			'label'      => $label,
			// Two decimals: 10.75 km must not become 10.8 (one decimal did that).
			'km'         => null !== $km ? rtrim( rtrim( sprintf( '%.2f', $km ), '0' ), '.' ) : null,
			'cat'        => null !== $km ? self::km_to_cat( $km ) : null,
			'gender'     => $gender,
			'season'     => null !== $season ? (string) $season : null,
			'event_base' => '' !== $event_base ? $event_base : null,
		);
	}

	/**
	 * The derived meta as post-meta key => value. A null value means "could not
	 * be derived": bulk-import deletes the key if it is in
	 * IMPORT_DELETABLE_META and keeps it otherwise; backfill-meta leaves it alone.
	 *
	 * @param array{label: string, km: ?string, cat: ?string, gender: string, season: ?string, event_base: ?string} $meta
	 * @return array<string, ?string>
	 */
	public static function meta_writes( array $meta ): array {
		return array(
			'_tsr_distance_km'  => $meta['km'],
			'_tsr_distance_cat' => $meta['cat'],
			'_tsr_gender'       => '' !== $meta['gender'] ? $meta['gender'] : null,
			'_tsr_event_base'   => $meta['event_base'],
			'_tsr_season'       => $meta['season'],
		);
	}

	/**
	 * Parse category-map.csv into normalised-header → {km, gender}.
	 *
	 * Keys are produced by norm_cat_key() so lookups tolerate case and
	 * Latin/Cyrillic KM/КМ variation. km may be '' for headers whose
	 * distance is unknown ("Мъже", "Жени" — marked "fill in manually").
	 *
	 * @return array<string, array{km: string, gender: string}>
	 */
	public static function read_category_map( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$headers = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! $headers ) {
			fclose( $handle );
			return array();
		}
		if ( str_starts_with( $headers[0], "\xEF\xBB\xBF" ) ) {
			$headers[0] = substr( $headers[0], 3 );
		}

		$map = array();
		while ( false !== ( $fields = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			if ( count( $fields ) !== count( $headers ) ) {
				continue;
			}
			$row = array_combine( $headers, $fields );
			if ( '' === trim( $row['raw_header'] ) ) {
				continue;
			}
			$map[ self::norm_cat_key( $row['raw_header'] ) ] = array(
				'km'     => trim( $row['canonical_distance_km'] ),
				'gender' => trim( $row['gender'] ?? '' ),
			);
		}

		fclose( $handle );
		return $map;
	}

	/**
	 * Normalise a category header for map lookup: collapse whitespace,
	 * uppercase, and unify Latin K/M with Cyrillic К/М so "15KM МЪЖЕ"
	 * and "15КМ МЪЖЕ" produce the same key.
	 */
	public static function norm_cat_key( string $s ): string {
		$s = mb_strtoupper( trim( (string) preg_replace( '/\s+/u', ' ', $s ) ), 'UTF-8' );
		return strtr( $s, array( 'K' => 'К', 'M' => 'М' ) );
	}

	/**
	 * Extract the " — {label}" suffix that bulk-import appends to post_title.
	 * Uses the LAST em-dash separator: a label never contains one, but a
	 * legacy page_title might.
	 *
	 * @return string The label, or '' when the title has no suffix.
	 */
	public static function title_category_suffix( string $title ): string {
		$pos = mb_strrpos( $title, ' — ' );
		if ( false === $pos ) {
			return '';
		}
		return trim( mb_substr( $title, $pos + 3 ) );
	}

	/**
	 * Fallback distance extraction: first "NN km" / "NN.N КМ" token in the text.
	 * Comma decimal separators are accepted ("16,7км" → 16.7).
	 */
	public static function parse_km( string $text ): ?float {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*(?:km|км)/iu', $text, $m ) ) {
			return (float) str_replace( ',', '.', $m[1] );
		}
		return null;
	}

	/**
	 * Map a distance to its scoring category.
	 *
	 * Boundaries (confirmed with the series organiser, 2026-07):
	 *   <8 short · 8–13.9 medium · 14–20.9 long · 21+ bonus
	 */
	public static function km_to_cat( float $km ): string {
		if ( $km < 8 ) {
			return 'short';
		}
		if ( $km < 14 ) {
			return 'medium';
		}
		if ( $km < 21 ) {
			return 'long';
		}
		return 'bonus';
	}
}
