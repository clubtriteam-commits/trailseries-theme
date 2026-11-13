<?php
declare( strict_types=1 );
/**
 * TSR_Runner_Alias — owner-confirmed spellings of one person (invented names).
 */

test( 'alias: spellings map to the canonical name, case and spacing ignored', static function (): void {
	$map = TSR_Runner_Alias::with( array(), 'Ivan Testov-fast', 'Ivan Testov - Fast' );
	$map = TSR_Runner_Alias::with( $map, 'Ivan - Fast Testov', 'Ivan Testov - Fast' );
	eq( TSR_Runner_Alias::canonical( 'Ivan Testov-Fast', $map ), 'Ivan Testov - Fast', 'case differs' );
	eq( TSR_Runner_Alias::canonical( 'ivan  - fast   testov', $map ), 'Ivan Testov - Fast', 'spacing differs' );
	eq( TSR_Runner_Alias::canonical( 'Ivan Testov - Fast', $map ), 'Ivan Testov - Fast', 'canonical stays' );
	eq( TSR_Runner_Alias::canonical( 'Ivan Testov', $map ), 'Ivan Testov', 'a different spelling is not merged' );
} );

test( 'alias: self-aliases, empty names and chains are rejected', static function (): void {
	$map = TSR_Runner_Alias::with( array(), 'B Name', 'A Name' );
	throws( static fn() => TSR_Runner_Alias::with( $map, 'a name', 'A Name' ), 'same spelling' );
	throws( static fn() => TSR_Runner_Alias::with( $map, '', 'A Name' ), 'empty' );
	throws( static fn() => TSR_Runner_Alias::with( $map, 'C Name', 'B Name' ), 'canonical is an alias' );
	throws( static fn() => TSR_Runner_Alias::with( $map, 'A Name', 'D Name' ), 'variant is a canonical' );
} );
