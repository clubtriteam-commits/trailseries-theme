<?php
declare( strict_types=1 );
/**
 * Official season standings as published on the old site (audit H1 step 2).
 *
 * The owner decided (2026-11-13, audit Q2) that past seasons show the
 * official final standings exactly as the old site published them, not a
 * recalculation with today's rules. migration/extract_official_standings.py
 * turns the old pages into one JSON document per season;
 * `wp tsr import-standings` validates them and stores each in its own
 * option (not autoloaded — only the season being viewed is ever loaded).
 *
 * Document shape (validate() is the contract):
 *   season int (series-season key), label string, source_slug string,
 *   kind 'full'|'winners', has_podiums bool, notes list<string>,
 *   m / f: list<{rank int ≥1, name non-empty string, points int ≥0, podiums int|null}>
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Official_Standings {

	public const OPTION_PREFIX = 'tsr_official_standings_';
	public const INDEX_OPTION  = 'tsr_official_standings_index';

	/**
	 * Check one season document; returns it normalised (types cast).
	 *
	 * @param mixed $doc Decoded JSON.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException On any shape error.
	 */
	public static function validate( mixed $doc ): array {
		if ( ! is_array( $doc ) ) {
			throw new InvalidArgumentException( 'standings: not an object' );
		}
		$season = $doc['season'] ?? null;
		if ( ! is_int( $season ) || $season < 2012 || $season > 2100 ) {
			throw new InvalidArgumentException( 'standings: bad season' );
		}
		$kind = $doc['kind'] ?? '';
		if ( ! in_array( $kind, array( 'full', 'winners' ), true ) ) {
			throw new InvalidArgumentException( "standings {$season}: bad kind" );
		}
		$out = array(
			'season'      => $season,
			'label'       => (string) ( $doc['label'] ?? '' ),
			'source_slug' => (string) ( $doc['source_slug'] ?? '' ),
			'kind'        => $kind,
			'has_podiums' => (bool) ( $doc['has_podiums'] ?? false ),
			'notes'       => array_values( array_map( 'strval', (array) ( $doc['notes'] ?? array() ) ) ),
		);
		foreach ( array( 'm', 'f' ) as $g ) {
			if ( ! isset( $doc[ $g ] ) || ! is_array( $doc[ $g ] ) || array() === $doc[ $g ] ) {
				throw new InvalidArgumentException( "standings {$season}: no {$g} rows" );
			}
			$out[ $g ] = array();
			foreach ( $doc[ $g ] as $i => $row ) {
				$rank    = $row['rank'] ?? null;
				$name    = trim( (string) ( $row['name'] ?? '' ) );
				$points  = $row['points'] ?? null;
				$podiums = $row['podiums'] ?? null;
				if ( ! is_int( $rank ) || $rank < 1 || '' === $name || ! is_int( $points ) || $points < 0
					|| ( null !== $podiums && ( ! is_int( $podiums ) || $podiums < 0 ) ) ) {
					throw new InvalidArgumentException( "standings {$season}: bad {$g} row " . ( $i + 1 ) );
				}
				$out[ $g ][] = array(
					'rank'    => $rank,
					'name'    => $name,
					'points'  => $points,
					'podiums' => $podiums,
				);
			}
		}
		return $out;
	}

	/**
	 * Stored document for a season, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $season ): ?array {
		$index = (array) get_option( self::INDEX_OPTION, array() );
		if ( ! isset( $index[ $season ] ) ) {
			return null;
		}
		$doc = get_option( self::OPTION_PREFIX . $season, null );
		return is_array( $doc ) ? $doc : null;
	}

	/**
	 * Seasons with a stored document: season => kind.
	 *
	 * @return array<int, string>
	 */
	public static function seasons(): array {
		return array_map( 'strval', (array) get_option( self::INDEX_OPTION, array() ) );
	}

	/**
	 * Store a validated document (and register it in the index).
	 *
	 * @param array<string, mixed> $doc Output of validate().
	 */
	public static function store( array $doc ): void {
		update_option( self::OPTION_PREFIX . $doc['season'], $doc, false );
		$index                   = (array) get_option( self::INDEX_OPTION, array() );
		$index[ $doc['season'] ] = $doc['kind'];
		ksort( $index );
		update_option( self::INDEX_OPTION, $index, false );
	}
}
