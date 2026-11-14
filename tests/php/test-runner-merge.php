<?php
declare( strict_types=1 );
/**
 * TSR_Runner_Merge — automatic merging of spellings (invented names).
 */

function tsr_test_merge_row( string $name, string $race, int $year = 2020, ?int $age = null, string $bib = '' ): array {
	return array( 'name' => $name, 'race' => $race, 'year' => $year, 'age' => $age, 'bib' => $bib );
}

test( 'merge: fold_key ignores script, spelling variants and word order', static function (): void {
	eq( TSR_Runner_Merge::fold_key( 'Тестан Пробов' ), TSR_Runner_Merge::fold_key( 'Testan Probov' ), 'cyr = lat' );
	eq( TSR_Runner_Merge::fold_key( 'Probov Testan' ), TSR_Runner_Merge::fold_key( 'testan  PROBOV' ), 'order and case' );
	eq( TSR_Runner_Merge::fold_word( 'Илиян' ), TSR_Runner_Merge::fold_word( 'Ilian' ), 'iy = i' );
	eq( TSR_Runner_Merge::fold_word( 'Цветков' ), TSR_Runner_Merge::fold_word( 'Tzvetkow' ), 'ts/tz, v/w' );
	eq( TSR_Runner_Merge::fold_word( 'Вълчанов' ), TSR_Runner_Merge::fold_word( 'Valchanov' ), 'ъ = a' );
	eq( TSR_Runner_Merge::fold_key( 'Testan Probov' ) === TSR_Runner_Merge::fold_key( 'Testan Probev' ), false, 'different surname' );
} );

test( 'merge: Cyrillic and Latin spellings map to the most frequent one', static function (): void {
	$plan = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Тестана Пробова', 'a|2014' ),
		tsr_test_merge_row( 'Testana Probova', 'a|2018' ),
		tsr_test_merge_row( 'Testana Probova', 'b|2019' ),
		tsr_test_merge_row( 'testana probova', 'c|2019' ),
		tsr_test_merge_row( 'Other Person', 'a|2018' ),
	) );
	eq( TSR_Runner_Alias::canonical( 'Тестана Пробова', $plan['map'] ), 'Testana Probova', 'cyrillic → latin' );
	eq( TSR_Runner_Alias::canonical( 'TESTANA PROBOVA', $plan['map'] ), 'Testana Probova', 'case variant' );
	eq( TSR_Runner_Alias::canonical( 'Other Person', $plan['map'] ), 'Other Person', 'single spelling untouched' );
	eq( count( $plan['merged'] ), 1, 'one merged group' );
} );

test( 'merge: two spellings in the same race are two people', static function (): void {
	$plan = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Тестан Пробов', 'a|2019' ),
		tsr_test_merge_row( 'Testan Probov', 'a|2019' ),
	) );
	eq( $plan['map'], array(), 'nothing merged' );
	eq( $plan['skipped'][0]['reason'], 'same-race', 'reason' );
} );

test( 'merge: the same spelling twice in a race does not block a merge', static function (): void {
	$plan = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Testan Probov', 'a|2018' ),
		tsr_test_merge_row( 'Testan Probov', 'a|2018' ), // duplicated table
		tsr_test_merge_row( 'Тестан Пробов', 'b|2014' ),
	) );
	eq( TSR_Runner_Alias::canonical( 'Тестан Пробов', $plan['map'] ), 'Testan Probov', 'merged' );
} );

test( 'merge: contradicting ages block, bib-as-age is ignored', static function (): void {
	$plan = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Тестан Пробов', 'a|2015', 2015, 25 ),
		tsr_test_merge_row( 'Testan Probov', 'b|2019', 2019, 50 ),
	) );
	eq( $plan['skipped'][0]['reason'], 'age', 'born 1990 vs 1969' );
	$plan = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Тестан Пробов', 'a|2015', 2015, 25 ),
		tsr_test_merge_row( 'Testan Probov', 'b|2019', 2019, 30 ),
		tsr_test_merge_row( 'Testan Probov', 'c|2020', 2020, 77, '77' ),
	) );
	eq( count( $plan['merged'] ), 1, 'consistent ages merge; age equal to bib does not count' );
} );

test( 'merge: one-word names and blocked names are never merged', static function (): void {
	$plan = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Тестан', 'a|2015' ),
		tsr_test_merge_row( 'Testan', 'b|2019' ),
	) );
	eq( $plan['map'], array(), 'one word' );
	$rows = array( tsr_test_merge_row( 'Тестан Пробов', 'a|2015' ), tsr_test_merge_row( 'Testan Probov', 'b|2019' ) );
	$plan = TSR_Runner_Merge::plan( $rows, array(), array( TSR_Runner_Merge::fold_key( 'Testan Probov' ) ) );
	eq( $plan['map'], array(), 'blocked' );
	eq( $plan['skipped'][0]['reason'], 'blocked', 'reason' );
} );

test( 'merge: an owner alias decides the canonical name and always wins', static function (): void {
	$manual = TSR_Runner_Alias::with( array(), 'Testan Probov-fast', 'Тестан Пробов' );
	$plan   = TSR_Runner_Merge::plan( array(
		tsr_test_merge_row( 'Testan Probov', 'a|2018' ),
		tsr_test_merge_row( 'Testan Probov', 'b|2019' ),
		tsr_test_merge_row( 'Тестан Пробов', 'c|2014' ),
	), $manual );
	$map = TSR_Runner_Merge::effective( $manual, $plan['map'] );
	eq( TSR_Runner_Alias::canonical( 'Testan Probov', $map ), 'Тестан Пробов', 'owner-chosen name, not the most frequent' );
	eq( TSR_Runner_Alias::canonical( 'testan probov-fast', $map ), 'Тестан Пробов', 'manual alias kept' );
} );
