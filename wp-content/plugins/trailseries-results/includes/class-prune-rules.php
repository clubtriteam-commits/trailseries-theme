<?php
declare( strict_types=1 );
/**
 * Classification of leftover section posts for `wp tsr prune` (audit M4).
 *
 * Pure decision logic, separate from the database work in TSR_CLI::prune(),
 * so the rule that once nearly trashed the only copy of the relay results
 * is unit-tested (tests/php). Added 2026-11-13 (audit H10).
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Prune_Rules {

	/** Classes whose posts --trash may move without --ids. */
	public const TRASHABLE = array( 'DUPLICATE', 'OLD', 'SPLIT' );

	private function __construct() {}

	/**
	 * Classify one leftover post against the current (expected) sections of
	 * its page.
	 *
	 *   DUPLICATE — same names hash as a current section.
	 *   OLD       — ≥ 90 % of its runners appear in ONE current section.
	 *   SPLIT     — every runner appears in the current sections together
	 *               (a merged section the extractor now splits).
	 *   EXTRA     — anything else: data the current sections do not hold.
	 *               Never trashable without explicit --ids.
	 *
	 * @param string                                             $hash    Names hash of the leftover ('' if unknown).
	 * @param list<string>                                       $names   Its runner names ("first\x1flast").
	 * @param list<array{id: int, hash: string, names: list<string>}> $current Current sections of the page.
	 * @return array{class: string, match: int, best: float}
	 */
	public static function classify( string $hash, array $names, array $current ): array {
		foreach ( $current as $c ) {
			if ( '' !== $hash && $hash === $c['hash'] ) {
				return array( 'class' => 'DUPLICATE', 'match' => $c['id'], 'best' => 1.0 );
			}
		}
		if ( array() === $names ) {
			// Nothing to compare: never claim it is covered.
			return array( 'class' => 'EXTRA', 'match' => 0, 'best' => 0.0 );
		}

		$best  = 0.0;
		$match = 0;
		$union = array();
		foreach ( $current as $c ) {
			$overlap = count( array_intersect( $names, $c['names'] ) ) / count( $names );
			if ( $overlap > $best ) {
				$best  = $overlap;
				$match = $c['id'];
			}
			$union = array_merge( $union, $c['names'] );
		}
		if ( $best >= 0.9 ) {
			return array( 'class' => 'OLD', 'match' => $match, 'best' => $best );
		}
		if ( array() === array_diff( $names, $union ) ) {
			return array( 'class' => 'SPLIT', 'match' => $match, 'best' => $best );
		}
		return array( 'class' => 'EXTRA', 'match' => $match, 'best' => $best );
	}

	/**
	 * Runner names of a result set as "first\x1flast" strings (byte-exact,
	 * never normalised — iron rule 2), for overlap comparisons.
	 *
	 * @return list<string>
	 */
	public static function row_names( TSR_Result_Set $set ): array {
		$names = array();
		foreach ( $set->rows() as $row ) {
			$names[] = $row->first_name . "\x1f" . $row->last_name;
		}
		return $names;
	}
}
