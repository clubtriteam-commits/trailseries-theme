<?php
/**
 * Template Name: Класирания
 *
 * Template for the Класирания page (slug: klasiraniya).
 *
 * Season standings — accumulated points per athlete across all races in a
 * season, using the scoring rules from Правила.
 *
 * Points per race:
 *   short  (<8 km)      → top 5 score,  1st = 5  (6 - place)
 *   medium (8-13.9 km)  → top 10 score, 1st = 10 (11 - place)
 *   long   (14+ km)     → top 15 score, 1st = 15 (16 - place)
 *   BONUS races         → top 20 score, 1st = 20 (21 - place)
 *
 * Bonus is NOT a distance category — each season designates exactly two
 * specific races (event + distance) as bonus; see $tsr_bonus_races below.
 * Note the legacy `_tsr_distance_cat` meta value 'bonus' (backfill assigned
 * it to all 21+ km races) is treated as 'long' here unless the race is in
 * the season's designated bonus list. Verified against the live Season 13
 * (2025) standings: Vincent Sliman 88, Leo Sliman 82, Иван Венков 60.
 *
 * Distance category comes from `_tsr_distance_cat` post meta; the distance
 * itself from `_tsr_distance_km`. When meta is absent the race earns 0
 * points. Run `wp tsr backfill-meta` to populate them.
 *
 * @package exhibz-child
 */

declare( strict_types=1 );

get_header();

// ── Season selection ──────────────────────────────────────────────────────────

// Available seasons: derive from published ts_result posts grouped by year.
$season_years = array();
$all_ids      = get_posts(
	array(
		'post_type'   => 'ts_result',
		'numberposts' => -1,
		'post_status' => 'publish',
		'fields'      => 'ids',
	)
);
foreach ( $all_ids as $pid ) {
	$season_meta = get_post_meta( $pid, '_tsr_season', true );
	if ( '' === (string) $season_meta ) {
		continue;
	}
	$season_years[ (int) $season_meta ] = true;
}
krsort( $season_years );
$available_seasons = array_keys( $season_years );

$current_season = isset( $_GET['sezon'] ) ? (int) $_GET['sezon'] : ( $available_seasons[0] ?? (int) gmdate( 'Y' ) ); // phpcs:ignore WordPress.Security.NonceVerification

// ── Season display labels ─────────────────────────────────────────────────────
$season_labels = array(
	2012 => 'Сезон 1 (2012–2013)',
	2013 => 'Сезон 2 (2013–2014)',
	2014 => 'Сезон 3 (2014–2015)',
	2015 => 'Сезон 4 (2015–2016)',
	2016 => 'Сезон 5 (2016–2017)',
	2017 => 'Сезон 6 (2017–2018)',
	2018 => 'Сезон 7 (2018–2019)',
	2019 => 'Сезон 8 (2019–2020)',
	2020 => 'Сезон 8 (2019–2020)',
	2021 => 'Сезон 9 (2021)',
	2022 => 'Сезон 10 (2022)',
	2023 => 'Сезон 11 (2023)',
	2024 => 'Сезон 12 (2024)',
	2025 => 'Сезон 13 (2025)',
	2026 => 'Сезон 14 (2026)',
);
$current_season_label = $season_labels[ $current_season ] ?? "Сезон $current_season";

// ── Bonus race designation ────────────────────────────────────────────────────
//
// Exactly two races per season score as bonus (top 20, 21 - place). They are
// identified by legacy page slug + distance, because the designation changes
// per season and a distance category alone can't express it (e.g. in 2025
// The Cactus Run 21km is a regular long race while Buhovo HM 21km is bonus).
//
// 'slug' is the base post_name of the imported result posts (the part before
// any '--category' suffix); 'km' matches against the `_tsr_distance_km` meta.
// Seasons set in the 'tsr_bonus_races' option override these defaults.
$tsr_bonus_defaults = array(
	2025 => array(
		array( 'slug' => '7-hills-run25-results', 'km' => 26.0 ),          // 7 Hills Run 26km (Hardcore edition)
		array( 'slug' => 'buhovo-half-marathon25-results', 'km' => 21.0 ), // Buhovo Half Marathon'25
	),
);
$tsr_bonus_races = (array) get_option( 'tsr_bonus_races', array() ) + $tsr_bonus_defaults;

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

// ── Compute standings for the selected season ─────────────────────────────────

/**
 * @var array<string, array{points:int, finishes:int, name:string}> $standings
 */
