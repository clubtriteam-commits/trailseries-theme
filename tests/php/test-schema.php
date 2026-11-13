<?php
declare( strict_types=1 );
/**
 * Unified results template (iron rule 1): TSR_Result_Row / TSR_Result_Set /
 * TSR_Schema validation, and names-hash parity with the Python extractor.
 */

/** @param array<string, mixed> $over */
function tsr_test_row( array $over = array() ): array {
	return array_merge(
		array(
			'place'       => 1,
			'first_name'  => 'Иван',
			'last_name'   => 'Тестов',
			'team'        => '',
			'age'         => 30,
			'bib'         => '12',
			'finish_time' => '1:02:03',
			'status'      => 'FIN',
			'splits'      => array(),
		),
		$over
	);
}

test( 'TSR_Schema::is_valid_time', static function (): void {
	eq( TSR_Schema::is_valid_time( '1:02:03' ), true );
	eq( TSR_Schema::is_valid_time( '101:02:03' ), true );
	eq( TSR_Schema::is_valid_time( '1:60:00' ), false );
	eq( TSR_Schema::is_valid_time( '12:34' ), false );
} );

test( 'TSR_Result_Row: finished runner needs time and place', static function (): void {
	TSR_Result_Row::from_array( tsr_test_row() );
	throws( static fn() => TSR_Result_Row::from_array( tsr_test_row( array( 'finish_time' => '' ) ) ), 'FIN without time' );
	throws( static fn() => TSR_Result_Row::from_array( tsr_test_row( array( 'place' => null ) ) ), 'FIN without place' );
} );

test( 'TSR_Result_Row: FNT must not carry a time; DNF may have no time', static function (): void {
	TSR_Result_Row::from_array( tsr_test_row( array( 'status' => 'FNT', 'finish_time' => '' ) ) );
	throws( static fn() => TSR_Result_Row::from_array( tsr_test_row( array( 'status' => 'FNT' ) ) ), 'FNT with time' );
	TSR_Result_Row::from_array( tsr_test_row( array( 'status' => 'DNF', 'finish_time' => '', 'place' => null ) ) );
} );

test( 'TSR_Result_Row: name required, age range, status enum', static function (): void {
	throws( static fn() => TSR_Result_Row::from_array( tsr_test_row( array( 'first_name' => '', 'last_name' => '' ) ) ), 'no name' );
	TSR_Result_Row::from_array( tsr_test_row( array( 'first_name' => 'Мария Примерна', 'last_name' => '' ) ) );
	throws( static fn() => TSR_Result_Row::from_array( tsr_test_row( array( 'age' => 1992 ) ) ), 'birth year as age' );
	throws( static fn() => TSR_Result_Row::from_array( tsr_test_row( array( 'status' => 'XYZ' ) ) ), 'unknown status' );
} );

test( 'TSR_Result_Set: split labels unique; round trip is lossless', static function (): void {
	throws( static fn() => new TSR_Result_Set( array( 'Lap', 'Lap' ) ), 'duplicate split label' );
	$data = array(
		'schema_version' => TSR_Schema::VERSION,
		'split_labels'   => array( 'Lap 1' ),
		'rows'           => array( tsr_test_row( array( 'splits' => array( '0:30:00' ) ) ) ),
	);
	eq( TSR_Result_Set::from_array( $data )->to_array(), $data );
} );

test( 'names hash: PHP TSR_Repository::names_hash == Python names_sha256 (shared fixture)', static function (): void {
	$fx  = json_decode( (string) file_get_contents( TSR_TEST_ROOT . '/tests/fixtures/names-hash.json' ), true );
	$set = new TSR_Result_Set( array() );
	foreach ( $fx['rows'] as $i => $r ) {
		$set->add( TSR_Result_Row::from_array( tsr_test_row( array(
			'place'      => $i + 1,
			'first_name' => $r['first_name'],
			'last_name'  => $r['last_name'],
		) ) ) );
	}
	eq( TSR_Repository::names_hash( $set ), $fx['sha256'] );
} );
