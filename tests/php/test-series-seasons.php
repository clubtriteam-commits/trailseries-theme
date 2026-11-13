<?php
declare( strict_types=1 );
/**
 * TSR_Series_Seasons — the owner's season rules (audit H1, 2026-11-13).
 */

test( 'seasons: autumn race opens its own year, spring race belongs to the year before', static function (): void {
	eq( TSR_Series_Seasons::is_valid_split_season( 2018, 2018 ), true, 'Cactus Run 2018 → season 7 (2018)' );
	eq( TSR_Series_Seasons::is_valid_split_season( 2019, 2018 ), true, 'Golyam Sechko 2019 → season 7 (2018)' );
	eq( TSR_Series_Seasons::is_valid_split_season( 2019, 2017 ), false );
	eq( TSR_Series_Seasons::is_valid_split_season( 2018, 2019 ), false );
} );

test( 'seasons: every 2020 race is season 8, every 2012 race season 1', static function (): void {
	eq( TSR_Series_Seasons::is_valid_split_season( 2020, 2019 ), true );
	eq( TSR_Series_Seasons::is_valid_split_season( 2020, 2020 ), false, 'Christmas Run 2020 is still season 8' );
	eq( TSR_Series_Seasons::is_valid_split_season( 2012, 2012 ), true );
	eq( TSR_Series_Seasons::is_valid_split_season( 2012, 2011 ), false );
} );

test( 'seasons: CSV parses, "none" excludes, bad rows are rejected', static function (): void {
	$csv = "page_slug,race_year,series_season,title\n"
		. "cactus-run18-ranking,2018,2018,The Cactus Run'18\n"
		. "golyam-sechko-run19-ranking,2019,2018,Golyam Sechko Run'19\n"
		. "обб-vertical-run-results,2018,none,ОББ Vertical Run\n";
	$rows = TSR_Series_Seasons::parse_csv( $csv );
	eq( $rows['golyam-sechko-run19-ranking'], array( 'race_year' => 2019, 'series_season' => '2018' ) );
	eq( $rows['обб-vertical-run-results']['series_season'], 'none' );
	throws( static fn() => TSR_Series_Seasons::parse_csv( "page_slug,race_year,series_season\nx,2019,2017\n" ), 'impossible season' );
	throws( static fn() => TSR_Series_Seasons::parse_csv( "page_slug,race_year,series_season\nx,2022,2022\n" ), 'not a split-season year' );
	throws( static fn() => TSR_Series_Seasons::parse_csv( "page_slug,race_year,series_season\nx,2018,2018\nx,2018,2018\n" ), 'repeated slug' );
	throws( static fn() => TSR_Series_Seasons::parse_csv( "page_slug,year\nx,2018\n" ), 'missing column' );
} );

test( 'seasons: section posts map to the longest matching page slug', static function (): void {
	$pages = array( 'golyam-sechko-run16-класиране', 'golyam-sechko-run16-класиране-65km', 'malak-sechko-run-ranking' );
	eq( TSR_Series_Seasons::page_for( 'golyam-sechko-run16-класиране-65km-мъже', $pages ), 'golyam-sechko-run16-класиране-65km' );
	eq( TSR_Series_Seasons::page_for( 'golyam-sechko-run16-класиране-жени', $pages ), 'golyam-sechko-run16-класиране' );
	eq( TSR_Series_Seasons::page_for( 'malak-sechko-run-ranking', $pages ), 'malak-sechko-run-ranking' );
	eq( TSR_Series_Seasons::page_for( 'malak-sechko-run-ranking16', $pages ), null, 'no dash → different page' );
} );

test( 'seasons: season key = series season, else race year, none = no season', static function (): void {
	eq( TSR_Series_Seasons::season_key( '2019', '2018' ), 2018 );
	eq( TSR_Series_Seasons::season_key( '2024', '' ), 2024 );
	eq( TSR_Series_Seasons::season_key( '2018', 'none' ), null );
	eq( TSR_Series_Seasons::season_key( '', '' ), null );
} );
