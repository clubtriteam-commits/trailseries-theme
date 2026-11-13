<?php
declare( strict_types=1 );
/**
 * Template Name: Ранкинг
 *
 * Template for the Ранкинг page (slug: klasiraniya — unchanged, legacy URL
 * per ADR-003; only the displayed name changed from "Класирания").
 *
 * Season standings split into Мъже / Жени columns, accumulated points per
 * athlete across all races in a season using the scoring rules from Правила.
 *
 * Points per race:
 *   short  (<8 km)      -> top 5 score,  1st = 5  (6 - place)
 *   medium (8-13.9 km)  -> top 10 score, 1st = 10 (11 - place)
 *   long   (14+ km)     -> top 15 score, 1st = 15 (16 - place)
 *   BONUS races         -> top 20 score, 1st = 20 (21 - place)
 *
 * Bonus is NOT a distance category -- each season designates exactly two
 * specific races (event + distance) as bonus; see $tsr_bonus_races below.
 * Note the legacy `_tsr_distance_cat` meta value 'bonus' (backfill assigned
 * it to all 21+ km races) is treated as 'long' here unless the race is in
 * the season's designated bonus list. Verified against the live Season 13
 * (2025) standings: Vincent Sliman 88, Leo Sliman 82, Ivan Venkov 60.
 *
 * Tiebreaker: equal points -> podium count (top-3 finishes) DESC.
 *
 * Dedup: posts within the season sharing the same `_tsr_names_sha256`
 * (byte-identical source tables published under two legacy URLs) score
 * once; identical groups with conflicting gender markers are skipped.
 *
 * Distance category comes from `_tsr_distance_cat` post meta; the distance
 * itself from `_tsr_distance_km`. When meta is absent the race earns 0
 * points. Run `wp tsr backfill-meta` to populate them.
 *
 * @package exhibz-child
 */

get_header();

// -- Season selection ---------------------------------------------------------

// Seasons from the small cached index (audit H3): the previous per-post
// get_post_meta() loop loaded every post's meta, results blob included —
// ~980 queries per view even when the standings themselves were cached.
$season_years = array();
foreach ( tsr_results_index() as $tsr_entry ) {
	// Series season (audit H1): 2012–2020 races count for the October-to-
	// October season they belong to, not their calendar year.
	if ( '' === $tsr_entry['series'] ) {
		continue;
	}
	$season_years[ (int) $tsr_entry['series'] ] = true;
}
// Seasons with an official old-site table are offered even if none of their
// results were imported (audit H1 step 2).
foreach ( array_keys( TSR_Official_Standings::seasons() ) as $tsr_os ) {
	$season_years[ (int) $tsr_os ] = true;
}
krsort( $season_years );
$available_seasons = array_keys( $season_years );

$current_season = isset( $_GET['sezon'] ) ? (int) $_GET['sezon'] : ( $available_seasons[0] ?? (int) gmdate( 'Y' ) ); // phpcs:ignore WordPress.Security.NonceVerification

$current_season_label = tsr_season_label( $current_season );

// -- Bonus race designation ---------------------------------------------------
//
// Exactly two races per season score as bonus (top 20, 21 - place). They are
// identified by legacy page slug + distance, because the designation changes
// per season and a distance category alone cannot express it (e.g. in 2025
// The Cactus Run 21km is a regular long race while Buhovo HM 21km is bonus).
//
// 'slug' is the legacy page slug — the bare post_name of the page's first
// imported section; sibling sections extend it as "{slug}-{cat}" (single
// dash: sanitize_title collapses the importer's "--"). 'km' matches against
// the `_tsr_distance_km` meta, so BOTH gender categories of the designated
// distance score as bonus. Seasons set in the 'tsr_bonus_races' option
// override these defaults.
$tsr_bonus_defaults = array(
	2025 => array(
		array( 'slug' => '7-hills-run25-results', 'km' => 26.0 ),          // 7 Hills Run 26km (Hardcore edition)
		array( 'slug' => 'buhovo-half-marathon25-results', 'km' => 21.0 ), // Buhovo Half Marathon'25
	),
);
$tsr_bonus_races = (array) get_option( 'tsr_bonus_races', array() ) + $tsr_bonus_defaults;

