<?php
declare( strict_types=1 );
/**
 * event-heuristics.php — cases are the examples written in each function's
 * docblock (the documented contract), plus edge cases.
 */

test( 'tsr_title_year: apostrophe and 4-digit years, category suffix ignored', static function (): void {
	eq( tsr_title_year( "Baba Marta Run'25 - Results — 16KM МЪЖЕ" ), 2025 );
	eq( tsr_title_year( "Baba Marta Run ranking'16" ), 2016 );
	eq( tsr_title_year( "The Cactus Run\u{2019}23" ), 2023 );
	eq( tsr_title_year( '7 Hills Run 2024' ), 2024 );
	eq( tsr_title_year( 'BuhovoRun класиране' ), null );
	eq( tsr_title_year( 'Xmas Run — 2015 note' ), null, 'year only in the suffix' );
} );

test( 'tsr_slug_year: documented examples', static function (): void {
	eq( tsr_slug_year( 'heat-stroke-run-14-07-2013' ), 2013 );
	eq( tsr_slug_year( 'buhovorun-ranking-2014' ), 2014 );
	eq( tsr_slug_year( '7-hills-run15-ranking' ), 2015 );
	eq( tsr_slug_year( 'iran-run-results15' ), 2015 );
	eq( tsr_slug_year( 'pancharevo-night-run-класиране16' ), 2016 );
	eq( tsr_slug_year( 'golyam-sechko-run18' ), 2018 );
	eq( tsr_slug_year( 'xmas-run-15-results' ), 2015 );
} );

test( 'tsr_slug_year: day-of-month numbers are not years', static function (): void {
	eq( tsr_slug_year( 'vladaya-21-april' ), null );
	eq( tsr_slug_year( 'buhovo-26-may' ), null );
	eq( tsr_slug_year( 'lokorsko-23-fevruari' ), null );
	eq( tsr_slug_year( 'pasarel-run-16-06' ), null );
} );

test( 'tsr_event_base_name: documented stripping rules', static function (): void {
	eq( tsr_event_base_name( "Iran Run'25 - Results" ), 'Iran Run' );
	eq( tsr_event_base_name( '7 Hills Run 2024 - Ranking' ), '7 Hills Run' );
	eq( tsr_event_base_name( 'BuhovoRun класиране' ), 'BuhovoRun' );
	eq( tsr_event_base_name( "Malak Sechko Run'22 Results — 19КМ МЪЖЕ" ), 'Malak Sechko Run' );
	eq( tsr_event_base_name( 'Класиране - 7 Hills Run' ), 'Класиране - 7 Hills Run', 'leading label is kept' );
	eq( tsr_event_base_name( '' ), '' );
} );

test( 'tsr_slug_event_name: fallback names and garbage slugs', static function (): void {
	eq( tsr_slug_event_name( 'xmas-run-15-results' ), 'Xmas Run' );
	eq( tsr_slug_event_name( 'news' ), '' );
	eq( tsr_slug_event_name( '1539' ), '' );
	eq( tsr_slug_event_name( 'untitled' ), '' );
} );

test( 'tsr_dist_label_from_title and tsr_time_to_seconds', static function (): void {
	eq( tsr_dist_label_from_title( 'Iran Run — 21 км' ), '21 км' );
	eq( tsr_dist_label_from_title( 'Iran Run' ), '' );
	eq( tsr_time_to_seconds( '1:02:03' ), 3723 );
	eq( tsr_time_to_seconds( '' ), PHP_INT_MAX );
	eq( tsr_time_to_seconds( '12:34' ), PHP_INT_MAX, 'only H:MM:SS is accepted' );
} );

test( 'tsr_race_gender: _tsr_gender meta wins; mixed means undetermined', static function (): void {
	$GLOBALS['tsr_test_meta'][1]['_tsr_gender'] = 'F';
	eq( tsr_race_gender( new WP_Post( 1, 'x-results-19km-m', 'X — 19KM МЪЖЕ' ) ), 'F', 'meta beats slug/title' );
	$GLOBALS['tsr_test_meta'][2]['_tsr_gender'] = 'mixed';
	eq( tsr_race_gender( new WP_Post( 2, 'x-relay-m', 'X — ЩАФЕТА МЪЖЕ' ) ), '', 'mixed never scores' );
} );

test( 'tsr_race_gender_from_text: slug and title rules (posts without meta)', static function (): void {
	eq( tsr_race_gender( new WP_Post( 3, 'iran-run18-results-16-7km-m', 'Iran' ) ), 'M' );
	eq( tsr_race_gender( new WP_Post( 4, 'iran-run18-results-6km-f', 'Iran' ) ), 'F' );
	eq( tsr_race_gender( new WP_Post( 5, rawurlencode( 'run-15км-мъже' ), 'Run' ) ), 'M', 'encoded Cyrillic slug' );
	eq( tsr_race_gender( new WP_Post( 6, 'run', 'Run — 11KM ЖЕНИ' ) ), 'F' );
	eq( tsr_race_gender( new WP_Post( 7, 'run', 'Run — 11KM' ) ), '' );
} );

test( 'tsr_sort_by_distance_gender_scalars: longest first, men before women, stable', static function (): void {
	$sorted = tsr_sort_by_distance_gender_scalars( array(
		array( 'km' => 6.0, 'gender' => 'F', 'id' => 'a' ),
		array( 'km' => 19.0, 'gender' => '', 'id' => 'b' ),
		array( 'km' => 6.0, 'gender' => 'M', 'id' => 'c' ),
		array( 'km' => 19.0, 'gender' => 'F', 'id' => 'd' ),
		array( 'km' => 19.0, 'gender' => 'M', 'id' => 'e' ),
	) );
	eq( array_column( $sorted, 'id' ), array( 'e', 'd', 'b', 'c', 'a' ) );
} );
