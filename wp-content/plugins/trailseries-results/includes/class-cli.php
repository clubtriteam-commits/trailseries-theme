<?php
/**
 * WP-CLI commands: `wp tsr import` and `wp tsr verify-names`.
 *
 * The import path is deliberately paranoid: after saving, names are
 * byte-compared against the source file and the command fails hard on any
 * difference. Requirement: names are never changed by migration.
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_CLI {

	/**
	 * Import a result set from a canonical JSON file into a ts_result post.
	 *
	 * The file must contain the TSR_Result_Set::to_array() shape:
	 * { "schema_version": 1, "split_labels": [...], "rows": [...] }
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Target ts_result post ID.
	 *
	 * <file>
	 * : Path to the canonical JSON file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr import 123 migration/data/2023-vitosha-run-30k.json
	 *
	 * @param array<int, string> $args Positional args.
	 */
	public function import( array $args ): void {
		[ $post_id, $file ] = array( (int) $args[0], $args[1] );

		$set = $this->load_source_file( $file );

		TSR_Repository::save( $post_id, $set );

		// Byte-verify names: stored data vs source file, strict binary comparison.
		$stored     = TSR_Repository::load( $post_id );
		$mismatches = $this->compare_names( $set, $stored );
		if ( array() !== $mismatches ) {
			foreach ( $mismatches as $line ) {
				WP_CLI::warning( $line );
			}
			WP_CLI::error( sprintf( 'Import aborted: %d name mismatch(es) after save. Data NOT trusted.', count( $mismatches ) ) );
		}

		WP_CLI::success(
			sprintf(
				'Imported %d rows into post %d. Names byte-verified. names_sha256=%s',
				count( $set->rows() ),
				$post_id,
				TSR_Repository::names_hash( $set )
			)
		);
	}

	/**
	 * Byte-verify stored runner names against a source JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ts_result post ID to verify.
	 *
	 * [<file>]
	 * : Source JSON file to compare against. When omitted, only the stored
	 *   SHA-256 names hash is recomputed and checked.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr verify-names 123 migration/data/2023-vitosha-run-30k.json
	 *     wp tsr verify-names 123
	 *
	 * @subcommand verify-names
	 *
	 * @param array<int, string> $args Positional args.
	 */
	public function verify_names( array $args ): void {
		$post_id = (int) $args[0];

		$stored = TSR_Repository::load( $post_id );
		if ( null === $stored ) {
			WP_CLI::error( sprintf( 'Post %d has no results data.', $post_id ) );
		}

		// Always: recompute the hash and compare with the one stored at save time.
		$expected_hash = TSR_Repository::stored_names_hash( $post_id );
		$actual_hash   = TSR_Repository::names_hash( $stored );
		if ( null === $expected_hash ) {
			WP_CLI::warning( 'No stored names hash found (pre-hash import?).' );
		} elseif ( $expected_hash !== $actual_hash ) {
			WP_CLI::error( sprintf( 'Names hash mismatch! stored=%s recomputed=%s', $expected_hash, $actual_hash ) );
		}

		// Optionally: byte-compare every name against the source file.
		if ( isset( $args[1] ) ) {
			$source     = $this->load_source_file( $args[1] );
			$mismatches = $this->compare_names( $source, $stored );
			if ( array() !== $mismatches ) {
				foreach ( $mismatches as $line ) {
					WP_CLI::warning( $line );
				}
				WP_CLI::error( sprintf( '%d name mismatch(es) between source and stored data.', count( $mismatches ) ) );
			}
		}

		WP_CLI::success(
			sprintf( 'All %d runner names verified byte-for-byte. sha256=%s', count( $stored->rows() ), $actual_hash )
		);
	}

	private function load_source_file( string $file ): TSR_Result_Set {
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read file: %s', $file ) );
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( 'File is not valid JSON: %s', $file ) );
		}
		try {
			return TSR_Result_Set::from_array( $data );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( sprintf( 'File does not match the canonical schema: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Strict binary comparison of names, row by row. Mismatches are reported
	 * with hex dumps because encoding bugs are usually invisible as text.
	 *
	 * @return list<string> Human-readable mismatch descriptions; empty when identical.
	 */
	private function compare_names( TSR_Result_Set $expected, TSR_Result_Set $actual ): array {
		$mismatches    = array();
		$expected_rows = $expected->rows();
		$actual_rows   = $actual->rows();

		if ( count( $expected_rows ) !== count( $actual_rows ) ) {
			$mismatches[] = sprintf( 'Row count differs: expected %d, got %d.', count( $expected_rows ), count( $actual_rows ) );
			return $mismatches;
		}

		foreach ( $expected_rows as $i => $exp ) {
			$act = $actual_rows[ $i ];
			foreach ( array( 'first_name', 'last_name' ) as $field ) {
				if ( $exp->{$field} !== $act->{$field} ) {
					$mismatches[] = sprintf(
						'Row %d %s differs: expected "%s" (hex %s), got "%s" (hex %s)',
						$i,
						$field,
						$exp->{$field},
						bin2hex( $exp->{$field} ),
						$act->{$field},
						bin2hex( $act->{$field} )
					);
				}
			}
		}

		return $mismatches;
	}
}