// tsr_race_gender() comes from the trailseries-results plugin
// (includes/event-heuristics.php) — page-runner.php's duplicate-post
// dedup needs the same detector, so it is no longer private to this
// template.

/**
 * Return points earned for a given place, distance category and bonus flag.
 *
 * @param int    $place    Place number (1-based).
 * @param string $cat      Distance category: short|medium|long (a legacy
 *                         'bonus' value means 21+ km and scores as long).
 * @param bool   $is_bonus True when the race is one of the season's two
 *                         designated bonus races.
 * @return int Points, or 0 if outside the scoring window.
 */
function tsr_points( int $place, string $cat, bool $is_bonus = false ): int {
	if ( $is_bonus ) {
		$max = 20;
	} else {
		$max = match ( $cat ) {
			'short'         => 5,
			'medium'        => 10,
			'long', 'bonus' => 15, // legacy 'bonus' cat = 21+ km distance, not a designated bonus race
			default         => 0,
		};
	}
	if ( 0 === $max || $place < 1 || $place > $max ) {
		return 0;
	}
	return $max + 1 - $place;
}

// -- Compute standings for the selected season ---------------------------------
//
// Results-derived transient: recomputing means a meta query plus a JSON
// decode of every season post per page view. The key embeds the cache
// generation (tsr_cache_gen(), functions.php), so any results write —
// import, admin edit, post deletion — makes a fresh key and the stale
// entry expires on its own TTL. On a cache hit the compute path below
// runs over an empty post list and the cached standings are restored
// after the (no-op) sort.
$tsr_standings_key  = sprintf( 'tsr_standings_g%d_s%d', tsr_cache_gen(), $current_season );
// Audit H5: only a season that has results may compute or cache anything.
// `?sezon=<any other int>` used to write a fresh transient per value; it
// now renders the same empty standings straight away.
$tsr_standings_hit  = in_array( $current_season, $available_seasons, true )
	? get_transient( $tsr_standings_key )
	: array( 'standings' => array( 'm' => array(), 'f' => array() ), 'has_cat_meta' => false );
// Audit H1 step 2 / Q2: a past season with an official table shows it as
// the old site published it — no calculation, no transient.
$tsr_official      = in_array( $current_season, $available_seasons, true ) ? TSR_Official_Standings::get( $current_season ) : null;
$tsr_official_full = null !== $tsr_official && 'full' === $tsr_official['kind'];
if ( $tsr_official_full ) {
	$tsr_standings_hit = array(
		'standings'    => array( 'm' => $tsr_official['m'], 'f' => $tsr_official['f'] ),
		'has_cat_meta' => true,
	);
}
$tsr_need_compute   = ! is_array( $tsr_standings_hit );

/**
 * @var array<'m'|'f', array<string, array{points:int, finishes:int, podiums:int, name:string}>> $standings
 */
$standings = array( 'm' => array(), 'f' => array() );

$tsr_season_ids = array_keys( array_filter(
	tsr_results_index(),
	static fn( array $e ): bool => (string) $current_season === $e['series']
) );
$race_posts = ( ! $tsr_need_compute || array() === $tsr_season_ids ) ? array() : get_posts(
	array(
		'post_type'   => 'ts_result',
		'numberposts' => -1,
		'post_status' => 'publish',
		'post__in'    => $tsr_season_ids,
	)
);

$has_cat_meta = false;

$tsr_season_bonus = $tsr_bonus_races[ $current_season ] ?? array();

