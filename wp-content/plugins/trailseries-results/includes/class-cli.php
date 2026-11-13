<?php
declare( strict_types=1 );
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
		WP_CLI::add_command( 'tsr prune',        array( $instance, 'prune' ) );
		WP_CLI::add_command( 'tsr verify-import', array( $instance, 'verify_import' ) );
		WP_CLI::add_command( 'tsr apply-seasons', array( $instance, 'apply_seasons' ) );
		WP_CLI::add_command( 'tsr import-standings', array( $instance, 'import_standings' ) );
		WP_CLI::add_command( 'tsr alias', array( $instance, 'alias' ) );
	}

	/**
	 * Map a spelling of a runner's name to the canonical one (audit M2).
	 *
	 * Use only when the owner has confirmed both spellings are one person.
	 * Stored results are not changed; standings, search and profiles
	 * aggregate under the canonical name.
	 *
	 * ## OPTIONS
	 *
	 * [<variant>]
	 * : The spelling as it appears in the results ("First Last").
	 *
	 * [<canonical>]
	 * : The name to show and aggregate under.
	 *
	 * [--list]
	 * : Print the current aliases.
	 *
	 * [--remove]
	 * : Remove the alias of <variant>.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr alias "Ivan Testov-fast" "Ivan Testov - Fast"
	 *     wp tsr alias --list
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function alias( array $args, array $assoc_args ): void {
		$map = (array) get_option( TSR_Runner_Alias::OPTION, array() );
		if ( isset( $assoc_args['list'] ) ) {
			foreach ( $map as $key => $canonical ) {
				WP_CLI::log( "{$key}  =>  {$canonical}" );
			}
			WP_CLI::success( count( $map ) . ' alias(es).' );
			return;
		}
		$variant = (string) ( $args[0] ?? '' );
		if ( isset( $assoc_args['remove'] ) ) {
			unset( $map[ TSR_Runner_Alias::key( $variant ) ] );
		} else {
			try {
				$map = TSR_Runner_Alias::with( $map, $variant, (string) ( $args[1] ?? '' ) );
			} catch ( InvalidArgumentException $e ) {
				WP_CLI::error( $e->getMessage() );
				return;
			}
		}
		update_option( TSR_Runner_Alias::OPTION, $map, false );
		do_action( 'tsr_results_updated' );
		WP_CLI::success( count( $map ) . ' alias(es) stored; caches flushed.' );
	}

	/**
	 * Import the old site's official season standings (audit H1 step 2).
	 *
	 * Reads every season-*.json written by
	 * migration/extract_official_standings.py, validates it
	 * (TSR_Official_Standings::validate) and stores it. All files are checked
	 * before anything is written. The directory must be outside the web root:
	 * the files hold names of real people.
	 *
	 * ## OPTIONS
	 *
	 * --dir=<path>
	 * : Directory with season-*.json.
	 *
	 * [--dry-run]
	 * : Validate and report without storing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr import-standings --dir=/home/trailser/tsr-data/migration/data/official-standings --dry-run
	 *
	 * @subcommand import-standings
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function import_standings( array $args, array $assoc_args ): void {
		$dir = rtrim( (string) ( $assoc_args['dir'] ?? '' ), '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			WP_CLI::error( 'Pass --dir=<directory with season-*.json>.' );
			return;
		}
		$real = realpath( $dir );
		$root = realpath( ABSPATH );
		if ( false === $real || ( false !== $root && str_starts_with( $real . '/', rtrim( $root, '/' ) . '/' ) ) ) {
			WP_CLI::error( 'Refusing a directory inside the web root — the files hold personal data.' );
			return;
		}
		$files = glob( $real . '/season-*.json' ) ?: array();
		if ( array() === $files ) {
			WP_CLI::error( 'No season-*.json in --dir.' );
			return;
		}
		$docs = array();
		foreach ( $files as $file ) {
			try {
				$doc = TSR_Official_Standings::validate( json_decode( (string) file_get_contents( $file ), true, 32, JSON_THROW_ON_ERROR ) );
			} catch ( Throwable $e ) {
				WP_CLI::error( basename( $file ) . ': ' . $e->getMessage() );
				return;
			}
			$docs[ $doc['season'] ] = $doc;
		}
		ksort( $docs );
		foreach ( $docs as $doc ) {
			WP_CLI::log( sprintf( '%s (%d) %-7s men %3d women %3d podiums %s%s',
				$doc['label'], $doc['season'], $doc['kind'], count( $doc['m'] ), count( $doc['f'] ),
				$doc['has_podiums'] ? 'yes' : 'no', array() !== $doc['notes'] ? ' — note: ' . implode( ' ', $doc['notes'] ) : '' ) );
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( sprintf( 'Dry run: %d season(s) valid, nothing stored.', count( $docs ) ) );
			return;
		}
		foreach ( $docs as $doc ) {
			TSR_Official_Standings::store( $doc );
		}
		do_action( 'tsr_results_updated' );
		WP_CLI::success( sprintf( 'Stored %d season(s).', count( $docs ) ) );
	}

	/**
	 * Apply the 2012–2020 race years and series seasons (audit H1).
	 *
	 * Reads migration/series-seasons.csv (one row per old results page:
	 * page_slug, race_year, series_season) and, for every ts_result post of
	 * that page (slug equal to the page slug or "{page}-…", longest page slug
	 * wins), sets `_tsr_season` to the race year and `_tsr_series_season` to
	 * the season start year or `none`. Idempotent — re-run it after any bulk
	 * re-import. Also lists 2012–2020 posts that no CSV row covers.
	 *
	 * ## OPTIONS
	 *
	 * --file=<csv>
	 * : Path to series-seasons.csv.
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr apply-seasons --file=/home/trailser/tsr-data/migration/series-seasons.csv --dry-run
	 *
	 * @subcommand apply-seasons
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function apply_seasons( array $args, array $assoc_args ): void {
		global $wpdb;
		$path = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			WP_CLI::error( 'Cannot read --file.' );
			return;
		}
		$dry = isset( $assoc_args['dry-run'] );
		try {
			$pages = TSR_Series_Seasons::parse_csv( (string) file_get_contents( $path ) );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$slugs = array_keys( $pages );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$posts = $wpdb->get_results(
			"SELECT p.ID, p.post_name, p.post_status,
			        MAX( CASE WHEN m.meta_key = '_tsr_season' THEN m.meta_value END ) AS season,
			        MAX( CASE WHEN m.meta_key = '" . TSR_Series_Seasons::META_KEY . "' THEN m.meta_value END ) AS series
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( '_tsr_season', '" . TSR_Series_Seasons::META_KEY . "' )
			  WHERE p.post_type = 'ts_result' AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			  GROUP BY p.ID, p.post_name, p.post_status"
		);

		$hit        = array_fill_keys( $slugs, 0 );
		$changes    = array( 'race_year' => 0, 'series' => 0, 'posts' => 0 );
		$uncovered  = array();
		$per_season = array();
		foreach ( $posts as $post ) {
			$slug = urldecode( (string) $post->post_name );
			$page = TSR_Series_Seasons::page_for( $slug, $slugs );
			if ( null === $page ) {
				$y = (int) $post->season;
				if ( 'publish' === $post->post_status && ( 0 === $y || $y <= TSR_Series_Seasons::LAST_SPLIT_YEAR ) ) {
					$uncovered[] = sprintf( '%d %s (season %s)', $post->ID, $slug, '' !== (string) $post->season ? $post->season : '—' );
				}
				continue;
			}
			++$hit[ $page ];
			$want_year   = (string) $pages[ $page ]['race_year'];
			$want_series = $pages[ $page ]['series_season'];
			$per_season[ $want_series ] = ( $per_season[ $want_series ] ?? 0 ) + 1;
			$touched     = false;
			if ( (string) $post->season !== $want_year ) {
				++$changes['race_year'];
				$touched = true;
				if ( ! $dry ) {
					update_post_meta( (int) $post->ID, '_tsr_season', $want_year );
				}
			}
			if ( (string) $post->series !== $want_series ) {
				++$changes['series'];
				$touched = true;
				if ( ! $dry ) {
					update_post_meta( (int) $post->ID, TSR_Series_Seasons::META_KEY, $want_series );
				}
			}
			if ( $touched ) {
				++$changes['posts'];
			}
		}

		ksort( $per_season );
		WP_CLI::log( sprintf( 'CSV pages: %d. Posts matched: %d.', count( $pages ), array_sum( $hit ) ) );
		WP_CLI::log( 'Sections per series season: ' . implode( ', ', array_map( static fn( $k, $v ) => "{$k}={$v}", array_keys( $per_season ), $per_season ) ) );
		WP_CLI::log( sprintf( '%s %d post(s): race year on %d, series season on %d.', $dry ? 'Would change' : 'Changed', $changes['posts'], $changes['race_year'], $changes['series'] ) );
		foreach ( array_keys( array_filter( $hit, static fn( int $n ): bool => 0 === $n ) ) as $page ) {
			WP_CLI::warning( "CSV page with no posts: {$page}" );
		}
		foreach ( $uncovered as $line ) {
			WP_CLI::warning( "2012–2020 post not in the CSV: {$line}" );
		}
		if ( $dry ) {
			WP_CLI::success( 'Dry run. Nothing written.' );
			return;
		}
		if ( $changes['posts'] > 0 ) {
			do_action( 'tsr_results_updated' );
		}
		WP_CLI::success( 'Seasons applied.' );
	}

	/**
	 * Verify the published results against the data set's manifest (audit H10).
	 *
	 * For every manifest section with a JSON file (minus --exclude pages), the
	 * post bulk-import writes for it must exist, be published, carry the
	 * manifest's names hash in _tsr_names_sha256, and the hash recomputed from
	 * the stored rows must match too (meta and data agree). Together with
	 * migration/verify_names.py (data set vs. WXR source) this closes the
	 * chain source → JSON → published post.
	 *
	 * ## OPTIONS
	 *
	 * [--manifest=<file>]
	 * : Path to _manifest.csv. Default: TSR_DATA_DIR/_manifest.csv
	 *
	 * [--exclude=<slugs>]
	 * : Page slugs bulk-import skips (must match its --exclude). Default: news
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr verify-import --manifest=/home/trailser/tsr-data/migration/data/canonical_fixed/_manifest.csv
	 *
	 * @subcommand verify-import
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function verify_import( array $args, array $assoc_args ): void {
		$excluded      = array_filter( array_map( 'trim', explode( ',', $assoc_args['exclude'] ?? 'news' ) ) );
		$manifest_path = $assoc_args['manifest']
			?? ( ( defined( 'TSR_DATA_DIR' ) && is_string( TSR_DATA_DIR ) && '' !== TSR_DATA_DIR )
				? rtrim( TSR_DATA_DIR, '/\\' ) . DIRECTORY_SEPARATOR . '_manifest.csv'
				: '' );
		if ( '' === $manifest_path || ! is_readable( $manifest_path ) ) {
			WP_CLI::error( 'Cannot read manifest: pass --manifest or define TSR_DATA_DIR.' );
			return;
		}

		$failures        = array();
		$checked         = 0;
		$bare_slug_given = array();
		foreach ( $this->read_manifest( $manifest_path ) as $row ) {
			if ( empty( $row['file'] ) || in_array( $row['slug'], $excluded, true ) ) {
				continue;
			}
			$post_slug                         = $this->derive_post_slug( $row['slug'], $row['file'], $bare_slug_given );
			$bare_slug_given[ $row['slug'] ] = true;
			$stored_slug                       = sanitize_title( $post_slug );
			$label                             = sprintf( '%s [%s]', $row['slug'], '' !== $row['category_raw'] ? $row['category_raw'] : 'no category' );
			++$checked;

			$post = $this->find_existing_by_slug( $stored_slug );
			if ( null === $post ) {
				$failures[] = "MISSING   {$label}: no post {$stored_slug}";
				continue;
			}
			if ( 'publish' !== $post->post_status ) {
				$failures[] = "STATUS    {$label}: post {$post->ID} is {$post->post_status}";
			}
			$meta_hash = (string) get_post_meta( $post->ID, TSR_Repository::NAMES_HASH_KEY, true );
			if ( $meta_hash !== $row['names_sha256'] ) {
				$failures[] = "HASH      {$label}: post {$post->ID} meta hash differs from the manifest";
				continue;
			}
			$set = TSR_Repository::load( $post->ID );
			if ( null === $set || TSR_Repository::names_hash( $set ) !== $row['names_sha256'] || count( $set->rows() ) !== (int) $row['rows'] ) {
				$failures[] = "DATA      {$label}: post {$post->ID} stored rows do not match the manifest";
			}
		}

		WP_CLI::log( sprintf( 'Checked %d manifest sections against their published posts.', $checked ) );
		foreach ( $failures as $line ) {
			WP_CLI::log( $line );
		}
		if ( array() !== $failures ) {
			WP_CLI::error( sprintf( '%d problem(s).', count( $failures ) ) );
		}
		WP_CLI::success( 'Every manifest section is published with matching names (meta and stored data).' );
	}

	/**
	 * List (and optionally trash) leftover section posts of migrated pages.
	 *
	 * bulk-import only creates and updates (audit M4). A section renamed by an
	 * override, dropped from the manifest, or hand-fixed under another slug
	 * leaves its old post published next to the current one, and the hub page
	 * renders both.
	 *
	 * Scope — only posts that belong to a page in the manifest: post_name is
	 * the page slug or starts with "{page_slug}-" (the same prefix rule the
	 * hub view uses; no page slug is a dash-prefix of another). Posts outside
	 * that scope (admin uploads of new races, anything else) are never touched.
	 * A candidate is an in-scope post whose slug bulk-import does NOT derive
	 * from the current manifest.
	 *
	 * Each candidate is classified against the expected posts of its page:
	 *   DUPLICATE — same names hash as an expected post: the identical table
	 *               is already shown. Trashable.
	 *   OLD       — ≥ 90 % of its runner names appear in ONE expected post: an
	 *               earlier extraction of a section the page still has.
	 *               Trashable.
	 *   SPLIT     — every runner appears in the page's current sections
	 *               together (a merged section the extractor now splits).
	 *               Trashable.
	 *   EXTRA     — anything else: a table the current manifest does not
	 *               have at all (e.g. relay and kids' sections that the August
	 *               re-extraction lost and that were re-created by hand on
	 *               2026-08-22). Never trashed automatically — fix the
	 *               manifest instead, or pass --ids after manual review.
	 * A names hash alone cannot tell OLD from EXTRA; the name overlap can.
	 *
	 * ## OPTIONS
	 *
	 * [--manifest=<file>]
	 * : Path to _manifest.csv. Default: TSR_DATA_DIR/_manifest.csv
	 *
	 * [--exclude=<slugs>]
	 * : Page slugs bulk-import skips (must match its --exclude). Default: news
	 *
	 * [--slug=<pattern>]
	 * : Only pages whose slug contains this string.
	 *
	 * [--trash]
	 * : Move DUPLICATE, OLD and SPLIT candidates to the trash (reversible).
	 *   Without it the command only lists.
	 *
	 * [--ids=<ids>]
	 * : With --trash: only these post IDs (comma-separated); may include
	 *   EXTRA candidates after manual review.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tsr prune
	 *     wp tsr prune --slug=simeonovo-run18
	 *     wp tsr prune --trash
	 *     wp tsr prune --trash --ids=1336,7285
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function prune( array $args, array $assoc_args ): void {
		$do_trash    = isset( $assoc_args['trash'] );
		$slug_filter = $assoc_args['slug'] ?? '';
		$excluded    = array_filter( array_map( 'trim', explode( ',', $assoc_args['exclude'] ?? 'news' ) ) );
		$only_ids    = isset( $assoc_args['ids'] )
			? array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $assoc_args['ids'] ) ) ) )
			: null;

		$manifest_path = $assoc_args['manifest']
			?? ( ( defined( 'TSR_DATA_DIR' ) && is_string( TSR_DATA_DIR ) && '' !== TSR_DATA_DIR )
				? rtrim( TSR_DATA_DIR, '/\\' ) . DIRECTORY_SEPARATOR . '_manifest.csv'
				: '' );
		if ( '' === $manifest_path || ! is_readable( $manifest_path ) ) {
			WP_CLI::error( 'Cannot read manifest: pass --manifest or define TSR_DATA_DIR.' );
			return;
		}
		$rows = $this->read_manifest( $manifest_path );

		// Expected stored slugs, derived exactly as bulk_import() does.
		$expected        = array(); // stored slug => page slug
		$bare_slug_given = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['file'] ) || in_array( $row['slug'], $excluded, true ) ) {
				continue;
			}
			$page_slug = $row['slug'];
			$post_slug = $this->derive_post_slug( $page_slug, $row['file'], $bare_slug_given );
			$bare_slug_given[ $page_slug ] = true;
			$expected[ sanitize_title( $post_slug ) ] = $page_slug;
		}
		$pages = array();
		foreach ( array_unique( array_values( $expected ) ) as $page_slug ) {
			if ( '' === $slug_filter || str_contains( $page_slug, $slug_filter ) ) {
				$pages[ sanitize_title( $page_slug ) ] = $page_slug;
			}
		}

		global $wpdb;
		$all = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_name, post_title, post_date FROM {$wpdb->posts}
			  WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
			  ORDER BY post_name",
			TSR_Post_Types::POST_TYPE
		) );

		// Group in-scope posts by page; expected posts per page.
		$candidates   = array();
		$held_by_page = array(); // page slug => list of [post ID, names hash]
		foreach ( $all as $p ) {
			$page = null;
			foreach ( $pages as $stored_page => $page_slug ) {
				if ( $p->post_name === $stored_page || str_starts_with( $p->post_name, $stored_page . '-' ) ) {
					$page = $page_slug;
					break;
				}
			}
			if ( null === $page ) {
				continue; // Not a migrated page: never touched.
			}
			$hash = (string) get_post_meta( (int) $p->ID, TSR_Repository::NAMES_HASH_KEY, true );
			if ( isset( $expected[ $p->post_name ] ) ) {
				$held_by_page[ $page ][] = array( (int) $p->ID, $hash );
			} else {
				$candidates[] = array( 'post' => $p, 'page' => $page, 'hash' => $hash );
			}
		}

		$trashable = array();
		$counts    = array( 'DUPLICATE' => 0, 'OLD' => 0, 'SPLIT' => 0, 'EXTRA' => 0 );
		foreach ( $candidates as $c ) {
			$set     = TSR_Repository::load( (int) $c['post']->ID );
			$names   = null !== $set ? TSR_Prune_Rules::row_names( $set ) : array();
			$current = array();
			foreach ( $held_by_page[ $c['page'] ] ?? array() as [ $held_id, $held_hash ] ) {
				$held_set  = TSR_Repository::load( $held_id );
				$current[] = array(
					'id'    => $held_id,
					'hash'  => $held_hash,
					'names' => null !== $held_set ? TSR_Prune_Rules::row_names( $held_set ) : array(),
				);
			}
			[ 'class' => $class, 'match' => $match, 'best' => $best ] = TSR_Prune_Rules::classify( $c['hash'], $names, $current );
			++$counts[ $class ];
			WP_CLI::log( sprintf(
				'%-9s [%d]  %-55s rows=%-4s %s  best=[%d] %3d%%  page=%s',
				$class,
				$c['post']->ID,
				urldecode( $c['post']->post_name ),
				(string) count( $names ),
				substr( $c['post']->post_date, 0, 10 ),
				$match,
				'DUPLICATE' === $class ? 100 : (int) round( $best * 100 ),
				$c['page']
			) );
			if ( null !== $only_ids ? in_array( (int) $c['post']->ID, $only_ids, true ) : in_array( $class, TSR_Prune_Rules::TRASHABLE, true ) ) {
				$trashable[] = (int) $c['post']->ID;
			}
		}

		WP_CLI::log( sprintf(
			'Pages in scope: %d. Candidates: %d (DUPLICATE %d, OLD %d, SPLIT %d, EXTRA %d — EXTRA is never trashed without --ids).',
			count( $pages ),
			count( $candidates ),
			$counts['DUPLICATE'],
			$counts['OLD'],
			$counts['SPLIT'],
			$counts['EXTRA']
		) );

		if ( ! $do_trash ) {
			WP_CLI::success( 'Listed only. Re-run with --trash to move DUPLICATE, OLD and SPLIT candidates to the trash.' );
			return;
		}
		if ( null !== $only_ids && count( $trashable ) !== count( $only_ids ) ) {
			WP_CLI::error( 'Some --ids are not prune candidates; nothing trashed.' );
			return;
		}

		$done = 0;
		foreach ( $trashable as $id ) {
			if ( wp_trash_post( $id ) ) {
				++$done;
				WP_CLI::log( sprintf( 'trashed [%d]', $id ) );
			} else {
				WP_CLI::warning( sprintf( 'could not trash [%d]', $id ) );
			}
		}
		if ( $done > 0 ) {
			do_action( 'tsr_results_updated', 0 );
		}
		// wp_untrash_post() restores to 'draft' since WP 5.6, so publish afterwards.
		WP_CLI::success( sprintf( 'Trashed %d post(s). Undo per post: wp eval \'wp_untrash_post( ID );\' then wp post update ID --post_status=publish', $done ) );
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
	 *     wp tsr import 123 /home/trailser/tsr-data/migration/data/canonical_fixed/iran-run18-results__16.7km-m.json
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
	 *     wp tsr verify-names 123 /home/trailser/tsr-data/migration/data/canonical_fixed/iran-run18-results__16.7km-m.json
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
	 * The canonical JSON files contain personal data (names, ages) and must
	 * live OUTSIDE the web root. There is deliberately no default under
	 * ABSPATH: define TSR_DATA_DIR in wp-config.php (the directory holding
	 * _manifest.csv and the JSON files), or pass both paths explicitly.
	 *
	 * [--manifest=<file>]
	 * : Path to _manifest.csv.
	 *   Default: TSR_DATA_DIR/_manifest.csv
	 *
	 * [--json-dir=<dir>]
	 * : Directory containing the JSON files.
	 *   Default: TSR_DATA_DIR
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
	 * [--map=<file>]
	 * : category-map.csv used to derive distance and gender.
	 *   Default: {plugin}/data/category-map.csv
	 *
	 * [--exclude=<slugs>]
	 * : Comma-separated page slugs never imported as results. Default: news
	 *   (the podium tables of the news post /news/, which the extractor took
	 *   for results; a ts_result hub "news" would collide with that post).
	 *
	 * Titles are "{page_title} — {label}", label = override_applied when the
	 * manifest has one, else category_raw (audit B3). Distance, category,
	 * gender, season and event meta are written by the import itself, from
	 * the manifest via derive_section_meta() (audit B4); a value that cannot
	 * be derived is deleted rather than left stale. The dry run lists every
	 * meta change on existing posts and summarises gender and distance
	 * changes against what the site uses today.
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
		$excluded      = array_filter( array_map( 'trim', explode( ',', $assoc_args['exclude'] ?? 'news' ) ) );
		$map_path      = $assoc_args['map'] ?? TSR_PLUGIN_DIR . 'data/category-map.csv';
		$map           = $this->read_category_map( $map_path );
		if ( array() === $map ) {
			WP_CLI::error( sprintf( 'Cannot read category map (or it is empty): %s', $map_path ) );
			return;
		}

		// No default under ABSPATH: the canonical JSON is personal data and
		// was once served straight from the web root because the default
		// pointed there (docs/AUDIT-2026-11.md, H11).
		$data_dir = ( defined( 'TSR_DATA_DIR' ) && is_string( TSR_DATA_DIR ) && '' !== TSR_DATA_DIR )
			? rtrim( TSR_DATA_DIR, '/\\' )
			: null;
		if ( null === $data_dir && ( ! isset( $assoc_args['manifest'] ) || ! isset( $assoc_args['json-dir'] ) ) ) {
			WP_CLI::error( 'No data location: define TSR_DATA_DIR in wp-config.php (a directory OUTSIDE the web root), or pass both --manifest and --json-dir.' );
			return;
		}
		$manifest_path = $assoc_args['manifest'] ?? $data_dir . DIRECTORY_SEPARATOR . '_manifest.csv';
		$json_dir      = rtrim( $assoc_args['json-dir'] ?? $data_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		// Refuse to read results data from inside the web root at all.
		$web_root = wp_normalize_path( (string) realpath( ABSPATH ) );
		foreach ( array( dirname( $manifest_path ), $json_dir ) as $tsr_dir ) {
			$real = realpath( $tsr_dir );
			if ( false !== $real && '' !== $web_root
				&& str_starts_with( trailingslashit( wp_normalize_path( $real ) ), trailingslashit( $web_root ) ) ) {
				WP_CLI::error( sprintf( 'Refusing to read results data from inside the web root: %s — move it outside %s.', $real, $web_root ) );
				return;
			}
		}

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
		$excluded_count  = 0;
		$bare_slug_given = array(); // page_slug → true once its first section is assigned.
		$audit           = array(
			'title_changed'  => 0,
			'meta_changed'   => 0,
			'gender_gained'  => array(), // '' today → M/F (starts scoring)
			'gender_flipped' => array(), // M ↔ F
			'gender_lost'    => array(), // M/F today → '' / mixed (stops scoring)
			'km_changed'     => array(), // old value → different new value
			'km_removed'     => array(), // had a value, now underivable
			'no_km_before'   => 0,
			'no_km_after'    => 0,
			'labels'         => array(), // page_slug → list of labels (duplicate check)
		);

		foreach ( $manifest_rows as $row ) {
			if ( empty( $row['file'] ) ) {
				continue; // Section produced no JSON (all rows were issues).
			}

			$page_slug = $row['slug'];
			if ( $slug_filter && false === strpos( $page_slug, $slug_filter ) ) {
				continue;
			}
			if ( in_array( $page_slug, $excluded, true ) ) {
				++$excluded_count;
				continue;
			}

			// Derive post slug: first section gets the original page slug.
			$post_slug                      = $this->derive_post_slug( $page_slug, $row['file'], $bare_slug_given );
			$bare_slug_given[ $page_slug ]  = true;

			// Derive label (B3), title and meta (B4).
			$label = trim( (string) ( $row['override_applied'] ?? '' ) );
			if ( '' === $label ) {
				$label = trim( (string) ( $row['category_raw'] ?? '' ) );
			}
			// trim(): WordPress stores titles trimmed; a page with no title
			// would otherwise differ from its stored form by a leading space.
			$post_title = trim( $row['page_title'] . ( '' !== $label ? ' — ' . $label : '' ) );
			$meta       = $this->derive_section_meta(
				(string) $row['page_title'],
				$page_slug,
				$label,
				(string) ( $row['distance_km'] ?? '' ),
				(string) ( $row['gender'] ?? '' ),
				$map
			);
			$meta_new   = $this->meta_writes( $meta );
			$audit['labels'][ $page_slug ][] = $label;

			// Resolve the actual on-disk path. On Linux, ZIP extraction may store
			// Cyrillic filenames as raw Windows code-page bytes instead of UTF-8,
			// so direct path construction fails. resolve_json_path() tries iconv
			// re-encoding and a directory-scan fallback before returning null.
			$json_file = $this->resolve_json_path( $json_dir, $row['file'] );

			// Check for existing ts_result post with this slug. The database
			// stores the sanitize_title()'d form — derive_post_slug()'s '--'
			// separator collapses to a single dash and Cyrillic is percent-
			// encoded — so the raw derived slug never matches a stored
			// non-hub section. Looking up the raw form (the old
			// get_page_by_path() call) made every re-run insert a colliding
			// post that WordPress silently renamed with a '-2' suffix.
			$expected_slug = sanitize_title( $post_slug );
			$existing      = $this->find_existing_by_slug( $expected_slug );
			$meta_diff     = $existing ? $this->audit_meta_change( $existing, $post_title, $meta_new, $audit ) : array();
			if ( null === $meta_new['_tsr_distance_km'] ) {
				++$audit['no_km_after'];
			}

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
				if ( array() !== $meta_diff ) {
					WP_CLI::log( '          ' . implode( '  ', $meta_diff ) );
				}
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

				// The '--' → '-' collapse is expected; only warn when WordPress
				// stored something OTHER than the sanitized form we looked up.
				// That means a slug collision (WP appended '-2'): a post this
				// run should have matched exists but find_existing_by_slug()
				// missed it — never let that pass silently again.
				$stored_slug = get_post_field( 'post_name', $post_id );
				if ( $stored_slug !== $expected_slug ) {
					WP_CLI::warning( sprintf(
						'[%d] Slug collision: expected stored form %s but WordPress stored %s — check for an unmatched existing post',
						$post_id,
						$expected_slug,
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

			// Meta after the data import succeeded; a cache flush at the end of
			// the run covers these meta-only writes (audit M3).
			foreach ( $meta_new as $meta_key => $value ) {
				if ( null === $value ) {
					if ( in_array( $meta_key, TSR_Section_Meta::IMPORT_DELETABLE_META, true ) ) {
						delete_post_meta( (int) $post_id, $meta_key );
					}
				} else {
					update_post_meta( (int) $post_id, $meta_key, $value );
				}
			}

			$verb = $existing ? 'UPDATE' : 'CREATE';
			WP_CLI::log( sprintf(
				'%-6s  [%d]  slug=%-55s  rows=%-4d  sha=%s…',
				$verb,
				$post_id,
				$post_slug,
				$row_count,
				substr( $hash, 0, 12 )
			) );
			if ( array() !== $meta_diff ) {
				WP_CLI::log( '          ' . implode( '  ', $meta_diff ) );
			}

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

		if ( ! $dry_run && ( $created + $updated ) > 0 ) {
			do_action( 'tsr_results_updated', 0 ); // Meta written after the last save (M3).
		}

		$this->print_import_audit( $audit, $excluded_count, $excluded );

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
			'gender'      => 0,
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

			// ── Derive (shared with bulk-import, audit B3/B4) ─────────────────
			// Title is "{page_title} — {label}"; the page part feeds season and
			// event name, the label feeds distance and gender. No slug fallback
			// for the distance (B4). The slug only stands in for the page slug.
			$label      = $this->title_category_suffix( $post->post_title );
			$page_title = '' !== $label
				? trim( mb_substr( $post->post_title, 0, (int) mb_strrpos( $post->post_title, ' — ' ) ) )
				: $post->post_title;
			$meta       = $this->derive_section_meta( $page_title, $post->post_name, $label, '', '', $map );
			$km         = $meta['km'];
			$season     = $meta['season'];
			$event_base = (string) $meta['event_base'];

			// ── Collect writes (respecting --force vs fill-missing) ──────────
			$derived = $this->meta_writes( $meta );
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
				'%s[%d]  %-50s  km=%-5s cat=%-6s g=%-5s season=%-4s base=%s',
				$dry_run ? '[DRY] ' : '',
				$post->ID,
				mb_strimwidth( $post->post_name, 0, 50, '…' ),
				$derived['_tsr_distance_km'] ?? '—',
				$derived['_tsr_distance_cat'] ?? '—',
				$derived['_tsr_gender'] ?? '—',
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
			if ( isset( $writes['_tsr_gender'] ) )       { ++$stats['gender']; }
		}

		// Meta-only writes never pass through TSR_Repository::save(), so the
		// result caches (rankings, records, totals) must be told (audit M3).
		if ( ! $dry_run && ( $stats['km'] + $stats['cat'] + $stats['event_base'] + $stats['season'] + $stats['gender'] ) > 0 ) {
			do_action( 'tsr_results_updated', 0 );
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
			'Processed %d posts. Wrote: km=%d cat=%d gender=%d event_base=%d season=%d. Existing values kept: %d.',
			$stats['processed'],
			$stats['km'],
			$stats['cat'],
			$stats['gender'],
			$stats['event_base'],
			$stats['season'],
			$stats['kept']
		) );
	}

	// ── section meta and prune rules: see class-section-meta.php and
	//    class-prune-rules.php (pure, unit-tested; audit H10) ─────────────────

	/** @return array{label: string, km: ?string, cat: ?string, gender: string, season: ?string, event_base: ?string} */
	private function derive_section_meta( string $page_title, string $page_slug, string $label, string $manifest_km, string $manifest_gender, array $map ): array {
		return TSR_Section_Meta::derive( $page_title, $page_slug, $label, $manifest_km, $manifest_gender, $map );
	}

	/** @return array<string, ?string> */
	private function meta_writes( array $meta ): array {
		return TSR_Section_Meta::meta_writes( $meta );
	}

	/** @return array<string, array{km: string, gender: string}> */
	private function read_category_map( string $path ): array {
		return TSR_Section_Meta::read_category_map( $path );
	}

	private function norm_cat_key( string $s ): string {
		return TSR_Section_Meta::norm_cat_key( $s );
	}

	private function title_category_suffix( string $title ): string {
		return TSR_Section_Meta::title_category_suffix( $title );
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
	 * Find an existing ts_result post by its STORED slug.
	 *
	 * Callers must pass the sanitize_title()'d form of the derived slug —
	 * that is what wp_insert_post() wrote to post_name. get_page_by_path()
	 * is unsuitable here because it matches the raw path string, which for
	 * non-hub sections ('{page_slug}--{cat_part}') and Cyrillic slugs never
	 * equals the stored form.
	 *
	 * post_status 'any' deliberately includes drafts and pending posts so a
	 * half-finished import is matched rather than duplicated; trashed posts
	 * are excluded by 'any' but carry a '__trashed' slug suffix anyway.
	 *
	 * @param string $stored_slug sanitize_title()'d slug to look up.
	 * @return WP_Post|null Matching post, or null when none exists.
	 */
	private function find_existing_by_slug( string $stored_slug ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'   => TSR_Post_Types::POST_TYPE,
				'name'        => $stored_slug,
				'post_status' => 'any',
				'numberposts' => 1,
			)
		);

		return $posts[0] ?? null;
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
	 * Compare an existing post with what the import is about to write, record
	 * the change in $audit and return printable "key: old→new" items.
	 *
	 * Gender is compared by EFFECT, not by stored meta (no post has
	 * _tsr_gender before the first run): "old" is what tsr_race_gender()
	 * returns today, "new" is what it will return afterwards. 'mixed' counts
	 * as '' because standings skip undetermined-gender categories.
	 *
	 * @param array<string, ?string> $meta_new From meta_writes().
	 * @param array<string, mixed>   $audit    Accumulator, see bulk_import().
	 * @return list<string>
	 */
	private function audit_meta_change( WP_Post $post, string $new_title, array $meta_new, array &$audit ): array {
		$diff = array();
		$slug = urldecode( $post->post_name );

		if ( $post->post_title !== $new_title ) {
			++$audit['title_changed'];
			$diff[] = sprintf( 'label: "%s"→"%s"', $this->title_category_suffix( $post->post_title ), $this->title_category_suffix( $new_title ) );
		}

		$old_km = (string) get_post_meta( $post->ID, '_tsr_distance_km', true );
		$new_km = (string) ( $meta_new['_tsr_distance_km'] ?? '' );
		if ( '' === $old_km ) {
			++$audit['no_km_before'];
		}
		if ( $old_km !== $new_km ) {
			if ( '' === $new_km ) {
				$audit['km_removed'][] = sprintf( '%s (%s)', $slug, $old_km );
			} elseif ( '' !== $old_km ) {
				$audit['km_changed'][] = sprintf( '%s %s→%s', $slug, $old_km, $new_km );
			}
			$diff[] = sprintf( 'km: %s→%s', '' !== $old_km ? $old_km : '∅', '' !== $new_km ? $new_km : '∅' );
		}

		$old_cat = (string) get_post_meta( $post->ID, '_tsr_distance_cat', true );
		$new_cat = (string) ( $meta_new['_tsr_distance_cat'] ?? '' );
		if ( $old_cat !== $new_cat ) {
			$diff[] = sprintf( 'cat: %s→%s', '' !== $old_cat ? $old_cat : '∅', '' !== $new_cat ? $new_cat : '∅' );
		}

		$old_g = tsr_race_gender( $post );
		$new_m = (string) ( $meta_new['_tsr_gender'] ?? '' );
		if ( 'M' === $new_m || 'F' === $new_m ) {
			$new_g = $new_m;
		} elseif ( 'mixed' === $new_m ) {
			$new_g = '';
		} else {
			$sim             = clone $post;
			$sim->post_title = $new_title;
			$new_g           = tsr_race_gender_from_text( $sim );
		}
		if ( $old_g !== $new_g ) {
			$item = sprintf( '%s %s→%s', $slug, '' !== $old_g ? $old_g : '∅', '' !== $new_g ? $new_g : '∅' );
			if ( '' === $old_g ) {
				$audit['gender_gained'][] = $item;
			} elseif ( '' === $new_g ) {
				$audit['gender_lost'][] = $item;
			} else {
				$audit['gender_flipped'][] = $item;
			}
			$diff[] = sprintf( 'gender: %s→%s', '' !== $old_g ? $old_g : '∅', '' !== $new_g ? $new_g : '∅' );
		}

		foreach ( array( '_tsr_season' => 'season', '_tsr_event_base' => 'event' ) as $key => $name ) {
			$old = (string) get_post_meta( $post->ID, $key, true );
			$new = (string) ( $meta_new[ $key ] ?? $old ); // Never deleted: underivable keeps the old value.
			if ( $old !== $new ) {
				$diff[] = sprintf( '%s: %s→%s', $name, '' !== $old ? $old : '∅', '' !== $new ? $new : '∅' );
			}
		}

		if ( array() !== $diff ) {
			++$audit['meta_changed'];
		}
		return $diff;
	}

	/**
	 * Print the bulk-import change summary (dry run and real run alike).
	 *
	 * @param array<string, mixed> $audit
	 * @param list<string>         $excluded
	 */
	private function print_import_audit( array $audit, int $excluded_count, array $excluded ): void {
		$dupes = array();
		foreach ( $audit['labels'] as $page_slug => $labels ) {
			$counts = array_count_values( array_map( fn( $l ) => $this->norm_cat_key( (string) $l ), $labels ) );
			$rep    = array_filter( $counts, static fn( $n ) => $n > 1 );
			if ( array() !== $rep ) {
				$dupes[] = sprintf( '%s [%s]', $page_slug, implode( ', ', array_map(
					static fn( $k, $n ) => ( '' !== $k ? $k : '(no label)' ) . ' ×' . $n,
					array_keys( $rep ),
					$rep
				) ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( '── Change summary (existing posts) ─────────────────────────────' );
		WP_CLI::log( sprintf( 'Excluded pages (%s): %d sections', implode( ',', $excluded ), $excluded_count ) );
		WP_CLI::log( sprintf( 'Posts with any change: %d   title/label changes: %d', $audit['meta_changed'], $audit['title_changed'] ) );
		WP_CLI::log( sprintf( 'No distance: before %d (existing posts)  →  after %d (all processed sections)', $audit['no_km_before'], $audit['no_km_after'] ) );
		$lists = array(
			'Distance changed'                              => $audit['km_changed'],
			'Distance removed (no longer derivable)'        => $audit['km_removed'],
			'Gender gained: undetermined → M/F (starts scoring)' => $audit['gender_gained'],
			'Gender flipped M ↔ F'                          => $audit['gender_flipped'],
			'Gender lost: M/F → undetermined (stops scoring)' => $audit['gender_lost'],
			'Hubs with repeated section labels'             => $dupes,
		);
		foreach ( $lists as $heading => $items ) {
			WP_CLI::log( sprintf( '%s: %d', $heading, count( $items ) ) );
			foreach ( array_slice( $items, 0, 60 ) as $item ) {
				WP_CLI::log( '    ' . $item );
			}
			if ( count( $items ) > 60 ) {
				WP_CLI::log( sprintf( '    … %d more', count( $items ) - 60 ) );
			}
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
