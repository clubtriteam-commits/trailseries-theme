<?php
declare( strict_types=1 );
/**
 * TSR_Scoring — bonus races of season 14 (2026).
 */

test( 'scoring: a bonus race scores by distance rank, longest first', static function (): void {
	$hills = array( 26.0, 19.0, 19.0, 13.0, 13.0, 6.0, 6.0 ); // men + women tables
	eq( TSR_Scoring::bonus_max( $hills, 26.0 ), 20, '26 km' );
	eq( TSR_Scoring::bonus_max( $hills, 19.0 ), 15, '19 km' );
	eq( TSR_Scoring::bonus_max( $hills, 13.0 ), 10, '13 km' );
	eq( TSR_Scoring::bonus_max( $hills, 6.0 ), 5, '6 km' );
	eq( TSR_Scoring::bonus_max( array( 21.0, 21.0, 15.0, 15.0, 7.0, 7.0 ), 7.0 ), 10, 'third of three distances' );
	eq( TSR_Scoring::bonus_max( array( 21.0, 21.0, 10.0, 10.0 ), 10.0 ), 15, 'second of two distances' );
} );

test( 'scoring: unknown, fifth and near-equal distances', static function (): void {
	eq( TSR_Scoring::bonus_max( array( 21.0, 10.0 ), 0.0 ), 0, 'no distance' );
	eq( TSR_Scoring::bonus_max( array( 21.0, 10.0 ), 15.0 ), 0, 'not a distance of the race' );
	eq( TSR_Scoring::bonus_max( array( 30.0, 25.0, 20.0, 15.0, 10.0 ), 10.0 ), 0, 'fifth distance' );
	eq( TSR_Scoring::bonus_max( array( 15.1, 15.0, 7.0 ), 7.0 ), 15, '15 and 15.1 km are one distance' );
} );

test( 'scoring: points are max + 1 − place inside the window', static function (): void {
	eq( TSR_Scoring::points( 1, 20 ), 20, 'winner' );
	eq( TSR_Scoring::points( 20, 20 ), 1, 'place equal to the maximum' );
	eq( TSR_Scoring::points( 21, 20 ), 0, 'outside' );
	eq( TSR_Scoring::points( 5, 5 ), 1, 'short window' );
	eq( TSR_Scoring::points( 0, 20 ), 0, 'no place' );
	eq( TSR_Scoring::points( 3, 0 ), 0, 'no maximum' );
} );