// -- Names-hash dedup -----------------------------------------------------------
//
// A handful of legacy source pages published byte-identical result tables
// under two URLs (twin legacy pages, repeated sections). Those imported as
// distinct posts sharing the same `_tsr_names_sha256`, and counting both
// would double every athlete's points. Within the season, byte-identical
// posts score exactly once. When an identical group carries CONFLICTING
// gender markers (one slug says мъже, another жени) the data's true gender
// is unknowable — the whole group is skipped rather than guessed, since a
// wrong guess corrupts one column and a double count corrupts both.
$tsr_hash_groups = array();
foreach ( $race_posts as $rpost ) {
	$tsr_hash = (string) get_post_meta( $rpost->ID, '_tsr_names_sha256', true );
	if ( '' !== $tsr_hash ) {
		$tsr_hash_groups[ $tsr_hash ][] = $rpost;
	}
}
$tsr_dedup_skip = array(); // post ID => true when this post must not score.
foreach ( $tsr_hash_groups as $tsr_group ) {
	if ( count( $tsr_group ) < 2 ) {
		continue;
	}
	$tsr_group_genders = array();
	foreach ( $tsr_group as $tsr_gp ) {
		$tsr_g = tsr_race_gender( $tsr_gp );
		if ( '' !== $tsr_g ) {
			$tsr_group_genders[ $tsr_g ] = true;
		}
	}
	if ( count( $tsr_group_genders ) > 1 ) {
		foreach ( $tsr_group as $tsr_gp ) {
			$tsr_dedup_skip[ $tsr_gp->ID ] = true;
		}
		continue;
	}
	// Same data, same (or undetermined) gender: keep one representative.
	// Prefer a post that can actually score correctly — detectable gender
	// first, then populated distance-category meta — and break remaining
	// ties on lowest ID so the choice is stable across page loads.
	$tsr_rep = null;
	foreach ( $tsr_group as $tsr_gp ) {
		if ( null === $tsr_rep ) {
			$tsr_rep = $tsr_gp;
			continue;
		}
		$tsr_rank = static function ( WP_Post $p ): array {
			return array(
				'' !== tsr_race_gender( $p ) ? 0 : 1,
				'' !== (string) get_post_meta( $p->ID, '_tsr_distance_cat', true ) ? 0 : 1,
				$p->ID,
			);
		};
		if ( $tsr_rank( $tsr_gp ) < $tsr_rank( $tsr_rep ) ) {
			$tsr_rep = $tsr_gp;
		}
	}
	foreach ( $tsr_group as $tsr_gp ) {
		if ( $tsr_gp->ID !== $tsr_rep->ID ) {
			$tsr_dedup_skip[ $tsr_gp->ID ] = true;
		}
	}
}

