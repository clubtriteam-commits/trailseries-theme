<?php
/**
 * Runner status — closed set. A row can never carry an ad-hoc status string.
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum TSR_Status: string {
	case Finished       = 'FIN';
	case DidNotFinish   = 'DNF';
	case DidNotStart    = 'DNS';
	case Disqualified   = 'DSQ';
	case OverTimeLimit  = 'OTL';

	public function label(): string {
		return match ( $this ) {
			self::Finished      => __( 'Finished', 'trailseries-results' ),
			self::DidNotFinish  => __( 'DNF', 'trailseries-results' ),
			self::DidNotStart   => __( 'DNS', 'trailseries-results' ),
			self::Disqualified  => __( 'DSQ', 'trailseries-results' ),
			self::OverTimeLimit => __( 'Over time limit', 'trailseries-results' ),
		};
	}
}
