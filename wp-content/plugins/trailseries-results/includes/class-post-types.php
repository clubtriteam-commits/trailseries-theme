<?php
/**
 * CPT and taxonomy registration.
 *
 * - ts_result:  one post per published results table (one race distance, one edition).
 * - ts_race:    the race a result belongs to (e.g. "Vitosha Run").
 * - ts_season:  the season/year (e.g. "2024").
 *
 * NOTE on rewrite slugs (see docs/decisions/ADR-003): the slug below is a
 * placeholder until the legacy URL inventory is complete. Permalinks must end
 * up byte-identical to the old site's URLs — do not "improve" slugs.
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Post_Types {

	public const POST_TYPE  = 'ts_result';
	public const TAX_RACE   = 'ts_race';
	public const TAX_SEASON = 'ts_season';

	private function __construct() {}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Race Results', 'trailseries-results' ),
					'singular_name' => __( 'Race Result', 'trailseries-results' ),
					'add_new_item'  => __( 'Add New Race Result', 'trailseries-results' ),
					'edit_item'     => __( 'Edit Race Result', 'trailseries-results' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-list-view',
				'supports'     => array( 'title', 'editor' ),
				// Placeholder — must be aligned with the legacy URL inventory (ADR-003).
				'rewrite'      => array(
					'slug'       => 'results',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::TAX_RACE,
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Races', 'trailseries-results' ),
					'singular_name' => __( 'Race', 'trailseries-results' ),
				),
				'public'       => true,
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array(
					'slug'       => 'race',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::TAX_SEASON,
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Seasons', 'trailseries-results' ),
					'singular_name' => __( 'Season', 'trailseries-results' ),
				),
				'public'       => true,
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array(
					'slug'       => 'season',
					'with_front' => false,
				),
			)
		);
	}
}