foreach ( $race_posts as $rpost ) {
	if ( isset( $tsr_dedup_skip[ $rpost->ID ] ) ) {
		continue; // byte-identical duplicate of a post that already scores (or gender-conflicted group).
	}

	$gender = tsr_race_gender( $rpost );
	if ( '' === $gender ) {
		continue; // skip categories with undetermined gender (kids, mixed, etc.)
	}
	$gender_key = 'M' === $gender ? 'm' : 'f';

	$cat = (string) ( get_post_meta( $rpost->ID, '_tsr_distance_cat', true ) ?: '' );
	if ( '' !== $cat ) {
		$has_cat_meta = true;
	}

	// Designated bonus race? A post belongs to the designated legacy page when
	// its slug IS the page slug or extends it ("{page_slug}-{cat}" — note
	// sanitize_title collapses the importer's "--" separator to one dash), and
	// its distance matches. Prefix matching is collision-free: no legacy page
	// slug is a dash-prefix of another page's slug (manifest-verified).
	$tsr_km_meta = (string) get_post_meta( $rpost->ID, '_tsr_distance_km', true );
	$is_bonus    = false;
	foreach ( $tsr_season_bonus as $tsr_br ) {
		$tsr_slug_match = $rpost->post_name === $tsr_br['slug']
			|| str_starts_with( $rpost->post_name, $tsr_br['slug'] . '-' );
		if ( $tsr_slug_match
			&& '' !== $tsr_km_meta
			&& abs( (float) $tsr_km_meta - (float) $tsr_br['km'] ) < 0.25 ) {
			$is_bonus = true;
			break;
		}
	}

	$json_raw = get_post_meta( $rpost->ID, '_tsr_result_set', true );
	if ( ! is_string( $json_raw ) || '' === $json_raw ) {
		continue;
	}
	$data = json_decode( $json_raw, true );
	if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
		continue;
	}

	foreach ( $data['rows'] as $row ) {
		// Only confirmed finishers score. DNF rows can legally carry a place
		// number so we filter by status, never by place != null.
		if ( ( $row['status'] ?? '' ) !== 'FIN' ) {
			continue;
		}

		$place = is_int( $row['place'] ?? null ) ? $row['place'] : 0;
		if ( $place < 1 ) {
			continue;
		}

		$pts  = ( '' !== $cat || $is_bonus ) ? tsr_points( $place, $cat, $is_bonus ) : 0;
		$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		$key  = mb_strtolower( $name );

		if ( ! isset( $standings[ $gender_key ][ $key ] ) ) {
			$standings[ $gender_key ][ $key ] = array(
				'name'     => $name,
				'points'   => 0,
				'finishes' => 0,
				'podiums'  => 0,
			);
		}
		$standings[ $gender_key ][ $key ]['points']   += $pts;
		$standings[ $gender_key ][ $key ]['finishes'] += 1;
		if ( $place <= 3 ) {
			$standings[ $gender_key ][ $key ]['podiums'] += 1;
		}
	}
}

// Sort: points DESC, podiums DESC, finishes DESC.
$tsr_sort_fn = static function ( array $a, array $b ): int {
	if ( $a['points'] !== $b['points'] ) {
		return $b['points'] <=> $a['points'];
	}
	if ( $a['podiums'] !== $b['podiums'] ) {
		return $b['podiums'] <=> $a['podiums'];
	}
	return $b['finishes'] <=> $a['finishes'];
};
uasort( $standings['m'], $tsr_sort_fn );
uasort( $standings['f'], $tsr_sort_fn );
$standings['m'] = array_values( $standings['m'] );
$standings['f'] = array_values( $standings['f'] );

if ( $tsr_need_compute ) {
	set_transient(
		$tsr_standings_key,
		array(
			'standings'    => $standings,
			'has_cat_meta' => $has_cat_meta,
		),
		12 * HOUR_IN_SECONDS
	);
} else {
	$standings    = $tsr_standings_hit['standings'];
	$has_cat_meta = (bool) $tsr_standings_hit['has_cat_meta'];
}

// -- Presentation (audit M17 redesign) ---------------------------------------
//
// Season dropdown, Мъже/Жени tabs, top-3 podium and a table up to #50 per
// gender; "Покажи всички" (`&vsichki=1`) renders the full list server-side,
// so the default page stays small (both full lists used to be in the DOM).
const TSR_STANDINGS_TOP = 50;
// phpcs:disable WordPress.Security.NonceVerification
$tsr_show_all = isset( $_GET['vsichki'] );
$tsr_open_tab = ( isset( $_GET['pol'] ) && 'f' === $_GET['pol'] ) ? 'f' : 'm';
// phpcs:enable WordPress.Security.NonceVerification

$tsr_race_count = count( array_unique( array_filter( array_map(
	static fn( array $e ): string => (string) $current_season === $e['series'] ? $e['event_base'] : '',
	tsr_results_index()
) ) ) );

/**
 * One gender panel: podium (top 3) + table (#4 … #50, or all).
 *
 * @param array<int, array{points:int, finishes:int, podiums:int, name:string}> $rows Sorted.
 */
