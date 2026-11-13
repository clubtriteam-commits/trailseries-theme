<?php
declare( strict_types=1 );
/**
 * Template Name: Резултати
 *
 * Template for the Резултати archive page (slug: rezultati).
 *
 * Queries all published ts_result posts and groups them two levels deep:
 *
 *   Year  — extracted from post_title first (apostrophe-year 'YY → 20YY, or
 *            literal 20YY), then from post_name when the title has none (2-digit
 *            year attached directly to a word: run15, ranking14, класиране16;
 *            or 4-digit year as a slug segment: run-14-07-2013). Never falls
 *            back to post_date (which is the import date, not the race year).
 *            Posts with no year signal in either source are grouped under
 *            key 0 and shown last, labelled "Без година".
 *
 *   Event — post_title with the results label ("- Results", "– класиране", etc.)
 *            and the year token both stripped, plus the " — {category_raw}"
 *            suffix that bulk-import appends. When the derived name is empty
 *            (page_title was blank — e.g. xmas-run-15-results), the post_name
 *            is converted to a human-readable fallback ("xmas-run-15-results"
 *            → "Xmas Run"). Posts with no usable name from either source are
 *            skipped entirely (fixes empty bullets in old seasons).
 *
 * Permalinks use get_permalink() — the CPT rewrite slug is '' (no prefix),
 * so posts live at /{post_name}/ in line with the old site's URL structure.
 *
 * @package exhibz-child
 */

// ── Helpers ─────────────────────────────────────────────────────────────────
//
// Year/event-name heuristics (tsr_title_year, tsr_slug_year,
// tsr_event_base_name, tsr_slug_event_name) live in the trailseries-results
// plugin: includes/event-heuristics.php — the single shared definition.
// tsr_slug_base() (hub resolution) lives in functions.php. Slug-based
// helpers take the BASE slug from tsr_slug_base(), never a raw section
// post_name (its category suffix cannot be split off textually — the
// importer's '--' separator is collapsed by sanitize_title() on insert).

// ── 1. Fetch all published ts_result posts ──────────────────────────────────

get_header();

// No meta priming: priming would load every post's `_tsr_result_set` blob
// (≈2.8 MB) just to read the season. Scalar meta comes from the small
// cached index instead (tsr_results_index(), audit H3).
$all_posts = get_posts( array(
	'post_type'              => 'ts_result',
	'posts_per_page'         => -1,
	'post_status'            => 'publish',
	'orderby'                => array( 'date' => 'DESC', 'title' => 'ASC' ),
	'suppress_filters'       => false,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
) );
$tsr_index = tsr_results_index();

// ── 2. Group: year_key → event_name → [ WP_Post, … ] ────────────────────────

$grouped      = array();

foreach ( $all_posts as $post ) {
	// Year: _tsr_season meta first (most authoritative), then title heuristics,
	// then slug heuristics, 0 = "Без година".
	// NEVER use post_date — it is the import date, not the race year.
	// Series season first (audit H1: 2012–2020 races belong to their October-
	// to-October season), then the race year — a non-series race (`none`)
	// is still listed, under its race year.
	$season_meta = $tsr_index[ $post->ID ]['series'] ?? '';
	if ( '' === $season_meta ) {
		$season_meta = $tsr_index[ $post->ID ]['season'] ?? '';
	}
	$year_key    = ( '' !== (string) $season_meta )
		? (int) $season_meta
		: ( tsr_title_year( $post->post_title )
			?? tsr_slug_year( tsr_slug_base( $post ) )
			?? 0 );

	// Event name: title-derived first, slug-derived as fallback.
	$event_name = tsr_event_base_name( $post->post_title );
	if ( $event_name === '' ) {
		$event_name = tsr_slug_event_name( tsr_slug_base( $post ) );
	}
	// Skip entries with no usable name — avoids empty bullets (issue 4).
	if ( $event_name === '' ) {
		continue;
	}

	// Keyed by name + race year: season 8 (Oct 2019 – Dec 2020) holds two
	// editions of Birthday/Cactus/Christmas Run, which must stay apart.
	$tsr_race_year = (int) ( $tsr_index[ $post->ID ]['season'] ?? 0 );
	$grouped[ $year_key ][ $event_name . "" . $tsr_race_year ][] = $post;
}

// Display names: plain event name, or "Name 2019" / "Name 2020" when the
// same race appears twice in one season. $tsr_event_base_of maps the
// display name back to the event name (event-page link, calendar dates).
$tsr_event_base_of = array();
foreach ( $grouped as $tsr_y => $tsr_by_key ) {
	$tsr_name_count = array_count_values( array_map( static fn( string $k ): string => explode( "", $k )[0], array_keys( $tsr_by_key ) ) );
	$tsr_named      = array();
	foreach ( $tsr_by_key as $tsr_k => $tsr_posts ) {
		[ $tsr_name, $tsr_ry ] = explode( "", $tsr_k );
		$tsr_display           = ( $tsr_name_count[ $tsr_name ] > 1 && '0' !== $tsr_ry ) ? $tsr_name . ' ' . $tsr_ry : $tsr_name;
		$tsr_named[ $tsr_display ]           = array_merge( $tsr_named[ $tsr_display ] ?? array(), $tsr_posts );
		$tsr_event_base_of[ $tsr_display ] = $tsr_name;
	}
	$grouped[ $tsr_y ] = $tsr_named;
}

