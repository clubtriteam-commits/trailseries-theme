<?php
declare( strict_types=1 );
/**
 * Bonus-race scoring (owner's rule for season 14 / 2026, docs/AUDIT-2026-11.md Q3).
 *
 * In a bonus race the points do not follow the distance's length category
 * but its rank among the race's own distances: the longest scores up to 20,
 * the second 15, the third 10, the fourth 5. Within a distance the score is
 * max + 1 − place (the winner gets the maximum, place = maximum gets 1).
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Scoring {

	/** Maximum points by distance rank in a bonus race, longest first. */
	public const BONUS_MAX = array( 20, 15, 10, 5 );

	/** Distances closer than this are the same distance (15 vs 15.1 km). */
	private const SAME_KM = 0.25;

	/**
	 * Maximum points of one distance of a bonus race.
	 *
	 * @param list<float> $race_kms Distances of every table of the race (repeats allowed: men, women).
	 * @param float       $km       The distance being scored.
	 * @return int 0 when the distance is unknown or ranks below the fourth.
	 */
	public static function bonus_max( array $race_kms, float $km ): int {
		if ( $km <= 0 ) {
			return 0;
		}
		rsort( $race_kms );
		$rank = 0;
		$prev = null;
		foreach ( $race_kms as $d ) {
			if ( $d <= 0 ) {
				continue;
			}
			if ( null !== $prev && $prev - $d >= self::SAME_KM ) {
				++$rank;
			}
			$prev = $d;
			if ( abs( $d - $km ) < self::SAME_KM ) {
				return self::BONUS_MAX[ $rank ] ?? 0;
			}
		}
		return 0;
	}

	/** Points for a place when the distance scores up to $max. */
	public static function points( int $place, int $max ): int {
		return ( $max < 1 || $place < 1 || $place > $max ) ? 0 : $max + 1 - $place;
	}
}
