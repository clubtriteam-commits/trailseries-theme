<?php
declare( strict_types=1 );
/**
 * TSR_Official_Standings::validate — the contract for imported old-site
 * standings (audit H1 step 2). Invented names only.
 */

function tsr_official_doc( array $over = array() ): array {
	return array_merge(
		array(
			'season'      => 2016,
			'label'       => 'Сезон 5',
			'source_slug' => 'season-5',
			'kind'        => 'full',
			'has_podiums' => false,
			'notes'       => array(),
			'm'           => array( array( 'rank' => 1, 'name' => 'Иван Тестов', 'points' => 104, 'podiums' => null ) ),
			'f'           => array( array( 'rank' => 1, 'name' => 'Мария Тестова', 'points' => 107, 'podiums' => null ) ),
		),
		$over
	);
}

test( 'official standings: a valid document passes unchanged', static function (): void {
	$doc = TSR_Official_Standings::validate( tsr_official_doc() );
	eq( $doc['season'], 2016 );
	eq( $doc['m'][0], array( 'rank' => 1, 'name' => 'Иван Тестов', 'points' => 104, 'podiums' => null ) );
	eq( $doc['kind'], 'full' );
} );

test( 'official standings: podiums are kept when published', static function (): void {
	$doc = TSR_Official_Standings::validate( tsr_official_doc( array(
		'has_podiums' => true,
		'm'           => array( array( 'rank' => 1, 'name' => 'Иван Тестов', 'points' => 88, 'podiums' => 6 ) ),
	) ) );
	eq( $doc['m'][0]['podiums'], 6 );
	eq( $doc['has_podiums'], true );
} );

test( 'official standings: shape errors are rejected', static function (): void {
	throws( static fn() => TSR_Official_Standings::validate( 'x' ), 'not an object' );
	throws( static fn() => TSR_Official_Standings::validate( tsr_official_doc( array( 'season' => '2016' ) ) ), 'season as string' );
	throws( static fn() => TSR_Official_Standings::validate( tsr_official_doc( array( 'kind' => 'partial' ) ) ), 'bad kind' );
	throws( static fn() => TSR_Official_Standings::validate( tsr_official_doc( array( 'f' => array() ) ) ), 'no women rows' );
	throws( static fn() => TSR_Official_Standings::validate( tsr_official_doc( array( 'm' => array( array( 'rank' => 0, 'name' => 'X', 'points' => 1 ) ) ) ) ), 'rank 0' );
	throws( static fn() => TSR_Official_Standings::validate( tsr_official_doc( array( 'm' => array( array( 'rank' => 1, 'name' => ' ', 'points' => 1 ) ) ) ) ), 'empty name' );
	throws( static fn() => TSR_Official_Standings::validate( tsr_official_doc( array( 'm' => array( array( 'rank' => 1, 'name' => 'X', 'points' => -1 ) ) ) ) ), 'negative points' );
} );
