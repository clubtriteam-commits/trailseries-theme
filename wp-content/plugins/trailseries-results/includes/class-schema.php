<?php
/**
 * The canonical results schema. Single source of truth for column set and order.
 *
 * Every results table on the site has exactly these columns, in this order:
 *
 *   Place | First name | Last name | Team | Age | Bib# | Finish Time | Status
 *
 * followed by zero or more split columns, always trailing. Nothing in the
 * theme or admin may add, remove or reorder core columns — TSR_Result_Row and
 * TSR_Result_Set only accept data in this shape, and TSR_Renderer only reads
 * from those objects.
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Schema {

	/**
	 * Bump when the serialized shape changes; from_array() rejects other versions.
	 */
	public const VERSION = 1;

	/**
	 * Core column keys in canonical display order.
	 *
	 * @var list<string>
	 */
	public const CORE_COLUMNS = array(
		'place',
		'first_name',
		'last_name',
		'team',
		'age',
		'bib',
		'finish_time',
		'status',
	);

	/**
	 * Elapsed time as H:MM:SS (hours unpadded, up to 3 digits for long races).
	 */
	public const TIME_PATTERN = '/^\d{1,3}:[0-5]\d:[0-5]\d$/';

	private function __construct() {}

	/**
	 * Display labels for the core columns, keyed by column key, in canonical order.
	 *
	 * @return array<string, string>
	 */
	public static function core_labels(): array {
		return array(
			'place'       => __( 'Place', 'trailseries-results' ),
			'first_name'  => __( 'First name', 'trailseries-results' ),
			'last_name'   => __( 'Last name', 'trailseries-results' ),
			'team'        => __( 'Team', 'trailseries-results' ),
			'age'         => __( 'Age', 'trailseries-results' ),
			'bib'         => __( 'Bib#', 'trailseries-results' ),
			'finish_time' => __( 'Finish Time', 'trailseries-results' ),
			'status'      => __( 'Status', 'trailseries-results' ),
		);
	}

	public static function is_valid_time( string $time ): bool {
		return 1 === preg_match( self::TIME_PATTERN, $time );
	}
}
