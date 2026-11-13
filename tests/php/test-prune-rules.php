<?php
declare( strict_types=1 );
/**
 * TSR_Prune_Rules::classify — the rule that once nearly trashed the only
 * copy of the relay results (2026-11-13). EXTRA must never be trashable.
 */

function tsr_n( string ...$names ): array {
	return array_map( static fn( $n ) => $n . "\x1f", $names );
}

test( 'prune: identical hash is DUPLICATE', static function (): void {
	$r = TSR_Prune_Rules::classify( 'h1', tsr_n( 'A', 'B' ), array( array( 'id' => 7, 'hash' => 'h1', 'names' => tsr_n( 'A', 'B' ) ) ) );
	eq( $r['class'], 'DUPLICATE' );
	eq( $r['match'], 7 );
} );

test( 'prune: empty hash is never a DUPLICATE of another empty hash', static function (): void {
	$r = TSR_Prune_Rules::classify( '', tsr_n( 'A' ), array( array( 'id' => 7, 'hash' => '', 'names' => tsr_n( 'Z' ) ) ) );
	eq( $r['class'], 'EXTRA' );
} );

test( 'prune: >= 90% of runners in ONE section is OLD', static function (): void {
	$old     = tsr_n( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'X' );          // 9 of 10 present
	$current = array( array( 'id' => 3, 'hash' => 'h', 'names' => tsr_n( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' ) ) );
	eq( TSR_Prune_Rules::classify( 'old', $old, $current )['class'], 'OLD' );
} );

test( 'prune: merged section fully covered by several current sections is SPLIT', static function (): void {
	$merged  = tsr_n( 'A', 'B', 'C', 'D' );
	$current = array(
		array( 'id' => 1, 'hash' => 'h1', 'names' => tsr_n( 'A', 'B' ) ),
		array( 'id' => 2, 'hash' => 'h2', 'names' => tsr_n( 'C', 'D', 'E' ) ),
	);
	eq( TSR_Prune_Rules::classify( 'm', $merged, $current )['class'], 'SPLIT' );
} );

test( 'prune: one runner missing from the union is EXTRA (relay case)', static function (): void {
	$relay   = tsr_n( 'Team 1', 'Team 2' );
	$current = array( array( 'id' => 1, 'hash' => 'h1', 'names' => tsr_n( 'A', 'B', 'Team 1' ) ) );
	eq( TSR_Prune_Rules::classify( 'r', $relay, $current )['class'], 'EXTRA' );
	eq( TSR_Prune_Rules::classify( 'r', tsr_n( 'Team 1' ), array() )['class'], 'EXTRA', 'no current sections' );
} );

test( 'prune: a post without names is EXTRA, never SPLIT/OLD', static function (): void {
	eq( TSR_Prune_Rules::classify( '', array(), array( array( 'id' => 1, 'hash' => 'h', 'names' => tsr_n( 'A' ) ) ) )['class'], 'EXTRA' );
} );

test( 'prune: EXTRA is not in the trashable set', static function (): void {
	eq( in_array( 'EXTRA', TSR_Prune_Rules::TRASHABLE, true ), false );
	eq( TSR_Prune_Rules::TRASHABLE, array( 'DUPLICATE', 'OLD', 'SPLIT' ) );
} );
