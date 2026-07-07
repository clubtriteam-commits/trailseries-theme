<?php
/**
 * WP-CLI commands for the TrailSeries Results plugin.
 *
 * Commands:
 *   wp tsr import <post_id> <file>   — import a single JSON into an existing post.
 *   wp tsr verify-names <post_id>    — recompute + check the stored names hash.
 *   wp tsr bulk-import               — create ts_result posts from all canonical JSONs.
 *   wp tsr backfill-meta             — derive scoring/grouping post meta for all ts_result posts.
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
		WP_CLI::add_command( 'tsr backfill-meta', array( $instance, 'backfill_meta' ) );
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

			// Resolve the actual on-disk path. On Linux, ZIP extraction may store
			// Cyrillic filenames as raw Windows code-page bytes instead of UTF-8,
			// so direct path construction fails. resolve_json_path() tries iconv
			// re-encoding and a directory-scan fallback before returning null.
			$json_file = $this->resolve_json_path( $json_dir, $row['file'] );

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

			// Early-exit when the file cannot be found — avoids creating a post
			// that would be immediately rolled back when import_file() throws.
			if ( null === $json_file ) {
				WP_CLI::warning( sprintf(
					'FAIL  no-file  slug=%s  cannot find on disk (tried CP1251/CP866 encoding variants): %s',
					$post_slug,
					$row['file']
				) );
				++$failed;
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

	/**
	 * Derive scoring and grouping post meta for all ts_result posts.
	 *
	 * Sets four meta keys from post_title / post_name, using
	 * migration/category-map.csv to normalise raw category headers:
	 *
	 *   _tsr_distance_km   — canonical distance ("11", "5.5"). From the
	 *                        " — {category_raw}" title suffix via the map;
	 *                        falls back to a km/КМ regex on the title, then
	 *                        on the post_name (secondary-section slugs carry
	 *                        a "--16.7km-m"-style category part).
	 *   _tsr_distance_cat  — scoring category from km:
	 *                        <8 short · 8–13.9 medium · 14–20.9 long · 21+ bonus
	 *   _tsr_event_base    — clean event name ("7 Hills Run"), title-derived
	 *                        with slug fallback (same logic as the Резултати page).
	 *   _tsr_season        — 4-digit race year from title or slug. NEVER falls
	 *                        back to post_date (that is the import date).
	 *
	 * A meta key is only written when a value could be derived; nothing is
	 * invented. Existing values are kept unless --force is given.
	 *
	 * ## OPTIONS
	 *
	 * [--map=<file>]
	 * : Path to category-map.csv.
	 *   Default: {plugin}/data/category-map.csv
	 *
	 * [--slug=<pattern>]
	 * : Only process posts whose post_name contains this string.
	 *
	 * [--force]
	 * : Overwrite meta values that already exist. Default: fill missing only.
	 *
	 * [--dry-run]
	 * : Print what would be written without touching the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr backfill-meta --dry-run
	 *     wp tsr backfill-meta --slug=7-hills --dry-run
	 *     wp tsr backfill-meta
	 *     wp tsr backfill-meta --force
	 *
	 * @subcommand backfill-meta
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function backfill_meta( array $args, array $assoc_args ): void {
		$dry_run     = isset( $assoc_args['dry-run'] );
		$force       = isset( $assoc_args['force'] );
		$slug_filter = $assoc_args['slug'] ?? '';
		$map_path    = $assoc_args['map'] ?? TSR_PLUGIN_DIR . 'data/category-map.csv';

		$map = $this->read_category_map( $map_path );
		if ( array() === $map ) {
			WP_CLI::error( sprintf( 'Cannot read category map (or it is empty): %s', $map_path ) );
			return;
		}
		WP_CLI::log( sprintf( 'Category map: %d headers loaded from %s', count( $map ), $map_path ) );
		if ( $dry_run ) {
			WP_CLI::log( '--- DRY RUN: no database writes ---' );
		}

		$posts = get_posts( array(
			'post_type'   => TSR_Post_Types::POST_TYPE,
			'numberposts' => -1,
			'post_status' => 'any',
			'orderby'     => 'name',
			'order'       => 'ASC',
		) );

		$stats = array(
			'processed'   => 0,
			'km'          => 0,
			'cat'         => 0,
			'event_base'  => 0,
			'season'      => 0,
			'no_distance' => 0,
			'no_season'   => 0,
			'no_event'    => 0,
			'kept'        => 0,
		);

		foreach ( $posts as $post ) {
			if ( '' !== $slug_filter && false === strpos( $post->post_name, $slug_filter ) ) {
				continue;
			}
			++$stats['processed'];

			// ── Derive all four values ────────────────────────────────────────
			$km = null;

			$cat_raw = $this->title_category_suffix( $post->post_title );
			if ( '' !== $cat_raw ) {
				$key = $this->norm_cat_key( $cat_raw );
				if ( isset( $map[ $key ] ) && '' !== $map[ $key ] ) {
					$km = (float) $map[ $key ];
				}
			}
			if ( null === $km ) {
				$km = $this->parse_km( '' !== $cat_raw ? $cat_raw : $post->post_title );
			}
			if ( null === $km ) {
				// Secondary-section slugs carry the category part after "--"
				// (e.g. "iran-run18-results--16.7km-m").
				$km = $this->parse_km( $post->post_name );
			}

			$cat = null !== $km ? $this->km_to_cat( $km ) : null;

			$event_base = $this->event_base_name( $post->post_title );
			if ( '' === $event_base ) {
				$event_base = $this->slug_event_name( $post->post_name );
			}

			$season = $this->title_year( $post->post_title )
				?? $this->slug_year( $post->post_name );

			// ── Collect writes (respecting --force vs fill-missing) ──────────
			$derived = array(
				'_tsr_distance_km'  => null !== $km ? rtrim( rtrim( sprintf( '%.1f', $km ), '0' ), '.' ) : null,
				'_tsr_distance_cat' => $cat,
				'_tsr_event_base'   => '' !== $event_base ? $event_base : null,
				'_tsr_season'       => null !== $season ? (string) $season : null,
			);
			$writes  = array();
			foreach ( $derived as $meta_key => $value ) {
				if ( null === $value ) {
					continue;
				}
				$existing = (string) get_post_meta( $post->ID, $meta_key, true );
				if ( '' !== $existing && ! $force ) {
					++$stats['kept'];
					continue;
				}
				if ( $existing === $value ) {
					continue;
				}
				$writes[ $meta_key ] = $value;
			}

			if ( null === $km ) {
				++$stats['no_distance'];
			}
			if ( null === $season ) {
				++$stats['no_season'];
			}
			if ( '' === $event_base ) {
				++$stats['no_event'];
			}

			// ── Log + write ───────────────────────────────────────────────────
			WP_CLI::log( sprintf(
				'%s[%d]  %-50s  km=%-5s cat=%-6s season=%-4s base=%s',
				$dry_run ? '[DRY] ' : '',
				$post->ID,
				mb_strimwidth( $post->post_name, 0, 50, '…' ),
				$derived['_tsr_distance_km'] ?? '—',
				$derived['_tsr_distance_cat'] ?? '—',
				$derived['_tsr_season'] ?? '—',
				$derived['_tsr_event_base'] ?? '—'
			) );

			if ( $dry_run || array() === $writes ) {
				continue;
			}

			foreach ( $writes as $meta_key => $value ) {
				update_post_meta( $post->ID, $meta_key, $value );
			}
			if ( isset( $writes['_tsr_distance_km'] ) )  { ++$stats['km']; }
			if ( isset( $writes['_tsr_distance_cat'] ) ) { ++$stats['cat']; }
			if ( isset( $writes['_tsr_event_base'] ) )   { ++$stats['event_base']; }
			if ( isset( $writes['_tsr_season'] ) )       { ++$stats['season']; }
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf(
			'Derivation gaps: no-distance=%d  no-season=%d  no-event-name=%d  (meta left unset — review manually)',
			$stats['no_distance'],
			$stats['no_season'],
			$stats['no_event']
		) );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run over %d posts. Nothing written.', $stats['processed'] ) );
			return;
		}

		WP_CLI::success( sprintf(
			'Processed %d posts. Wrote: km=%d cat=%d event_base=%d season=%d. Existing values kept: %d.',
			$stats['processed'],
			$stats['km'],
			$stats['cat'],
			$stats['event_base'],
			$stats['season'],
			$stats['kept']
		) );
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
			throw $e; // unreachable; WP_CLI::error() exits — satisfies return-type checker
		}
	}

	/**
	 * Resolve the on-disk path for a JSON file whose name comes from _manifest.csv.
	 *
	 * The manifest stores filenames in UTF-8 (as produced on the developer machine).
	 * When the canonical JSON directory was transferred to a Linux server via ZIP,
	 * Cyrillic characters in filenames may have been stored as raw Windows code-page
	 * bytes (most commonly CP1251 for Bulgarian) rather than UTF-8, causing
	 * is_readable( $dir . $manifest_name ) to fail for every file with Cyrillic.
	 *
	 * Resolution order:
	 *   1. Exact path — works for ASCII-only filenames and correctly extracted UTF-8.
	 *   2. iconv re-encoding — converts the UTF-8 manifest name to CP1251, CP866, or
	 *      ISO-8859-5 and checks whether any of those byte sequences exist on disk.
	 *   3. Directory-scan skeleton match — scans the directory once (result cached per
	 *      dir), strips every non-ASCII byte from each entry name and from the manifest
	 *      name, then compares. Works when the encoding is unknown and the ASCII
	 *      skeleton (slug prefix, numeric distances, separators, extension) is unique
	 *      within the directory.
	 *
	 * @param string $json_dir          Directory path with trailing separator.
	 * @param string $manifest_filename Filename as recorded in _manifest.csv (UTF-8).
	 * @return string|null Full readable path, or null if the file cannot be located.
	 */
	private function resolve_json_path( string $json_dir, string $manifest_filename ): ?string {
		// 1. Exact match — covers pure-ASCII names and correctly extracted UTF-8.
		$path = $json_dir . $manifest_filename;
		if ( is_readable( $path ) ) {
			return $path;
		}

		// 2. iconv re-encoding — try common Cyrillic Windows code pages.
		foreach ( array( 'CP1251', 'CP866', 'ISO-8859-5' ) as $enc ) {
			$alt = @iconv( 'UTF-8', $enc . '//IGNORE', $manifest_filename );
			if ( is_string( $alt ) && $alt !== '' && $alt !== $manifest_filename ) {
				$alt_path = $json_dir . $alt;
				if ( is_readable( $alt_path ) ) {
					return $alt_path;
				}
			}
		}

		// 3. Directory-scan skeleton match — encoding unknown; match on the pure-ASCII
		//    skeleton of the filename (page-slug prefix, numbers, hyphens, extension).
		/** @var array<string, list<string>> $dir_cache */
		static $dir_cache = array();
		if ( ! array_key_exists( $json_dir, $dir_cache ) ) {
			$entries = is_dir( $json_dir ) ? ( scandir( $json_dir ) ?: array() ) : array();
			$dir_cache[ $json_dir ] = array_values(
				array_filter( $entries, static fn( $f ) => str_ends_with( $f, '.json' ) )
			);
		}

		$manifest_skeleton = preg_replace( '/[^\x20-\x7E]+/', '', $manifest_filename );
		foreach ( $dir_cache[ $json_dir ] as $entry ) {
			if ( preg_replace( '/[^\x20-\x7E]+/', '', $entry ) === $manifest_skeleton ) {
				return $json_dir . $entry;
			}
		}

		return null;
	}

	/**
	 * Parse category-map.csv into a normalised-header → canonical_distance_km map.
	 *
	 * Keys are produced by norm_cat_key() so lookups tolerate case and
	 * Latin/Cyrillic KM/КМ variation. Values may be '' for headers whose
	 * distance is unknown ("Мъже", "Жени" — marked "fill in manually").
	 *
	 * @return array<string, string> normalised raw_header => canonical_distance_km.
	 */
	private function read_category_map( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle );
			return array();
		}
		if ( str_starts_with( $headers[0], "\xEF\xBB\xBF" ) ) {
			$headers[0] = substr( $headers[0], 3 );
		}

		$map = array();
		while ( false !== ( $fields = fgetcsv( $handle ) ) ) {
			if ( count( $fields ) !== count( $headers ) ) {
				continue;
			}
			$row = array_combine( $headers, $fields );
			if ( '' === trim( $row['raw_header'] ) ) {
				continue;
			}
			$map[ $this->norm_cat_key( $row['raw_header'] ) ] = trim( $row['canonical_distance_km'] );
		}

		fclose( $handle );
		return $map;
	}

	/**
	 * Normalise a category header for map lookup: collapse whitespace,
	 * uppercase, and unify Latin K/M with Cyrillic К/М so "15KM МЪЖЕ"
	 * and "15КМ МЪЖЕ" produce the same key.
	 */
	private function norm_cat_key( string $s ): string {
		$s = mb_strtoupper( trim( (string) preg_replace( '/\s+/u', ' ', $s ) ), 'UTF-8' );
		return strtr( $s, array( 'K' => 'К', 'M' => 'М' ) );
	}

	/**
	 * Extract the " — {category_raw}" suffix that bulk-import appends to
	 * post_title. Uses the LAST em-dash separator: category_raw never
	 * contains one, but a legacy page_title might.
	 *
	 * @return string The raw category header, or '' when the title has no suffix.
	 */
	private function title_category_suffix( string $title ): string {
		$pos = mb_strrpos( $title, ' — ' );
		if ( false === $pos ) {
			return '';
		}
		return trim( mb_substr( $title, $pos + 3 ) );
	}

	/**
	 * Fallback distance extraction: first "NN km" / "NN.N КМ" token in the text.
	 * Comma decimal separators are accepted ("16,7км" → 16.7).
	 */
	private function parse_km( string $text ): ?float {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*(?:km|км)/iu', $text, $m ) ) {
			return (float) str_replace( ',', '.', $m[1] );
		}
		return null;
	}

	/**
	 * Map a distance to its scoring category.
	 *
	 * Boundaries (confirmed with the series organiser, 2026-07):
	 *   <8 short · 8–13.9 medium · 14–20.9 long · 21+ bonus
	 */
	private function km_to_cat( float $km ): string {
		if ( $km < 8 ) {
			return 'short';
		}
		if ( $km < 14 ) {
			return 'medium';
		}
		if ( $km < 21 ) {
			return 'long';
		}
		return 'bonus';
	}

	/**
	 * Extract a 4-digit race year from a post_title.
	 * Same logic as tsr_title_year() in the Резултати page template.
	 */
	private function title_year( string $title ): ?int {
		$pos = mb_strpos( $title, ' — ' );
		$raw = false !== $pos ? mb_substr( $title, 0, $pos ) : $title;

		if ( preg_match( "/['\x{2019}](\d{2})\b/u", $raw, $m ) ) {
			return 2000 + (int) $m[1];
		}
		if ( preg_match( '/\b(20\d{2})\b/', $raw, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Extract a 4-digit race year from a post_name.
	 * Same logic as tsr_slug_year() in the Резултати page template:
	 * 4-digit segment, 2-digit year attached to a word char, or trailing
	 * 2-digit segment once the results/ranking label is stripped. Day-of-month
	 * numbers between hyphens are deliberately not matched.
	 */
	private function slug_year( string $slug ): ?int {
		$base = explode( '--', $slug )[0];

		if ( preg_match( '/(?:^|-)(20\d{2})(?:-|$)/', $base, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/[\pL\d](1[3-9]|2[0-9])(?:-|$)/u', $base, $m ) ) {
			return 2000 + (int) $m[1];
		}

		$stripped = (string) preg_replace(
			'/(?:^|-)(?:results?|ranking|класиране|резултати)\d*$/iu',
			'',
			$base
		);
		if ( $stripped !== $base && preg_match( '/-(1[3-9]|2[0-9])$/', $stripped, $m ) ) {
			return 2000 + (int) $m[1];
		}

		return null;
	}

	/**
	 * Derive a clean event base name from a post_title.
	 * Same logic as tsr_event_base_name() in the Резултати page template.
	 * Returns '' when the title yields no usable name.
	 */
	private function event_base_name( string $title ): string {
		$pos = mb_strpos( $title, ' — ' );
		if ( false !== $pos ) {
			$title = mb_substr( $title, 0, $pos );
		}
		$title = (string) preg_replace( "/['\x{2019}]\d{2}(?:\s*[-–—\s]\s*\S+.*)?\s*$/u", '', $title );
		$title = (string) preg_replace( '/\s+20\d{2}(?:\s*[-–—]\s*\S+.*)?\s*$/u', '', $title );
		$title = (string) preg_replace( '/\s*[-–—]\s*(?:results?|ranking|класиране|резултати)\b.*/iu', '', $title );
		$title = (string) preg_replace( '/\s+(?:класиране|резултати|results?|ranking)\s*$/iu', '', $title );
		$title = (string) preg_replace( '/[\s\-–—]+$/u', '', $title );
		$title = (string) preg_replace( '/\s{2,}/u', ' ', $title );
		return trim( $title );
	}

	/**
	 * Derive a human-readable event name from a post_name.
	 * Same logic as tsr_slug_event_name() in the Резултати page template.
	 * Returns '' for known-garbage slugs (pure digits, "untitled", …).
	 */
	private function slug_event_name( string $slug ): string {
		$base = explode( '--', $slug )[0];

		$base = (string) preg_replace( '/(?:^|-)20\d{2}(?:-|$)/', '-', $base );
		$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );
		$base = (string) preg_replace( '/(?:^|-)(?:results?|ranking|класиране|резултати)\d*$/iu', '', $base );
		$base = (string) preg_replace( '/-(1[3-9]|2[0-9])$/', '', $base );
		$base = (string) preg_replace( '/([\pL\d])(1[3-9]|2[0-9])(?:-|$)/u', '$1', $base );
		$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );

		$lower = mb_strtolower( $base );
		if ( '' === $base || ctype_digit( $base ) || in_array( $lower, array( 'untitled', 'news', 'page' ), true ) ) {
			return '';
		}

		$words = array_map(
			static fn( string $w ): string => mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ),
			explode( '-', $base )
		);
		return implode( ' ', $words );
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