// Sort years newest-first. Year key 0 ("Без година") is the lowest, so it
// lands last after krsort — correct position at the bottom of the list.
krsort( $grouped, SORT_NUMERIC );

// ── 2b. Within each year, order races by actual calendar date ───────────────
//
// $grouped[$year] is keyed by event name in whatever order the date-DESC
// post fetch happened to first encounter each event's posts — arbitrary
// relative to the actual race calendar (results-derived, not date-derived).
// The requested order is reverse-chronological within a season: the year's
// LAST race at the top, its FIRST race at the bottom. ts_result slugs for
// these events carry no day/month token to derive that from, so the real
// signal is the ajde_events calendar (evcal_srow) — same event_base/year
// match front-page.php's "Последни резултати" section already uses.
//
// Events with no calendar match (older seasons that predate the EventON
// calendar) have no date signal at all; they sort after every dated event
// in their year, keeping their prior relative order among themselves
// (usort is stable in PHP 8) rather than being scrambled.
if ( post_type_exists( 'ajde_events' ) ) {
	$tsr_event_dates = array(); // [ year => [ event_base => evcal_srow ] ]
	foreach ( get_posts( array(
		'post_type'      => 'ajde_events',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	) ) as $tsr_ev_post ) {
		$tsr_ev_title = html_entity_decode( $tsr_ev_post->post_title, ENT_QUOTES, 'UTF-8' );
		$tsr_ev_base  = tsr_event_base_name( $tsr_ev_title );
		if ( '' === $tsr_ev_base ) {
			continue;
		}
		$tsr_ev_ts   = (int) get_post_meta( $tsr_ev_post->ID, 'evcal_srow', true );
		$tsr_ev_year = tsr_title_year( $tsr_ev_title ) ?? (int) date_i18n( 'Y', $tsr_ev_ts );
		// A later calendar entry for the same event+year (edit, duplicate)
		// wins — last one processed simply overwrites, no ordering assumed.
		$tsr_event_dates[ $tsr_ev_year ][ $tsr_ev_base ] = $tsr_ev_ts;
	}

	foreach ( $grouped as $tsr_year => &$tsr_events_for_year ) {
		$tsr_dates_this_year = $tsr_event_dates[ $tsr_year ] ?? array();
		uksort(
			$tsr_events_for_year,
			static function ( string $tsr_a, string $tsr_b ) use ( $tsr_dates_this_year ): int {
				$tsr_ts_a = $tsr_dates_this_year[ $tsr_a ] ?? null;
				$tsr_ts_b = $tsr_dates_this_year[ $tsr_b ] ?? null;
				if ( null === $tsr_ts_a && null === $tsr_ts_b ) {
					return 0; // neither dated — keep relative order (stable sort).
				}
				if ( null === $tsr_ts_a ) {
					return 1; // undated sorts after dated.
				}
				if ( null === $tsr_ts_b ) {
					return -1;
				}
				return $tsr_ts_b <=> $tsr_ts_a; // descending: latest race first.
			}
		);
	}
	unset( $tsr_events_for_year );
}

// ── Selected season (one season per page — audit M17 redesign) ──────────────
//
// `?sezon=<year>` picks the season; anything that is not a season with
// results falls back to the latest one. Only that season is rendered, which
// keeps the page small (all 16 seasons used to be in the DOM at once).
$tsr_years = array_keys( $grouped ); // newest first, 0 ("Без година") last
// phpcs:ignore WordPress.Security.NonceVerification
$tsr_req      = ( isset( $_GET['sezon'] ) && is_string( $_GET['sezon'] ) && ctype_digit( $_GET['sezon'] ) ) ? (int) $_GET['sezon'] : null;
$tsr_selected = ( null !== $tsr_req && array_key_exists( $tsr_req, $grouped ) )
	? $tsr_req
	: ( $tsr_years[0] ?? 0 );
$tsr_events   = $grouped[ $tsr_selected ] ?? array();
$tsr_dates    = ( $tsr_event_dates ?? array() )[ $tsr_selected ] ?? array();
$tsr_rindex   = tsr_results_index();

/**
 * Distance rows of one race card: "21 км — Мъже · Жени". Sections with a
 * distance and a gender are grouped by distance; anything else (kids,
 * relays, sections without a distance) gets its own row under its label.
 * Input is already in ADR-004 order (longest first, men before women).
 *
 * @param list<WP_Post> $posts
 * @return list<array{label: string, links: list<array{text: string, url: string}>}>
 */
