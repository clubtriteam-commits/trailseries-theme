<?php
declare( strict_types=1 );
/**
 * TSR_Upload_Match::assign — admin upload slugs (audit H6). A re-upload must
 * update its own posts, hub included, and never touch another season.
 */

const TSR_T_BASE = 'baba-marta-run27-results';

function tsr_t_sections( string ...$labels ): array {
	return array_map(
		static fn( string $l ): array => array( 'label' => $l, 'candidate' => TSR_T_BASE . '-' . mb_strtolower( str_replace( ' ', '-', $l ), 'UTF-8' ) ),
		$labels
	);
}

function tsr_t_post( int $id, string $slug, string $label, string $season = '2027' ): array {
	return array( 'id' => $id, 'slug' => $slug, 'label' => $label, 'season' => $season );
}

test( 'upload: first upload — first category is the hub, the rest get candidate slugs', static function (): void {
	$r = TSR_Upload_Match::assign( TSR_T_BASE, 2027, tsr_t_sections( '16КМ МЪЖЕ', '16КМ ЖЕНИ' ), array() );
	eq( $r['conflict'], null );
	eq( array_column( $r['sections'], 'slug' ), array( TSR_T_BASE, TSR_T_BASE . '-16км-жени' ) );
	eq( array_column( $r['sections'], 'is_hub_head' ), array( true, false ) );
	eq( array_column( $r['sections'], 'existing_id' ), array( 0, 0 ) );
} );

test( 'upload: re-upload updates the hub and every category (H6), any order, K/М spelling', static function (): void {
	$family = array(
		tsr_t_post( 10, TSR_T_BASE, '16КМ МЪЖЕ' ),
		tsr_t_post( 11, TSR_T_BASE . '-16km-f', '16КМ ЖЕНИ' ),
		tsr_t_post( 12, TSR_T_BASE . '-6km-m', '6КМ МЪЖЕ' ),
	);
	$r = TSR_Upload_Match::assign( TSR_T_BASE, 2027, tsr_t_sections( '6KM мъже', '16KM МЪЖЕ', '16км жени' ), $family );
	eq( array_column( $r['sections'], 'existing_id' ), array( 12, 10, 11 ) );
	eq( array_column( $r['sections'], 'slug' ), array( TSR_T_BASE . '-6km-m', TSR_T_BASE, TSR_T_BASE . '-16km-f' ) );
	eq( array_column( $r['sections'], 'is_hub_head' ), array( false, true, false ) );
	eq( $r['leftovers'], array() );
} );

test( 'upload: hub owned by another season is a blocking conflict', static function (): void {
	$r = TSR_Upload_Match::assign( 'baba-marta-run-results', 2027, tsr_t_sections( '16КМ МЪЖЕ' ), array( tsr_t_post( 5, 'baba-marta-run-results', '16КМ МЪЖЕ', '2015' ) ) );
	eq( is_string( $r['conflict'] ), true );
	eq( $r['sections'], array() );
} );

test( 'upload: other-season posts in the family are never matched', static function (): void {
	$family = array( tsr_t_post( 10, TSR_T_BASE, '16КМ МЪЖЕ' ), tsr_t_post( 20, TSR_T_BASE . '-6km-m', '6КМ МЪЖЕ', '2026' ) );
	$r      = TSR_Upload_Match::assign( TSR_T_BASE, 2027, tsr_t_sections( '16КМ МЪЖЕ', '6КМ МЪЖЕ' ), $family );
	eq( array_column( $r['sections'], 'existing_id' ), array( 10, 0 ) );
} );

test( 'upload: removed category is a leftover; added category gets a new slug', static function (): void {
	$family = array( tsr_t_post( 10, TSR_T_BASE, '16КМ МЪЖЕ' ), tsr_t_post( 11, TSR_T_BASE . '-16km-f', '16КМ ЖЕНИ' ) );
	$r      = TSR_Upload_Match::assign( TSR_T_BASE, 2027, tsr_t_sections( '16КМ МЪЖЕ', '10КМ МЪЖЕ' ), $family );
	eq( array_column( $r['sections'], 'existing_id' ), array( 10, 0 ) );
	eq( $r['sections'][1]['slug'], TSR_T_BASE . '-10км-мъже' );
	eq( array_column( $r['leftovers'], 'id' ), array( 11 ) );
} );

test( 'upload: hub category gone — hub reported, never reassigned to another category', static function (): void {
	$family = array( tsr_t_post( 10, TSR_T_BASE, '16КМ МЪЖЕ' ) );
	$r      = TSR_Upload_Match::assign( TSR_T_BASE, 2027, tsr_t_sections( '6КМ ЖЕНИ' ), $family );
	eq( $r['sections'][0]['slug'] !== TSR_T_BASE, true );
	eq( $r['sections'][0]['existing_id'], 0 );
	eq( array_column( $r['leftovers'], 'id' ), array( 10 ) );
} );

test( 'upload: a label repeated in the file never maps twice to one post', static function (): void {
	$family = array( tsr_t_post( 10, TSR_T_BASE, '16КМ МЪЖЕ' ) );
	$r      = TSR_Upload_Match::assign( TSR_T_BASE, 2027, tsr_t_sections( '16КМ МЪЖЕ', '16КМ МЪЖЕ' ), $family );
	eq( array_column( $r['sections'], 'existing_id' ), array( 10, 0 ) );
} );
