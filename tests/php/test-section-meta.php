<?php
declare( strict_types=1 );
/**
 * TSR_Section_Meta (audit B3/B4) — one case per rule; several were bugs
 * caught by dry runs on 2026-11-13.
 */

function tsr_test_map(): array {
	return TSR_Section_Meta::read_category_map( TSR_TEST_PLUGIN . 'data/category-map.csv' );
}

test( 'category map: plugin copy loads, keys normalised (Latin K/M = Cyrillic К/М)', static function (): void {
	$map = tsr_test_map();
	eq( count( $map ) > 30, true, 'entries' );
	eq( TSR_Section_Meta::norm_cat_key( '15KM  мъже' ), TSR_Section_Meta::norm_cat_key( '15КМ МЪЖЕ' ) );
	eq( $map[ TSR_Section_Meta::norm_cat_key( '6КМ МЪЖЕ' ) ], array( 'km' => '6', 'gender' => 'M' ) );
} );

test( 'plugin category map is identical to migration/category-map.csv', static function (): void {
	eq(
		TSR_Section_Meta::read_category_map( TSR_TEST_PLUGIN . 'data/category-map.csv' ),
		TSR_Section_Meta::read_category_map( TSR_TEST_ROOT . '/migration/category-map.csv' )
	);
} );

test( 'distance: manifest first, then map, then label; never the slug', static function (): void {
	$map = tsr_test_map();
	eq( TSR_Section_Meta::derive( "Run'18", 'run18', '6КМ МЪЖЕ', '6.5', '', $map )['km'], '6.5', 'manifest wins' );
	eq( TSR_Section_Meta::derive( "Run'18", 'run18', '6KM мъже', '', '', $map )['km'], '6', 'map via normalised key' );
	eq( TSR_Section_Meta::derive( "Run'18", 'run18', '16,7км Мъже', '', '', $map )['km'], '16.7', 'label, comma decimal' );
	eq( TSR_Section_Meta::derive( "Run'18", 'iran-run18-results--115km-m', '', '', '', $map )['km'], null, 'slug never read (B4)' );
} );

test( 'distance keeps two decimals (10.75 must not become 10.8)', static function (): void {
	$meta = TSR_Section_Meta::derive( "Palakaria'19", 'palakaria19-ranking', '10.75КМ', '', '', array() );
	eq( $meta['km'], '10.75' );
	eq( $meta['cat'], 'medium' );
} );

test( 'km_to_cat boundaries', static function (): void {
	eq( TSR_Section_Meta::km_to_cat( 7.99 ), 'short' );
	eq( TSR_Section_Meta::km_to_cat( 8.0 ), 'medium' );
	eq( TSR_Section_Meta::km_to_cat( 13.99 ), 'medium' );
	eq( TSR_Section_Meta::km_to_cat( 14.0 ), 'long' );
	eq( TSR_Section_Meta::km_to_cat( 20.99 ), 'long' );
	eq( TSR_Section_Meta::km_to_cat( 21.0 ), 'bonus' );
} );

test( 'gender: label words, both words = mixed', static function (): void {
	eq( TSR_Section_Meta::derive( 'R', 'r', '19КМ МЪЖЕ', '', '', array() )['gender'], 'M' );
	eq( TSR_Section_Meta::derive( 'R', 'r', '19км Жени', '', '', array() )['gender'], 'F' );
	eq( TSR_Section_Meta::derive( 'R', 'r', 'Мъже и жени', '', '', array() )['gender'], 'mixed' );
	eq( TSR_Section_Meta::derive( 'R', 'r', '19KM', '', '', array() )['gender'], '' );
} );

test( 'gender: relay and team sections never score, even with a gender word', static function (): void {
	foreach ( array( 'ЩАФЕТА - ЖЕНИ', 'МЪЖКА ЩАФЕТА', 'Мъжки отбори', 'Отбори', 'Relay men', 'Team' ) as $label ) {
		eq( TSR_Section_Meta::derive( 'R', 'r', $label, '', 'F', array() )['gender'], 'mixed', $label );
	}
} );

test( 'gender: kids sections never score, even with a gender word', static function (): void {
	foreach ( array( '1000м деца Мъже', 'Деца Жени', 'Детско бягане', 'KIDS RUN Winners', 'Kids Run Момичета', 'ДЕЦА 1КМ Момчета', '1КМ ДЖУДЖЕТА' ) as $label ) {
		eq( TSR_Section_Meta::derive( 'R', 'r', $label, '', 'M', array() )['gender'], 'mixed', $label );
	}
} );

test( 'season and event come from the PAGE title/slug, never the label', static function (): void {
	$meta = TSR_Section_Meta::derive( "Iran Run'18 - Results", 'iran-run18-results', 'Класиране 2019 Крайно', '', '', array() );
	eq( $meta['season'], '2018' );
	eq( $meta['event_base'], 'Iran Run' );
	$meta = TSR_Section_Meta::derive( '', 'xmas-run-15-results', '15KM МЪЖЕ', '', '', array() );
	eq( $meta['season'], '2015', 'slug fallback' );
	eq( $meta['event_base'], 'Xmas Run', 'slug fallback' );
} );

test( 'meta_writes and the import delete set: season/event are never deletable', static function (): void {
	$w = TSR_Section_Meta::meta_writes( TSR_Section_Meta::derive( '', 'news', 'Отбори', '', '', array() ) );
	eq( $w['_tsr_gender'], 'mixed' );
	eq( $w['_tsr_distance_km'], null );
	eq( in_array( '_tsr_season', TSR_Section_Meta::IMPORT_DELETABLE_META, true ), false );
	eq( in_array( '_tsr_event_base', TSR_Section_Meta::IMPORT_DELETABLE_META, true ), false );
	eq( TSR_Section_Meta::meta_writes( TSR_Section_Meta::derive( 'R', 'r', '19KM', '', '', array() ) )['_tsr_gender'], null, "'' gender is not written" );
} );

test( 'title_category_suffix uses the last em-dash', static function (): void {
	eq( TSR_Section_Meta::title_category_suffix( 'Run — part — 16KM МЪЖЕ' ), '16KM МЪЖЕ' );
	eq( TSR_Section_Meta::title_category_suffix( 'Run' ), '' );
} );