function tsr_render_standings_panel( array $rows, string $key, string $heading, bool $open, int $season, bool $show_all, ?callable $link_ok = null ): void {
	$runner_url  = static fn( string $name ): string => add_query_arg( 'runner', rawurlencode( $name ), home_url( '/runner/' ) );
	$link_ok     = $link_ok ?? static fn( string $name ): bool => true;
	// Official tables carry their published place and may lack podiums.
	$place       = static fn( array $a, int $i ): int => (int) ( $a['rank'] ?? ( $i + 1 ) );
	$has_podiums = array() !== array_filter( $rows, static fn( array $a ): bool => isset( $a['podiums'] ) );
	$name_html   = static function ( array $a, string $class = '' ) use ( $runner_url, $link_ok ): string {
		$cls = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';
		return $link_ok( $a['name'] )
			? '<a' . $cls . ' href="' . esc_url( $runner_url( $a['name'] ) ) . '">' . esc_html( $a['name'] ) . '</a>'
			: '<span' . $cls . '>' . esc_html( $a['name'] ) . '</span>';
	};
	$total      = count( $rows );
	$limit      = $show_all ? $total : min( $total, TSR_STANDINGS_TOP );
	?>
	<section class="tsr-standings-panel" id="tsr-panel-<?php echo esc_attr( $key ); ?>" role="tabpanel" aria-labelledby="tsr-tab-<?php echo esc_attr( $key ); ?>"<?php echo $open ? '' : ''; ?>>
		<h3 class="tsr-standings-panel__heading"><?php echo esc_html( $heading ); ?></h3>

		<?php if ( 0 === $total ) : ?>
			<p class="tsr-empty">Няма класирани.</p>
		<?php else : ?>

			<ol class="tsr-podium">
				<?php foreach ( array_slice( $rows, 0, 3 ) as $i => $a ) : ?>
					<li class="tsr-podium__item tsr-podium__item--<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
						<span class="tsr-podium__place" aria-hidden="true"><?php echo esc_html( (string) $place( $a, $i ) ); ?></span>
						<?php echo $name_html( $a, 'tsr-podium__name' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $name_html. ?>
						<span class="tsr-podium__pts"><?php echo esc_html( (string) $a['points'] ); ?> т.<?php if ( isset( $a['podiums'] ) ) : ?> <span class="tsr-podium__meta">· <?php echo esc_html( (string) $a['podiums'] ); ?> <?php echo 1 === (int) $a['podiums'] ? 'подиум' : 'подиума'; ?></span><?php endif; ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ( $limit > 3 ) : ?>
				<div class="tsr-results-wrap">
					<table class="tsr-results tsr-standings-table">
						<caption class="screen-reader-text"><?php echo esc_html( $heading ); ?> — от <?php echo esc_html( (string) $place( $rows[3], 3 ) ); ?> до <?php echo esc_html( (string) $place( $rows[ $limit - 1 ], $limit - 1 ) ); ?> място</caption>
						<thead>
							<tr>
								<th scope="col">#</th>
								<th scope="col">Атлет</th>
								<th scope="col">Точки</th>
								<?php if ( $has_podiums ) : ?>
									<th scope="col">Подиуми</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php for ( $i = 3; $i < $limit; $i++ ) : $a = $rows[ $i ]; ?>
								<tr>
									<td><?php echo esc_html( (string) $place( $a, $i ) ); ?></td>
									<td><?php echo $name_html( $a ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $name_html. ?></td>
									<td><?php echo esc_html( (string) $a['points'] ); ?></td>
									<?php if ( $has_podiums ) : ?>
										<td><?php echo esc_html( (string) ( $a['podiums'] ?? '' ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $total > TSR_STANDINGS_TOP ) : ?>
				<p class="tsr-standings-more">
					<?php if ( $show_all ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'sezon' => $season, 'pol' => $key ), get_permalink() ) . '#tsr-panel-' . $key ); ?>">Покажи само първите <?php echo esc_html( (string) TSR_STANDINGS_TOP ); ?></a>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'sezon' => $season, 'pol' => $key, 'vsichki' => 1 ), get_permalink() ) . '#tsr-panel-' . $key ); ?>">Покажи всички <?php echo esc_html( (string) $total ); ?><span class="screen-reader-text"> — <?php echo esc_html( $heading ); ?></span></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

		<?php endif; ?>
	</section>
	<?php
}
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Ранкинг</h1>
		<p class="tsr-page-hero__subtitle">
			Сезонен ранкинг по точки
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Ранкинг' ); ?>

		<?php
		if ( count( $available_seasons ) > 0 ) {
			tsr_season_select(
				$available_seasons,
				$current_season,
				$tsr_race_count > 0 ? sprintf( '%d %s', $tsr_race_count, 1 === $tsr_race_count ? 'състезание' : 'състезания' ) : ''
			);
		}
		?>

		<h2 class="tsr-season-heading"><?php echo esc_html( $current_season_label ); ?></h2>

		<?php if ( ! empty( $standings['m'] ) || ! empty( $standings['f'] ) ) : ?>

			<?php if ( ! $has_cat_meta ) : ?>
				<div class="tsr-notice tsr-notice--info">
					<p>Класирането за този сезон показва само броя финиши — липсват данни за дистанциите.</p>
				</div>
			<?php endif; ?>

			<div class="tsr-standings" data-tsr-tabs>
				<div class="tsr-tabs" role="tablist" aria-label="Класиране по пол" hidden>
					<?php foreach ( array( 'm' => 'Мъже', 'f' => 'Жени' ) as $tsr_k => $tsr_h ) : ?>
						<button class="tsr-tabs__tab" type="button" role="tab" id="tsr-tab-<?php echo esc_attr( $tsr_k ); ?>"
							aria-controls="tsr-panel-<?php echo esc_attr( $tsr_k ); ?>"
							aria-selected="<?php echo $tsr_k === $tsr_open_tab ? 'true' : 'false'; ?>">
							<?php echo esc_html( $tsr_h ); ?>
							<span class="tsr-tabs__count"><?php echo esc_html( (string) count( $standings[ $tsr_k ] ) ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<?php
				// Official names come from the old standings page, not from the
				// results — link only those a runner profile exists for.
				$tsr_link_ok = null;
				if ( $tsr_official_full ) {
					$tsr_known = array();
					foreach ( tsr_runner_name_index() as $tsr_blob ) {
						foreach ( explode( "\n", $tsr_blob ) as $tsr_nm ) {
							$tsr_known[ (string) preg_replace( '/\s+/u', ' ', trim( $tsr_nm ) ) ] = true;
						}
					}
					$tsr_link_ok = static fn( string $n ): bool => isset( $tsr_known[ mb_strtolower( (string) preg_replace( '/\s+/u', ' ', trim( $n ) ) ) ] );
				}
				tsr_render_standings_panel( $standings['m'], 'm', 'Мъже', 'm' === $tsr_open_tab, $current_season, $tsr_show_all, $tsr_link_ok );
				tsr_render_standings_panel( $standings['f'], 'f', 'Жени', 'f' === $tsr_open_tab, $current_season, $tsr_show_all, $tsr_link_ok );
				?>
			</div>

			<?php if ( $tsr_official_full ) : ?>
				<p class="tsr-standings-note">
					Официалното генерално класиране за сезона, както е публикувано от Trail Series.
					<?php if ( 2012 === $current_season ) : ?>
						Местата при мъжете са по реда в публикуваната таблица (в нея колоната „място“ повтаря точките).
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p class="tsr-standings-note">
					При равен брой точки предимство има броят подиуми.
					<a href="<?php echo esc_url( home_url( '/pravila/' ) ); ?>">Пълните правила за класиране</a>
				</p>
			<?php endif; ?>

		<?php else : ?>
			<p class="tsr-empty">Няма класиране за <?php echo esc_html( $current_season_label ); ?>.</p>
		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
