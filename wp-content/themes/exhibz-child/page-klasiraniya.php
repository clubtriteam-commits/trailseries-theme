<?php
declare( strict_types=1 );
/**
 * Template Name: Класирания
 *
 * Template for the Класирания page (slug: klasiraniya).
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
 * Distance category comes from `_tsr_distance_cat` post meta; the distance
 * itself from `_tsr_distance_km`. When meta is absent the race earns 0
 * points. Run `wp tsr backfill-meta` to populate them.
 *
 * @package exhibz-child
 */

get_header();

// -- Season selection ---------------------------------------------------------

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

// -- Season display labels ----------------------------------------------------
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

/**
 * Detect race gender from post_name slug or post_title category suffix.
 *
 * Detection order:
 *   1. Latin -m / -f at the very end of the slug ("19km-m", "6km-f").
 *   2. Cyrillic мъже / жени anywhere in the slug (Unicode-slug sites).
 *   3. Cyrillic МЪЖЕ / ЖЕНИ anywhere in the post title (always reliable,
 *      including bare-slug first sections whose slug has no gender marker).
 *
 * @return string 'M', 'F', or '' when undetermined.
 */
function tsr_race_gender( WP_Post $post ): string {
	if ( preg_match( '/-m$/i', $post->post_name ) ) {
		return 'M';
	}
	if ( preg_match( '/-f$/i', $post->post_name ) ) {
		return 'F';
	}
	// Cyrillic slug parts are stored URL-encoded in post_name — decode first.
	$slug = mb_strtolower( urldecode( $post->post_name ), 'UTF-8' );
	if ( str_contains( $slug, 'мъже' ) ) {
		return 'M';
	}
	if ( str_contains( $slug, 'жени' ) ) {
		return 'F';
	}
	$title = mb_strtoupper( $post->post_title, 'UTF-8' );
	if ( str_contains( $title, 'МЪЖЕ' ) ) {
		return 'M';
	}
	if ( str_contains( $title, 'ЖЕНИ' ) ) {
		return 'F';
	}
	return '';
}

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

/**
 * @var array<'m'|'f', array<string, array{points:int, finishes:int, podiums:int, name:string}>> $standings
 */
$standings = array( 'm' => array(), 'f' => array() );

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

$has_cat_meta = false;

$tsr_season_bonus = $tsr_bonus_races[ $current_season ] ?? array();

foreach ( $race_posts as $rpost ) {
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

/**
 * Render one gender column of the standings table.
 *
 * @param array  $rows    Sorted athlete rows.
 * @param string $heading Column heading (e.g. "Мъже").
 */
function tsr_render_standings_col( array $rows, string $heading ): void {
	if ( empty( $rows ) ) {
		return;
	}
	?>
	<div class="tsr-standings-col">
		<h3 class="tsr-standings-col__heading">
			<?php echo esc_html( $heading ); ?>
		</h3>
		<div class="tsr-results-wrap">
			<table class="tsr-results">
				<thead>
					<tr>
						<th>#</th>
						<th>Атлет</th>
						<th>Точки</th>
						<th>Подиуми</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $tsr_rank => $tsr_athlete ) : ?>
						<tr<?php echo 0 === $tsr_rank ? ' class="tsr-rank-first"' : ''; ?>>
							<td><?php echo esc_html( $tsr_rank + 1 ); ?></td>
							<td><strong><?php echo esc_html( $tsr_athlete['name'] ); ?></strong></td>
							<td><?php echo esc_html( $tsr_athlete['points'] ); ?></td>
							<td><?php echo esc_html( $tsr_athlete['podiums'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Класирания</h1>
		<p class="tsr-page-hero__subtitle">
			Сезонно класиране по точки
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<!-- Season picker -->
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
			<div class="tsr-notice tsr-notice--info">
				<strong>Забележка:</strong> На резултатите от сезон
				<?php echo esc_html( $current_season ); ?> липсва метаданни за
				категорията дистанция (<code>_tsr_distance_cat</code>).
				Класирането показва само броя финиши. Добавете метаданни чрез
				<code>wp tsr bulk-import</code>, за да се изчислят точките.
			</div>
		<?php endif; ?>

		<!-- Standings -->
		<?php if ( ! empty( $standings['m'] ) || ! empty( $standings['f'] ) ) : ?>
			<section class="tsr-prose-section">
				<h2><?php echo esc_html( $current_season_label ); ?></h2>
				<p class="tsr-standings-note">
					За класиране при равен брой точки предимство има броят подиуми.
				</p>
				<div class="tsr-standings-cols">
					<?php tsr_render_standings_col( $standings['m'], 'Мъже' ); ?>
					<?php tsr_render_standings_col( $standings['f'], 'Жени' ); ?>
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