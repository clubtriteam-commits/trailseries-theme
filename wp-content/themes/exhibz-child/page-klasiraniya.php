<?php
/**
 * Template Name: Класирания
 *
 * Template for the Класирания page (slug: klasiraniya).
 *
 * Season standings — accumulated points per athlete across all races in a
 * season, using the scoring rules from Правила.
 *
 * Points formula per race depends on the distance category stored in the
 * `_tsr_distance_cat` post meta (values: 'short'|'medium'|'long'|'bonus').
 * When that meta is absent the race earns 0 points. Run `wp tsr backfill-meta`
 * to populate `_tsr_distance_cat` and `_tsr_season` on all ts_result posts.
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

// ── Max points map ────────────────────────────────────────────────────────────
$points_map = array(
	'short'  => 5,
	'medium' => 10,
	'long'   => 15,
	'bonus'  => 20,
);

/**
 * Return points earned for a given place and distance category.
 *
 * @param int    $place Place number (1-based).
 * @param string $cat   Distance category: short|medium|long|bonus.
 * @return int Points, or 0 if outside the scoring window.
 */
function tsr_points( int $place, string $cat ): int {
	global $points_map;
	$max = $points_map[ $cat ] ?? 0;
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

foreach ( $race_posts as $rpost ) {
	$cat = (string) ( get_post_meta( $rpost->ID, '_tsr_distance_cat', true ) ?: '' );
	if ( '' !== $cat ) {
		$has_cat_meta = true;
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

		$pts  = '' !== $cat ? tsr_points( $place, $cat ) : 0;
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
						<?php echo esc_html( $yr ); ?>
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
				<h2>Сезон <?php echo esc_html( $current_season ); ?></h2>
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
					Няма резултати за сезон <?php echo esc_html( $current_season ); ?>.
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
