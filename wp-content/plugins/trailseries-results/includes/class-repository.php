<?php
declare( strict_types=1 );
/**
 * Persistence for result sets. The ONLY read/write path for results data —
 * everything goes through TSR_Result_Set validation on both save and load.
 *
 * Alongside the data, save() stores a SHA-256 over all runner names so
 * `wp tsr verify-names` can detect any later corruption (encoding mangling,
 * bad search-replace, etc.) without the original source file.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Repository {

	public const META_KEY       = '_tsr_result_set';
	public const NAMES_HASH_KEY = '_tsr_names_sha256';

	private function __construct() {}

	public static function save( int $post_id, TSR_Result_Set $set ): void {
		if ( TSR_Post_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Post %d is not a %s.', $post_id, TSR_Post_Types::POST_TYPE )
			);
		}

		// JSON_UNESCAPED_UNICODE keeps Cyrillic names human-readable in the DB
		// and byte-identical to their PHP string form.
		$json = wp_json_encode( $set->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new RuntimeException( 'Failed to encode result set.' );
		}

		// update_post_meta() expects slashed input; without wp_slash() the
		// escaped quotes inside the JSON would be stripped and corrupt it.
		update_post_meta( $post_id, self::META_KEY, wp_slash( $json ) );
		update_post_meta( $post_id, self::NAMES_HASH_KEY, self::names_hash( $set ) );

		// Round-trip check: what was just written must load back identically.
		$reloaded = self::load( $post_id );
		if ( null === $reloaded || $reloaded->to_array() !== $set->to_array() ) {
			throw new RuntimeException(
				sprintf( 'Round-trip verification failed for post %d — stored data does not match input.', $post_id )
			);
		}
	}

	public static function load( int $post_id ): ?TSR_Result_Set {
		$json = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( sprintf( 'Corrupt results JSON on post %d.', $post_id ) );
		}

		return TSR_Result_Set::from_array( $data );
	}

	/**
	 * Canonical digest over every runner's name, in row order. Field and row
	 * separators are ASCII unit/record separators so no legitimate name
	 * content can collide with the framing.
	 */
	public static function names_hash( TSR_Result_Set $set ): string {
		$blob = '';
		foreach ( $set->rows() as $row ) {
			$blob .= $row->first_name . "\x1f" . $row->last_name . "\x1e";
		}
		return hash( 'sha256', $blob );
	}

	public static function stored_names_hash( int $post_id ): ?string {
		$hash = get_post_meta( $post_id, self::NAMES_HASH_KEY, true );
		return ( is_string( $hash ) && '' !== $hash ) ? $hash : null;
	}
}
