<?php
/**
 * WP-CLI commands for the TrailSeries Results plugin.
 *
 * Commands:
 *   wp tsr import <post_id> <file>   — import a single JSON into an existing post.
 *   wp tsr verify-names <post_id>    — recompute + check the stored names hash.
 *   wp tsr bulk-import               — create ts_result posts from all canonical JSONs.
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
	 * Register all TSR subcommands with WP-CLI.
	 *
	 * Called once from the plugin bootstrap instead of passing the whole class
	 * to WP_CLI::add_command(), which does not reliably honour @subcommand
	 * renames in every WP-CLI release. Explicit per-subcommand registration
	 * is the guaranteed path and makes the full command list visible here.
	 */
	public static function register(): void {
		$instance = new self();
		WP_CLI::add_command( 'tsr import',      array( $instance, 'import' ) );
		WP_CLI::add_command( 'tsr verify-names', array( $instance, 'verify_names' ) );
		WP_CLI::add_command( 'tsr bulk-import',  array( $instance, 'bulk_import' ) );
	}

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
	 *     wp tsr import 123 migration/data/canonical/iran-run18-results__16.7km-m.json
	 *
	 * @param array<int, string> $args Positional args.
	 */
	public function import( array $args ): void {
		[ $post_id, $file ] = array( (int) $args[0], $args[1] );

		try {
			[ $row_count, $hash ] = $this->import_file( $post_id, $file );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return; // unreachable; quiets static analysis.
		}

		WP_CLI::success(
			sprintf(
				'Imported %d rows into post %d. Names byte-verified. names_sha256=%s',
				$row_count,
				$post_id,
				$hash
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
	 *     wp tsr verify-names 123 migration/data/canonical/iran-run18__16.7km-m.json
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

	/**
	 * Bulk-import canonical JSON sections and create ts_result posts.
	 *
	 * Reads _manifest.csv (produced by extract_canonical_results.py) and
	 * creates one ts_result post per JSON file. The FIRST section of each
	 * legacy page receives the original page slug so that URL is preserved
	 * exactly for SEO; later sections from the same page get
	 * "{page_slug}--{cat_part}" slugs.
	 *
	 * After each import the stored names are byte-verified against the JSON.
	 * The command logs every result and exits non-zero if any file fails.
	 *
	 * ## OPTIONS
	 *
	 * [--manifest=<file>]
	 * : Path to _manifest.csv.
	 *   Default: <WP root>/migration/data/canonical/_manifest.csv
	 *
	 * [--json-dir=<dir>]
	 * : Directory containing the JSON files.
	 *   Default: <WP root>/migration/data/canonical/
	 *
	 * [--status=<status>]
	 * : Post status for created posts. Default: publish
	 *
	 * [--author=<login>]
	 * : WordPress username to assign as post author.
	 *   Default: first administrator found.
	 *
	 * [--dry-run]
	 * : Print what would happen without writing to the database.
	 *
	 * [--skip-existing]
	 * : Skip JSON files whose target post slug already exists as a ts_result.
	 *
	 * [--force]
	 * : Re-import data into existing ts_result posts (overwrite stored data).
	 *   Without this flag, existing posts cause a warning and count as failed.
	 *
	 * [--slug=<pattern>]
	 * : Only process sections whose original page slug contains this string.
	 *
	 * [--limit=<n>]
	 * : Stop after processing N JSON files (useful for smoke-testing).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr bulk-import --dry-run
	 *     wp tsr bulk-import --dry-run --slug=iran-run18
	 *     wp tsr bulk-import --skip-existing
	 *     wp tsr bulk-import --force --limit=5
	 *
	 * @subcommand bulk-import
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function bulk_import( array $args, array $assoc_args ): void {
		$dry_run       = isset( $assoc_args['dry-run'] );
		$skip_existing = isset( $assoc_args['skip-existing'] );
		$force         = isset( $assoc_args['force'] );
		$slug_filter   = $assoc_args['slug'] ?? '';
		$limit         = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$post_status   = $assoc_args['status'] ?? 'publish';
		$manifest_path = $assoc_args['manifest'] ?? ABSPATH . 'migration/data/canonical/_manifest.csv';
		$json_dir      = rtrim( $assoc_args['json-dir'] ?? ABSPATH . 'migration/data/canonical/', '/\\' ) . DIRECTORY_SEPARATOR;

		// Resolve author ID.
		$author_id = 0;
		if ( isset( $assoc_args['author'] ) ) {
			$user = get_user_by( 'login', $assoc_args['author'] );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User not found: %s', $assoc_args['author'] ) );
				return;
			}
			$author_id = $user->ID;
		} else {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
			if ( $admins ) {
				$author_id = $admins[0]->ID;
			}
		}

		// Read manifest.
		if ( ! is_readable( $manifest_path ) ) {
			WP_CLI::error( sprintf( 'Cannot read manifest: %s', $manifest_path ) );
			return;
		}
		$manifest_rows = $this->read_manifest( $manifest_path );
		if ( ! $manifest_rows ) {
			WP_CLI::error( 'Manifest is empty or could not be parsed.' );
			return;
		}

		$total_with_files = count( array_filter( $manifest_rows, static fn( $r ) => ! empty( $r['file'] ) ) );
		WP_CLI::log( sprintf(
			'Manifest: %d sections (%d with JSON files). json-dir: %s',
			count( $manifest_rows ),
			$total_with_files,
			$json_dir
		) );
		if ( $dry_run ) {
			WP_CLI::log( '--- DRY RUN: no database writes ---' );
		}

		$created         = 0;
		$updated         = 0;
		$skipped         = 0;
		$failed          = 0;
		$processed       = 0;
		$bare_slug_given = array(); // page_slug → true once its first section is assigned.

		foreach ( $manifest_rows as $row ) {
			if ( empty( $row['file'] ) ) {
				continue; // Section produced no JSON (all rows were issues).
			}

			$page_slug = $row['slug'];
			if ( $slug_filter && false === strpos( $page_slug, $slug_filter ) ) {
				continue;
			}

			// Derive post slug: first section gets the original page slug.
			$post_slug                      = $this->derive_post_slug( $page_slug, $row['file'], $bare_slug_given );
			$bare_slug_given[ $page_slug ]  = true;

			// Derive post title.
			$post_title = $row['page_title'];
			if ( ! empty( $row['category_raw'] ) ) {
				$post_title .= ' — ' . $row['category_raw'];
			}

			$json_file = $json_dir . $row['file'];

			// Check for existing ts_result post with this slug.
			$existing = get_page_by_path( $post_slug, OBJECT, TSR_Post_Types::POST_TYPE );

			if ( $dry_run ) {
				if ( $existing ) {
					$action = $force ? 'update' : ( $skip_existing ? 'skip' : 'CONFLICT' );
				} else {
					$action = 'create';
				}
				WP_CLI::log( sprintf(
					'[DRY] %-8s  slug=%-55s  %s',
					$action,
					$post_slug,
					mb_strimwidth( $post_title, 0, 55, '…' )
				) );
				++$processed;
				if ( $limit && $processed >= $limit ) {
					break;
				}
				continue;
			}

			if ( $existing && ! $force ) {
				if ( $skip_existing ) {
					WP_CLI::log( sprintf( 'SKIP     [%d]  %s', $existing->ID, $post_slug ) );
					++$skipped;
				} else {
					WP_CLI::warning( sprintf(
						'Slug already exists as post #%d — use --skip-existing or --force: %s',
						$existing->ID,
						$post_slug
					) );
					++$failed;
				}
				++$processed;
				if ( $limit && $processed >= $limit ) {
					break;
				}
				continue;
			}

			if ( $existing && $force ) {
				$post_id = $existing->ID;
				wp_update_post( array( 'ID' => $post_id, 'post_title' => $post_title ) );
			} else {
				$result = wp_insert_post(
					array(
						'post_type'   => TSR_Post_Types::POST_TYPE,
						'post_title'  => $post_title,
						'post_name'   => $post_slug,
						'post_status' => $post_status,
						'post_author' => $author_id,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( 'FAIL  create  slug=%s  %s', $post_slug, $result->get_error_message() ) );
					++$failed;
					++$processed;
					if ( $limit && $processed >= $limit ) {
						break;
					}
					continue;
				}
				$post_id = $result;

				// Warn if WordPress sanitized the slug to something different.
				$stored_slug = get_post_field( 'post_name', $post_id );
				if ( $stored_slug !== $post_slug ) {
					WP_CLI::warning( sprintf(
						'[%d] Slug was sanitized by WordPress: wanted %s, stored %s',
						$post_id,
						$post_slug,
						$stored_slug
					) );
				}
			}

			// Store the original page URL for 301 redirect generation.
			if ( ! empty( $row['url'] ) ) {
				update_post_meta( (int) $post_id, '_tsr_original_url', $row['url'] );
			}

			// Import JSON and byte-verify names.
			try {
				[ $row_count, $hash ] = $this->import_file( (int) $post_id, $json_file );
			} catch ( RuntimeException $e ) {
				WP_CLI::warning( sprintf( 'FAIL  import  [%d]  slug=%s  %s', $post_id, $post_slug, $e->getMessage() ) );
				if ( ! $existing ) {
					wp_delete_post( (int) $post_id, true ); // Roll back the empty post.
				}
				++$failed;
				++$processed;
				if ( $limit && $processed >= $limit ) {
					break;
				}
				continue;
			}

			$label = $existing ? 'UPDATE' : 'CREATE';
			WP_CLI::log( sprintf(
				'%-6s  [%d]  slug=%-55s  rows=%-4d  sha=%s…',
				$label,
				$post_id,
				$post_slug,
				$row_count,
				substr( $hash, 0, 12 )
			) );

			if ( $existing ) {
				++$updated;
			} else {
				++$created;
			}

			++$processed;
			if ( $limit && $processed >= $limit ) {
				break;
			}
		}

		$summary = sprintf(
			'Done. created=%d  updated=%d  skipped=%d  failed=%d',
			$created,
			$updated,
			$skipped,
			$failed
		);

		if ( $failed > 0 ) {
			WP_CLI::error( $summary ); // Exits non-zero.
		} else {
			WP_CLI::success( $summary );
		}
	}

	// ── private helpers ────────────────────────────────────────────────────────

	/**
	 * Core import logic: load JSON → validate schema → save → byte-verify.
	 * Throws RuntimeException on any failure so callers decide abort vs. continue.
	 *
	 * @return array{0: int, 1: string} [row_count, names_sha256]
	 * @throws RuntimeException
	 */
	private function import_file( int $post_id, string $file ): array {
		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( sprintf( 'Cannot read file: %s', $file ) );
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( sprintf( 'Invalid JSON: %s', $file ) );
		}

		try {
			$set = TSR_Result_Set::from_array( $data );
		} catch ( InvalidArgumentException $e ) {
			throw new RuntimeException( 'Schema error: ' . $e->getMessage() );
		}

		try {
			TSR_Repository::save( $post_id, $set );
		} catch ( Exception $e ) {
			throw new RuntimeException( 'Save failed: ' . $e->getMessage() );
		}

		$stored = TSR_Repository::load( $post_id );
		if ( null === $stored ) {
			throw new RuntimeException( 'Save appeared to succeed but load returned null immediately after.' );
		}

		$mismatches = $this->compare_names( $set, $stored );
		if ( array() !== $mismatches ) {
			throw new RuntimeException( sprintf(
				'%d name mismatch(es) after save: %s',
				count( $mismatches ),
				implode( '; ', array_slice( $mismatches, 0, 3 ) )
			) );
		}

		return array( count( $set->rows() ), TSR_Repository::names_hash( $set ) );
	}

	/**
	 * Derive the WordPress post_name for a single JSON section.
	 *
	 * The first section of each legacy page gets the original page slug
	 * unchanged (preserving the exact legacy URL). Subsequent sections from
	 * the same page get "{page_slug}--{cat_part}" where cat_part is the
	 * suffix after "__" in the filename (e.g. "16.7km-m", "all-2").
	 *
	 * @param string              $page_slug      Original slug from results-page-list.csv.
	 * @param string              $file           JSON filename.
	 * @param array<string, true> $bare_slug_given Pages whose bare slug has already been used.
	 */
	private function derive_post_slug( string $page_slug, string $file, array $bare_slug_given ): string {
		if ( ! isset( $bare_slug_given[ $page_slug ] ) ) {
			return $page_slug; // First section → exact legacy URL preserved.
		}

		$stem     = pathinfo( $file, PATHINFO_FILENAME );
		$sep      = strpos( $stem, '__' );
		$cat_part = ( false !== $sep ) ? substr( $stem, $sep + 2 ) : $stem;

		return $page_slug . '--' . $cat_part;
	}

	/**
	 * Parse _manifest.csv into an array of associative rows.
	 * Strips the UTF-8 BOM that Python's csv writer adds with utf-8-sig.
	 *
	 * @return list<array<string, string>>
	 */
	private function read_manifest( string $path ): array {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle );
			return array();
		}

		// Strip UTF-8 BOM ("\xEF\xBB\xBF") from the first header field.
		if ( str_starts_with( $headers[0], "\xEF\xBB\xBF" ) ) {
			$headers[0] = substr( $headers[0], 3 );
		}

		$rows = array();
		while ( false !== ( $fields = fgetcsv( $handle ) ) ) {
			if ( count( $fields ) === count( $headers ) ) {
				$rows[] = array_combine( $headers, $fields );
			}
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Load and validate a source JSON file into a TSR_Result_Set.
	 * Calls WP_CLI::error() (hard-exits) on any failure — only for
	 * single-file contexts where aborting is correct behaviour.
	 */
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