$standings  = array();
$race_posts = get_posts(
	array(
		'post_type'   => 'ts_result',
		'numberposts' => -1,
		'post_status' => 'publish',
		'meta_query'  => array(
			array(
				'key'     => '_tsr_season',
				'value'   => (string) $current_season,
				'compare' => '=',
			),
		),
	)
);

$has_cat_meta = false; // Flip to true once we find any distance category meta.

$tsr_season_bonus = $tsr_bonus_races[ $current_season ] ?? array();

foreach ( $race_posts as $rpost ) {
	$cat = (string) ( get_post_meta( $rpost->ID, '_tsr_distance_cat', true ) ?: '' );
	if ( '' !== $cat ) {
		$has_cat_meta = true;
	}

	// Designated bonus race? Match base slug (before '--category') + distance.
	$tsr_base_slug = explode( '--', $rpost->post_name )[0];
	$tsr_km_meta   = (string) get_post_meta( $rpost->ID, '_tsr_distance_km', true );
	$is_bonus      = false;
	foreach ( $tsr_season_bonus as $tsr_br ) {
		if ( $tsr_br['slug'] === $tsr_base_slug
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
		// Only confirmed finishers score. DNF rows can legally carry a place number
		// (e.g. golyam-sechko-run25 15km-m DNF at place 39) so we filter by status,
		// never by place != null. TSR_Result_Row always serialises a status string.
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

		if ( ! isset( $standings[ $key ] ) ) {
			$standings[ $key ] = array(
				'name'     => $name,
				'points'   => 0,
				'finishes' => 0,
			);
		}
		$standings[ $key ]['points']   += $pts;
		$standings[ $key ]['finishes'] += 1;
	}
}

// Sort: points DESC, then finishes DESC.
uasort(
	$standings,
	static function ( array $a, array $b ): int {
		if ( $a['points'] !== $b['points'] ) {
			return $b['points'] <=> $a['points'];
		}
		return $b['finishes'] <=> $a['finishes'];
	}
);
$standings = array_values( $standings );
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Класирания</h1>
		<p class="tsr-page-hero__subtitle">
			Сезонно класиране по точки — мъже и жени заедно
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<!-- ─── Season picker ──────────────────────────────────────────────── -->
		<?php if ( count( $available_seasons ) > 1 ) : ?>
			<nav class="tsr-season-nav" aria-label="Избор на сезон">
				<?php foreach ( $available_seasons as $yr ) : ?>
					<a class="tsr-season-nav__item<?php echo $yr === $current_season ? ' tsr-season-nav__item--active' : ''; ?>"
					   href="<?php echo esc_url( add_query_arg( 'sezon', $yr ) ); ?>">
						<?php echo esc_html( $season_labels[ $yr ] ?? (string) $yr ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php if ( ! $has_cat_meta ) : ?>
			<!-- Admin notice: metadata missing — standings show 0 pts -->
			<div class="tsr-notice tsr-notice--info">
				<strong>Забележка:</strong> На резултатите от сезон
				<?php echo esc_html( $current_season ); ?> липсва метаданни за
				категорията дистанция (<code>_tsr_distance_cat</code>).
				Класирането показва само броя финиши. Добавете метаданни чрез
				<code>wp tsr bulk-import</code>, за да се изчислят точките.
			</div>
		<?php endif; ?>

		<!-- ─── Standings table ────────────────────────────────────────────── -->
		<?php if ( ! empty( $standings ) ) : ?>
			<section class="tsr-prose-section">
				<h2><?php echo esc_html( $current_season_label ); ?></h2>
				<div class="tsr-results-wrap">
					<table class="tsr-results">
						<thead>
							<tr>
								<th>#</th>
								<th>Атлет</th>
								<th>Точки</th>
								<th>Финиши</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $standings as $rank => $athlete ) : ?>
								<tr<?php echo 0 === $rank ? ' class="tsr-rank-first"' : ''; ?>>
									<td><?php echo esc_html( $rank + 1 ); ?></td>
									<td><strong><?php echo esc_html( $athlete['name'] ); ?></strong></td>
									<td><?php echo esc_html( $athlete['points'] ); ?></td>
									<td><?php echo esc_html( $athlete['finishes'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php else : ?>
			<section class="tsr-prose-section">
				<p class="tsr-empty">
					Няма резултати за <?php echo esc_html( $current_season_label ); ?>.
				</p>
			</section>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link" href="<?php echo esc_url( home_url( '/pravila/' ) ); ?>">
				Виж пълните правила за класиране
			</a>
		</p>

	</div>
</main>

<?php get_footer(); ?>
