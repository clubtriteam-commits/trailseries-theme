<?php
declare( strict_types=1 );
/**
 * Dependency-free test runner for the trailseries-results plugin's pure logic
 * (audit H10). No Composer/PHPUnit needed: `php tests/php/run.php`.
 *
 * Loads the plugin's pure files with the few WordPress functions they touch
 * stubbed, runs every tests/php/test-*.php, prints failures, exits non-zero
 * on any failure. Fixtures contain invented names only — real results data
 * (names and ages of real people) never goes into the repository.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler( static function ( int $no, string $str, string $file, int $line ): bool {
	throw new ErrorException( $str, 0, $no, $file, $line );
} );

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSR_TEST_ROOT', dirname( __DIR__, 2 ) );
define( 'TSR_TEST_PLUGIN', TSR_TEST_ROOT . '/wp-content/plugins/trailseries-results/' );

// ── WordPress stubs (only what the loaded files call) ────────────────────────

/** @var array<int, array<string, string>> Post meta used by get_post_meta(). */
$GLOBALS['tsr_test_meta'] = array();

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	return $GLOBALS['tsr_test_meta'][ $post_id ][ $key ] ?? '';
}

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID = 0;
		public string $post_name = '';
		public string $post_title = '';
		public function __construct( int $id = 0, string $name = '', string $title = '' ) {
			$this->ID         = $id;
			$this->post_name  = $name;
			$this->post_title = $title;
		}
	}
}

foreach ( array(
	'includes/event-heuristics.php',
	'includes/class-status.php',
	'includes/class-schema.php',
	'includes/class-result-row.php',
	'includes/class-result-set.php',
	'includes/class-repository.php',
	'includes/class-section-meta.php',
	'includes/class-prune-rules.php',
	'includes/class-upload-match.php',
	'includes/class-series-seasons.php',
	'includes/class-official-standings.php',
	'includes/class-runner-alias.php',
) as $file ) {
	require TSR_TEST_PLUGIN . $file;
}

// ── tiny assertion API ───────────────────────────────────────────────────────

/** @var list<array{0: string, 1: callable}> */
$GLOBALS['tsr_tests'] = array();

function test( string $name, callable $fn ): void {
	$GLOBALS['tsr_tests'][] = array( $name, $fn );
}

final class TSR_Test_Failure extends Exception {}

function eq( mixed $actual, mixed $expected, string $what = '' ): void {
	if ( $actual !== $expected ) {
		throw new TSR_Test_Failure( sprintf(
			"%sexpected %s, got %s",
			'' !== $what ? $what . ': ' : '',
			var_export( $expected, true ),
			var_export( $actual, true )
		) );
	}
}

function throws( callable $fn, string $what = '' ): void {
	try {
		$fn();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new TSR_Test_Failure( ( '' !== $what ? $what . ': ' : '' ) . 'expected InvalidArgumentException, none thrown' );
}

// ── run ──────────────────────────────────────────────────────────────────────

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

$failed = 0;
foreach ( $GLOBALS['tsr_tests'] as [ $name, $fn ] ) {
	$GLOBALS['tsr_test_meta'] = array();
	try {
		$fn();
		echo "  ok    {$name}\n";
	} catch ( Throwable $e ) {
		++$failed;
		echo "  FAIL  {$name}\n        " . str_replace( "\n", "\n        ", $e->getMessage() ) . "\n";
	}
}
printf( "\n%d tests, %d failed\n", count( $GLOBALS['tsr_tests'] ), $failed );
exit( $failed > 0 ? 1 : 0 );
