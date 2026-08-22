<?php
declare( strict_types=1 );
/**
 * Runner status — closed set. A row can never carry an ad-hoc status string.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum TSR_Status: string {
	case Finished       = 'FIN';
	case FinishedNoTime = 'FNT';
	case DidNotFinish   = 'DNF';
	case DidNotStart    = 'DNS';
	case Disqualified   = 'DSQ';
	case OverTimeLimit  = 'OTL';

	public function label(): string {
		return match ( $this ) {
			self::Finished       => __( 'Finished', 'trailseries-results' ),
			// Distinct from Finished: the runner completed the course (often
			// a short, untimed kids' category) but no finish time was ever
			// recorded in the source data — not the same claim as DNF, which
			// asserts the runner did NOT complete the course.
			self::FinishedNoTime => __( 'Finished (no time recorded)', 'trailseries-results' ),
			self::DidNotFinish   => __( 'DNF', 'trailseries-results' ),
			self::DidNotStart    => __( 'DNS', 'trailseries-results' ),
			self::Disqualified   => __( 'DSQ', 'trailseries-results' ),
			self::OverTimeLimit  => __( 'Over time limit', 'trailseries-results' ),
		};
	}
}