$tsr_dist_rows = static function ( array $posts ) use ( $tsr_rindex ): array {
	$rows = array();
	foreach ( $posts as $p ) {
		$entry  = $tsr_rindex[ $p->ID ] ?? array( 'km' => '', 'gender' => '' );
		$label  = TSR_Section_Meta::title_category_suffix( $p->post_title );
		$gender = in_array( $entry['gender'], array( 'M', 'F' ), true )
			? $entry['gender']
			: ( 'mixed' === $entry['gender'] ? '' : tsr_race_gender_from_text( $p ) );
		$url    = (string) get_permalink( $p );
		if ( '' !== $entry['km'] && '' !== $gender ) {
			$km_label = str_replace( '.', ',', $entry['km'] ) . ' км';
			$rows[ 'km:' . $entry['km'] ] ??= array( 'label' => $km_label, 'links' => array() );
			$rows[ 'km:' . $entry['km'] ]['links'][] = array( 'text' => 'M' === $gender ? 'Мъже' : 'Жени', 'url' => $url );
		} else {
			$rows[ 'p:' . $p->ID ] = array(
				'label' => '' !== $entry['km'] ? str_replace( '.', ',', $entry['km'] ) . ' км' : '',
				'links' => array( array( 'text' => '' !== $label ? $label : 'Резултати', 'url' => $url ) ),
			);
		}
	}
	return array_values( $rows );
};
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Резултати</h1>
		<p class="tsr-page-hero__subtitle">
			Всички резултати от сезоните — по година и дистанция
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Резултати' ); ?>

		<?php if ( empty( $grouped ) ) : ?>
			<div class="tsr-notice">
				<p>Все още няма публикувани резултати. Проверете отново след първото състезание.</p>
			</div>

		<?php else : ?>

			<?php
			$tsr_n = count( $tsr_events );
			tsr_season_select( $tsr_years, $tsr_selected, sprintf( '%d %s', $tsr_n, 1 === $tsr_n ? 'състезание' : 'състезания' ) );
			?>

			<h2 class="tsr-season-heading"><?php echo esc_html( tsr_season_label( $tsr_selected ) ); ?></h2>

			<div class="tsr-race-cards">

				<?php foreach ( $tsr_events as $event_name => $event_posts ) : ?>
					<?php
					// Primary post: the hub (no other post's slug + '-' prefixes it)
					// = the SEO-preserved legacy URL. tsr_hub_head_for() returns null
					// for hubs/standalone posts.
					$primary = null;
					$cats    = array();
					foreach ( $event_posts as $p ) {
						if ( null === $primary && null === tsr_hub_head_for( $p ) ) {
							$primary = $p;
						} else {
							$cats[] = $p;
						}
					}
					if ( null === $primary ) {
						$primary = array_shift( $cats );
					}
					$sections = tsr_sort_results_indexed( array_merge( array( $primary ), $cats ) );
					$tsr_base = $tsr_event_base_of[ $event_name ] ?? $event_name;
					$race_ts  = $tsr_dates[ $tsr_base ] ?? 0;
					$ev_slug  = sanitize_title( $tsr_base );
					?>
					<article class="tsr-race-card">
						<header class="tsr-race-card__head">
							<h3 class="tsr-race-card__title">
								<a href="<?php echo esc_url( (string) get_permalink( $primary ) ); ?>"><?php echo esc_html( $event_name ); ?></a>
							</h3>
							<?php if ( $race_ts > 0 ) : ?>
								<time class="tsr-race-card__date" datetime="<?php echo esc_attr( wp_date( 'Y-m-d', $race_ts ) ); ?>">
									<?php echo esc_html( tsr_bg_date( $race_ts ) ); ?>
								</time>
							<?php endif; ?>
						</header>

						<dl class="tsr-dist-rows">
							<?php foreach ( $tsr_dist_rows( $sections ) as $row ) : ?>
								<div class="tsr-dist-row">
									<dt class="tsr-dist-row__km"><?php echo esc_html( $row['label'] ); ?></dt>
									<dd class="tsr-dist-row__links">
										<?php foreach ( $row['links'] as $i => $link ) : ?>
											<?php if ( $i > 0 ) : ?><span class="tsr-dist-row__sep" aria-hidden="true">·</span><?php endif; ?>
											<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['text'] ); ?><span class="screen-reader-text"> — <?php echo esc_html( trim( $row['label'] . ' ' . $event_name ) ); ?></span></a>
										<?php endforeach; ?>
									</dd>
								</div>
							<?php endforeach; ?>
						</dl>

						<?php if ( '' !== $ev_slug ) : ?>
							<p class="tsr-race-card__more">
								<a href="<?php echo esc_url( add_query_arg( 'sabitie', $ev_slug, home_url( '/event/' ) ) ); ?>">
									История на събитието<span class="screen-reader-text">: <?php echo esc_html( $event_name ); ?></span> ›
								</a>
							</p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>

			</div><!-- .tsr-race-cards -->

		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
