<?php
declare( strict_types=1 );
/**
 * Template Name: Трасета
 *
 * Template for the Трасета page (slug: traseta). Design A (owner-approved
 * 2026-11-19): one card per race in calendar order — the race's calendar photo,
 * month and place on top, then one row per distance (category colour, GPX
 * distance, D+, highest point, difficulty, a small elevation profile). Races no
 * longer on the calendar follow under "Други трасета". Filter chips show one
 * distance category at a time (js/traseta-filter.js).
 *
 * Track data: data/tracks.json (migration/build_tracks_theme_data.py), with the
 * admin status override from Tools → Трасета — етикети (tsr_track_status()).
 * Clicking a row opens the detail modal (js/traseta-modal.js): map, profile,
 * GPX / KML / Strava.
 *
 * Category colours: short green, medium blue, long red; the 7 Hills Run
 * Hard Core 26 km is dark red (owner's choice — TSR_TRACK_XL_SLUGS).
 *
 * @package exhibz-child
 */

/** Tracks shown in dark red (the extra-long category colour). */
if ( ! defined( 'TSR_TRACK_XL_SLUGS' ) ) {
	define( 'TSR_TRACK_XL_SLUGS', array( '7-hills-run-hard-core-edition-26km' ) );
}

if ( ! function_exists( 'tsr_star_rating' ) ) {
	/**
	 * 1-5 star difficulty rating.
	 *
	 * @param int|null $stars Star count 1-5, or null when unknown.
	 * @return string HTML markup, '' when unknown.
	 */
	function tsr_star_rating( ?int $stars ): string {
		if ( null === $stars || $stars < 1 ) {
			return '';
		}
		$labels = array( 1 => 'лесно', 2 => 'умерено', 3 => 'средно', 4 => 'трудно', 5 => 'много трудно' );
		$stars  = min( $stars, 5 );
		$html   = '<span class="tsr-stars" role="img" aria-label="'
			. esc_attr( sprintf( 'Трудност: %d от 5 (%s)', $stars, $labels[ $stars ] ) ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$html .= '<span class="tsr-star' . ( $i <= $stars ? ' tsr-star--on' : '' ) . '" aria-hidden="true">&#9733;</span>';
		}
		return $html . '</span>';
	}
}

if ( ! function_exists( 'tsr_track_icon_defs' ) ) {
	/**
	 * Icon <symbol>s, rendered once per page. The modal's stat cells and
	 * buttons (js/traseta-modal.js) reference them via <use>.
	 */
	function tsr_track_icon_defs(): void {
		?>
		<svg width="0" height="0" style="position:absolute" aria-hidden="true">
			<symbol id="tsr-i-distance" viewBox="0 0 24 24"><circle cx="4" cy="12" r="2"/><circle cx="20" cy="12" r="2"/><path d="M6 12h12" stroke-dasharray="3 3"/></symbol>
			<symbol id="tsr-i-ascent" viewBox="0 0 24 24"><path d="M4 20 20 4M20 4H10M20 4v10"/></symbol>
			<symbol id="tsr-i-descent" viewBox="0 0 24 24"><path d="M4 4 20 20M20 20H10M20 20V10"/></symbol>
			<symbol id="tsr-i-elevation" viewBox="0 0 24 24"><path d="M3 20 9 8l4 6 2-3 6 9H3z"/></symbol>
			<symbol id="tsr-i-download" viewBox="0 0 24 24"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></symbol>
			<?php // Official Strava mark (Simple Icons, CC0) — brand guidelines ask for the real glyph when linking to Strava. ?>
			<?php // Garmin delta (triangle) mark; link glyph for "Копирай линк". ?>
			<symbol id="tsr-i-garmin" viewBox="0 0 24 24"><path d="M12 3 2.5 20h19L12 3zm0 5.2 5.6 9.8H6.4L12 8.2z"/></symbol>
			<symbol id="tsr-i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></symbol>
			<symbol id="tsr-i-strava" viewBox="0 0 24 24"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/></symbol>
		</svg>
		<?php
	}
}

if ( ! function_exists( 'tsr_track_nominal_km' ) ) {
	/** Nominal distance from the track name ("Middle 9 км" → 9.0), else the GPX distance. */
	function tsr_track_nominal_km( array $tr ): ?float {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*км/u', (string) ( $tr['variant'] ?? '' ), $m ) ) {
			return (float) str_replace( ',', '.', $m[1] );
		}
		return empty( $tr['distance_km'] ) ? null : (float) $tr['distance_km'];
	}
}

if ( ! function_exists( 'tsr_track_cat' ) ) {
	/**
	 * Distance category, same limits as /pravila/: under 8 km short,
	 * 8–13.9 km medium, 14 km and more long.
	 *
	 * @return string 'short'|'medium'|'long'|''
	 */
	function tsr_track_cat( ?float $km ): string {
		if ( null === $km ) {
			return '';
		}
		return $km < 8 ? 'short' : ( $km < 14 ? 'medium' : 'long' );
	}
}

if ( ! function_exists( 'tsr_track_profiles' ) ) {
	/**
	 * Elevation samples (48 per track, evenly spaced by distance) for the row
	 * sparklines, keyed by points file. Cached until tracks.json changes.
	 *
	 * @return array<string, list<int>>
	 */
	function tsr_track_profiles( array $events ): array {
		$dir = get_stylesheet_directory() . '/data/';
		$key = 'tsr_track_prof_' . (int) @filemtime( $dir . 'tracks.json' );
		$out = get_transient( $key );
		if ( is_array( $out ) ) {
			return $out;
		}
		$out = array();
		$n   = 48;
		foreach ( $events as $event ) {
			foreach ( (array) ( $event['tracks'] ?? array() ) as $tr ) {
				$file = (string) ( $tr['points_file'] ?? '' );
				if ( '' === $file || isset( $out[ $file ] ) || ! is_readable( $dir . 'tracks/' . $file ) ) {
					continue;
				}
				$pts = json_decode( (string) file_get_contents( $dir . 'tracks/' . $file ), true )['pts'] ?? array();
				if ( ! is_array( $pts ) || count( $pts ) < 2 ) {
					continue;
				}
				$total = (float) end( $pts )[3];
				if ( $total <= 0 ) {
					continue;
				}
				$last    = count( $pts ) - 1;
				$samples = array();
				for ( $i = 0, $j = 0; $i < $n; $i++ ) {
					$d = $total * $i / ( $n - 1 );
					while ( $j < $last && $pts[ $j + 1 ][3] < $d ) {
						++$j;
					}
					$samples[] = (int) round( (float) $pts[ $j ][2] );
				}
				$out[ $file ] = $samples;
			}
		}
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}
}

if ( ! function_exists( 'tsr_track_result_stats' ) ) {
	/**
	 * Results per current track, for the modal's "Колко време отнема" and
	 * "Финишири по години": each published result table goes to the current
	 * track of the same race whose nominal distance is nearest (within 1 km).
	 * Percentiles, not records — a few wrong rows (a shortened course, a
	 * mistyped time) barely move them. Cached per results generation.
	 *
	 * @return array<string, array{n: int, p10: int, p50: int, p90: int, years: array<string, array{0: int, 1: int, 2: int}>, results: string}>
	 */
	function tsr_track_result_stats( array $events ): array {
		$key = 'tsr_track_stats_g' . tsr_cache_gen() . '_' . (int) @filemtime( get_stylesheet_directory() . '/data/tracks.json' );
		$out = get_transient( $key );
		if ( is_array( $out ) ) {
			return $out;
		}
		$tracks = array(); // race => list of [slug, nominal km]
		foreach ( $events as $event ) {
			foreach ( (array) $event['tracks'] as $tr ) {
				$km = tsr_track_nominal_km( $tr );
				if ( null !== $km && 'legacy' !== tsr_track_status( $tr ) ) {
					$tracks[ (string) $event['name'] ][] = array( (string) $tr['slug'], $km );
				}
			}
		}
		$assign = array(); // post id => [slug, year, gender, event base]
		foreach ( tsr_results_index() as $id => $e ) {
			$race = tsr_runner_event_name( $e['event_base'] );
			$km   = (float) $e['km'];
			if ( $km <= 0 || empty( $tracks[ $race ] ) ) {
				continue;
			}
			$best = null;
			foreach ( $tracks[ $race ] as [ $slug, $tkm ] ) {
				$diff = abs( $tkm - $km );
				if ( $diff <= 1.0 && ( null === $best || $diff < $best[1] ) ) {
					$best = array( $slug, $diff );
				}
			}
			if ( null !== $best ) {
				$assign[ (int) $id ] = array( $best[0], (string) $e['season'], (string) $e['gender'], (string) $e['event_base'] );
			}
		}
		update_meta_cache( 'post', array_keys( $assign ) );
		$times  = array();
		$latest = array(); // slug => [year, event base]
		$out    = array();
		foreach ( $assign as $id => [ $slug, $year, $gender, $base ] ) {
			$set = json_decode( (string) get_post_meta( $id, '_tsr_result_set', true ), true );
			foreach ( (array) ( $set['rows'] ?? array() ) as $r ) {
				$s = tsr_runner_seconds( (string) ( $r['finish_time'] ?? '' ) );
				if ( null === $s || $s < 300 ) {
					continue;
				}
				$g = 'M' === $gender ? 0 : ( 'F' === $gender ? 1 : 2 );
				$out[ $slug ]['years'][ $year ][ $g ] = ( $out[ $slug ]['years'][ $year ][ $g ] ?? 0 ) + 1;
				$times[ $slug ][] = $s;
			}
			if ( (int) $year > ( $latest[ $slug ][0] ?? 0 ) ) {
				$latest[ $slug ] = array( (int) $year, $base );
			}
		}
		foreach ( $times as $slug => $list ) {
			sort( $list );
			$n = count( $list );
			ksort( $out[ $slug ]['years'] );
			foreach ( $out[ $slug ]['years'] as &$counts ) {
				$counts = array( $counts[0] ?? 0, $counts[1] ?? 0, $counts[2] ?? 0 );
			}
			unset( $counts );
			$out[ $slug ] += array(
				'n'       => $n,
				'p10'     => $list[ intdiv( $n, 10 ) ],
				'p50'     => $list[ intdiv( $n, 2 ) ],
				'p90'     => $list[ min( $n - 1, intdiv( $n * 9, 10 ) ) ],
				'results' => isset( $latest[ $slug ] ) ? tsr_event_results_url( $latest[ $slug ][1], $latest[ $slug ][0] ) : '',
				'year'    => $latest[ $slug ][0] ?? 0,
			);
		}
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}
}

if ( ! function_exists( 'tsr_track_spark' ) ) {
	/** Small elevation profile (SVG, stroke and fill from currentColor). */
	function tsr_track_spark( array $p ): string {
		if ( count( $p ) < 2 ) {
			return '';
		}
		$lo  = min( $p );
		$rng = max( max( $p ) - $lo, 60 ); // flat courses stay flat-looking
		$w   = 300;
		$h   = 30;
		$d   = '';
		foreach ( $p as $i => $v ) {
			$d .= ( $i ? 'L' : 'M' ) . round( $i * $w / ( count( $p ) - 1 ), 1 ) . ' ' . round( 3 + ( $h - 6 ) * ( 1 - ( $v - $lo ) / $rng ), 1 );
		}
		return '<svg class="tsr-trk__spark" viewBox="0 0 300 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
			. '<path d="' . $d . 'L300 30L0 30Z" fill="currentColor" opacity=".15"/>'
			. '<path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>';
	}
}

if ( ! function_exists( 'tsr_trk_row' ) ) {
	/**
	 * One distance row. The data-* attributes feed the modal (js/traseta-modal.js).
	 *
	 * @param array<string, mixed>     $tr       Track entry from tracks.json.
	 * @param string                   $event    Race name.
	 * @param array<string, list<int>> $profiles tsr_track_profiles() output.
	 * @param array<string, mixed>     $ctx      Modal extras: 'kicker' (race · month · place),
	 *                                           'race_url', 'stats' (tsr_track_result_stats()).
	 */
	function tsr_trk_row( array $tr, string $event, array $profiles, array $ctx = array() ): void {
		$gpx_base    = get_stylesheet_directory_uri() . '/gpx/';
		$points_base = get_stylesheet_directory_uri() . '/data/tracks/';
		$nominal     = tsr_track_nominal_km( $tr );
		$cat         = tsr_track_cat( $nominal );
		$colour      = in_array( $tr['slug'] ?? '', TSR_TRACK_XL_SLUGS, true ) ? 'xl' : $cat;
		$cat_label   = array( 'short' => 'къса', 'medium' => 'средна', 'long' => 'дълга' )[ $cat ] ?? '';
		$label       = null !== $nominal ? str_replace( '.', ',', (string) $nominal ) . ' км' : (string) ( $tr['variant'] ?? $tr['title'] );
		$laps        = tsr_track_laps( $tr );
		$legacy      = 'legacy' === tsr_track_status( $tr );
		$prof        = $profiles[ (string) ( $tr['points_file'] ?? '' ) ] ?? array();
		$stats       = $legacy ? null : ( $ctx['stats'][ $tr['slug'] ] ?? null );
		$modal_stats = null;
		if ( $stats && $stats['n'] >= 20 ) { // too few finishes say nothing about a typical time
			$modal_stats = array(
				'n'     => $stats['n'],
				'p'     => array( $stats['p10'], $stats['p50'], $stats['p90'] ),
				'years' => $stats['years'],
			);
		}
		?>
		<li class="tsr-trk tsr-trk--<?php echo esc_attr( '' !== $colour ? $colour : 'none' ); ?>"
			data-cat="<?php echo esc_attr( $cat ); ?>"
			data-title="<?php echo esc_attr( $tr['title'] ); ?>"
			data-variant="<?php echo esc_attr( $tr['variant'] ?? '' ); ?>"
			data-event="<?php echo esc_attr( $event ); ?>"
			data-gpx="<?php echo esc_attr( ! empty( $tr['gpx_file'] ) ? $gpx_base . $tr['gpx_file'] : '' ); ?>"
			data-kml="<?php echo esc_attr( ! empty( $tr['kml_file'] ) ? $gpx_base . $tr['kml_file'] : '' ); ?>"
			data-points="<?php echo esc_attr( ! empty( $tr['points_file'] ) ? $points_base . $tr['points_file'] : '' ); ?>"
			data-strava="<?php echo esc_attr( tsr_track_strava( $tr ) ); ?>"
			data-distance="<?php echo esc_attr( (string) ( $tr['distance_km'] ?? '' ) ); ?>"
			data-ascent="<?php echo esc_attr( (string) ( $tr['ascent_m'] ?? '' ) ); ?>"
			data-descent="<?php echo esc_attr( (string) ( $tr['descent_m'] ?? '' ) ); ?>"
			data-highest="<?php echo esc_attr( (string) ( $tr['highest_m'] ?? '' ) ); ?>"
			data-lowest="<?php echo esc_attr( (string) ( $tr['lowest_m'] ?? '' ) ); ?>"
			data-slug="<?php echo esc_attr( (string) $tr['slug'] ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			data-catname="<?php echo esc_attr( $cat_label ); ?>"
			data-colour="<?php echo esc_attr( $colour ); ?>"
			data-stars="<?php echo esc_attr( (string) ( $tr['stars'] ?? '' ) ); ?>"
			data-laps="<?php echo esc_attr( (string) $laps ); ?>"
			data-kicker="<?php echo esc_attr( $legacy && ! empty( $tr['year'] ) ? $event . ' · стара версия ' . $tr['year'] : ( $ctx['kicker'] ?? $event ) ); ?>"
			data-garmin="<?php echo esc_attr( tsr_track_garmin( $tr ) ); ?>"
			data-race-url="<?php echo esc_attr( (string) ( $ctx['race_url'] ?? '' ) ); ?>"
			data-results="<?php echo esc_attr( $stats['results'] ?? '' ); ?>"
			data-results-year="<?php echo esc_attr( (string) ( $stats['year'] ?? '' ) ); ?>"
			<?php if ( $modal_stats ) : ?>data-stats="<?php echo esc_attr( (string) wp_json_encode( $modal_stats ) ); ?>"<?php endif; ?>>
			<div class="tsr-trk__km">
				<?php // The button's name starts with the visible text; the full variant and race follow for screen readers. ?>
				<button class="tsr-trk__open" type="button" aria-haspopup="dialog"><?php echo esc_html( $label ); ?><span class="screen-reader-text"> — <?php echo esc_html( $event . ', ' . ( $tr['variant'] ?? '' ) ); ?>, детайли за трасето</span></button>
				<?php if ( '' !== $cat_label ) : ?>
					<small><?php echo esc_html( $cat_label ); ?></small>
				<?php endif; ?>
			</div>
			<div class="tsr-trk__mid">
				<div class="tsr-trk__stats">
					<?php if ( ! empty( $tr['distance_km'] ) ) : ?>
						<span title="Дължина по GPX"><b><?php echo esc_html( number_format_i18n( (float) $tr['distance_km'], 1 ) ); ?> км</b></span>
					<?php endif; ?>
					<?php if ( isset( $tr['ascent_m'] ) ) : ?>
						<span title="Изкачване"><span aria-hidden="true">↗</span><span class="screen-reader-text">Изкачване</span> <b><?php echo esc_html( number_format_i18n( (int) $tr['ascent_m'] ) ); ?> м</b></span>
					<?php endif; ?>
					<?php if ( isset( $tr['highest_m'] ) ) : ?>
						<span title="Най-висока точка"><span aria-hidden="true">▲</span><span class="screen-reader-text">Най-висока точка</span> <b><?php echo esc_html( number_format_i18n( (int) $tr['highest_m'] ) ); ?> м</b></span>
					<?php endif; ?>
					<?php if ( $laps > 1 ) : ?>
						<span class="tsr-trk__laps"><?php echo esc_html( $laps ); ?> обиколки</span>
					<?php endif; ?>
					<?php if ( $legacy && ! empty( $tr['year'] ) ) : ?>
						<span><?php echo esc_html( (string) $tr['year'] ); ?></span>
					<?php endif; ?>
					<?php echo tsr_star_rating( isset( $tr['stars'] ) ? (int) $tr['stars'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from ints + esc_attr. ?>
				</div>
				<?php echo tsr_track_spark( $prof ); // phpcs:ignore WordPress.Security.EscapeOutput -- numbers only. ?>
			</div>
			<span class="tsr-trk__go" aria-hidden="true">›</span>
		</li>
		<?php
	}
}

if ( ! function_exists( 'tsr_trk_card' ) ) {
	/**
	 * One race card.
	 *
	 * @param array<string, mixed>      $event    tracks.json event.
	 * @param array<string, mixed>|null $cal      Calendar data (month, place, image) or null.
	 * @param array<string, list<int>>  $profiles tsr_track_profiles() output.
	 * @param array<string, mixed>      $stats    tsr_track_result_stats() output.
	 */
	function tsr_trk_card( array $event, ?array $cal, array $profiles, array $stats = array() ): void {
		$current = array();
		$legacy  = array();
		foreach ( (array) $event['tracks'] as $tr ) {
			if ( 'legacy' === tsr_track_status( $tr ) ) {
				$legacy[] = $tr;
			} else {
				$current[] = $tr;
			}
		}
		$by_km = static fn( array $a, array $b ): int => ( $a['distance_km'] ?? 0 ) <=> ( $b['distance_km'] ?? 0 );
		usort( $current, $by_km );
		usort( $legacy, $by_km );
		// A race whose tracks are all old versions shows them directly — a card
		// with only a folded "Стари версии" looks empty.
		if ( ! $current ) {
			$current = $legacy;
			$legacy  = array();
		}
		$name  = (string) $event['name'];
		$image = $cal['image'] ?? '';
		$ctx   = array(
			'kicker'   => implode( ' · ', array_filter( array( $name, $cal['month'] ?? '', $cal['place'] ?? '' ) ) ),
			'race_url' => $cal['url'] ?? '',
			'stats'    => $stats,
		);
		?>
		<article class="tsr-tc__card<?php echo '' !== $image ? ' tsr-tc__card--photo' : ''; ?>">
			<header class="tsr-tc__top">
				<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image(). ?>
				<?php if ( ! empty( $cal['month'] ) ) : ?>
					<span class="tsr-tc__month"><?php echo esc_html( $cal['month'] ); ?></span>
				<?php endif; ?>
				<div>
					<h2 class="tsr-tc__name"><?php echo esc_html( $name ); ?></h2>
					<?php if ( ! empty( $cal['place'] ) ) : ?>
						<p class="tsr-tc__place"><?php echo esc_html( $cal['place'] ); ?></p>
					<?php endif; ?>
				</div>
			</header>
			<?php if ( $current ) : ?>
				<ul class="tsr-tc__rows">
					<?php
					foreach ( $current as $tr ) {
						tsr_trk_row( $tr, $name, $profiles, $ctx );
					}
					?>
				</ul>
			<?php endif; ?>
			<?php if ( $legacy ) : ?>
				<details class="tsr-tc__old">
					<summary>Стари версии (<?php echo esc_html( (string) count( $legacy ) ); ?>)</summary>
					<ul class="tsr-tc__rows">
						<?php
						foreach ( $legacy as $tr ) {
							tsr_trk_row( $tr, $name, $profiles, $ctx );
						}
						?>
					</ul>
				</details>
			<?php endif; ?>
		</article>
		<?php
	}
}

// ── Data ─────────────────────────────────────────────────────────────────────

$tsr_events   = tsr_tracks_events();
$tsr_profiles = tsr_track_profiles( $tsr_events );
$tsr_stats    = tsr_track_result_stats( $tsr_events );

// Calendar: races with an event in the last 14 months or ahead (same window as
// the "Серия" menu). Per race the next upcoming event wins, else the latest;
// the photo falls back to any event of that race that has one.
$tsr_cal = array(); // race name => [ts, month, place, image, order]
if ( post_type_exists( 'ajde_events' ) ) {
	$tsr_now   = time();
	$tsr_since = $tsr_now - 425 * DAY_IN_SECONDS;
	$tsr_pick  = array(); // race name => event id
	$tsr_photo = array(); // race name => event id with a featured image
	foreach ( get_posts( array( 'post_type' => 'ajde_events', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $tsr_eid ) {
		$tsr_name = tsr_runner_event_name( tsr_event_base_name( html_entity_decode( get_the_title( $tsr_eid ), ENT_QUOTES, 'UTF-8' ) ) );
		$tsr_ts   = (int) get_post_meta( $tsr_eid, 'evcal_srow', true );
		if ( has_post_thumbnail( $tsr_eid ) && ( ! isset( $tsr_photo[ $tsr_name ] ) || $tsr_ts > $tsr_photo[ $tsr_name ][1] ) ) {
			$tsr_photo[ $tsr_name ] = array( $tsr_eid, $tsr_ts );
		}
		if ( $tsr_ts < $tsr_since ) {
			continue;
		}
		$tsr_prev = $tsr_pick[ $tsr_name ] ?? null;
		// Upcoming beats past; among upcoming the soonest, among past the latest.
		$tsr_better = null === $tsr_prev
			|| ( $tsr_ts >= $tsr_now && ( $tsr_prev[1] < $tsr_now || $tsr_ts < $tsr_prev[1] ) )
			|| ( $tsr_ts < $tsr_now && $tsr_prev[1] < $tsr_now && $tsr_ts > $tsr_prev[1] );
		if ( $tsr_better ) {
			$tsr_pick[ $tsr_name ] = array( $tsr_eid, $tsr_ts );
		}
	}
	foreach ( $tsr_pick as $tsr_name => [ $tsr_eid, $tsr_ts ] ) {
		$tsr_img_id = has_post_thumbnail( $tsr_eid ) ? $tsr_eid : ( $tsr_photo[ $tsr_name ][0] ?? 0 );
		$tsr_cal[ $tsr_name ] = array(
			'month' => tsr_bg_month( (int) gmdate( 'n', $tsr_ts ) ),
			'place' => trim( (string) get_post_meta( $tsr_eid, 'evcal_location_raw', true ) ),
			'image' => $tsr_img_id ? tsr_card_image( $tsr_img_id, 'tsr-tc__img', '(max-width: 900px) calc(100vw - 2.5rem), 560px' ) : '',
			'order' => (int) gmdate( 'n', $tsr_ts ) * 100 + (int) gmdate( 'j', $tsr_ts ),
			// Same race page link as the "Серия" menu (tsr_menu_races()).
			'url'   => add_query_arg( 'sabitie', sanitize_title( tsr_event_base_name( html_entity_decode( get_the_title( $tsr_eid ), ENT_QUOTES, 'UTF-8' ) ) ), home_url( '/event/' ) ),
		);
	}
}

$tsr_on_cal = array();
$tsr_other  = array();
foreach ( $tsr_events as $tsr_event ) {
	if ( isset( $tsr_cal[ $tsr_event['name'] ] ) ) {
		$tsr_on_cal[] = $tsr_event;
	} else {
		$tsr_other[] = $tsr_event;
	}
}
usort( $tsr_on_cal, static fn( array $a, array $b ): int => $tsr_cal[ $a['name'] ]['order'] <=> $tsr_cal[ $b['name'] ]['order'] );
usort( $tsr_other, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );

$tsr_count_current = static function ( array $events ): int {
	$n = 0;
	foreach ( $events as $e ) {
		foreach ( (array) $e['tracks'] as $tr ) {
			$n += 'legacy' === tsr_track_status( $tr ) ? 0 : 1;
		}
	}
	return $n;
};

get_header();
tsr_track_icon_defs();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Трасета</h1>
		<p class="tsr-page-hero__subtitle">
			Всички маршрути от сериите — дистанция, денивелация и GPX за изтегляне
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Трасета' ); ?>

		<?php if ( empty( $tsr_events ) ) : ?>
			<p class="tsr-empty">Няма налични трасета в момента.</p>
		<?php else : ?>

			<div class="tsr-tc" data-filter="all">
				<div class="tsr-tc__bar" role="group" aria-label="Покажи дистанции">
					<button type="button" class="tsr-tc__chip" data-f="all" aria-pressed="true">Всички</button>
					<button type="button" class="tsr-tc__chip" data-f="short" aria-pressed="false"><i class="tsr-tc__dot tsr-tc__dot--short" aria-hidden="true"></i>Къса <small>под 8 км</small></button>
					<button type="button" class="tsr-tc__chip" data-f="medium" aria-pressed="false"><i class="tsr-tc__dot tsr-tc__dot--medium" aria-hidden="true"></i>Средна <small>8–13,9 км</small></button>
					<button type="button" class="tsr-tc__chip" data-f="long" aria-pressed="false"><i class="tsr-tc__dot tsr-tc__dot--long" aria-hidden="true"></i>Дълга <small>14+ км</small></button>
				</div>

				<?php if ( $tsr_on_cal ) : ?>
					<p class="tsr-tc__sub">
						<?php echo esc_html( sprintf( '%s в календара · %d трасета. Избери дистанция за карта, профил и GPX.', tsr_races_label( count( $tsr_on_cal ) ), $tsr_count_current( $tsr_on_cal ) ) ); ?>
					</p>
					<div class="tsr-tc__grid">
						<?php
						foreach ( $tsr_on_cal as $tsr_event ) {
							tsr_trk_card( $tsr_event, $tsr_cal[ $tsr_event['name'] ], $tsr_profiles, $tsr_stats );
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( $tsr_other ) : ?>
					<section class="tsr-tc__more" aria-labelledby="tsr-tc-other">
						<h2 class="tsr-tc__h2" id="tsr-tc-other">Други трасета</h2>
						<p class="tsr-tc__sub">Състезания, които в момента не са в календара. Трасетата и GPX файловете остават.</p>
						<div class="tsr-tc__grid tsr-tc__grid--plain">
							<?php
							foreach ( $tsr_other as $tsr_event ) {
								tsr_trk_card( $tsr_event, null, $tsr_profiles, $tsr_stats );
							}
							?>
						</div>
					</section>
				<?php endif; ?>

				<p class="tsr-tc__none" hidden>Няма трасета в тази категория.</p>
			</div>

		<?php endif; ?>

	</div>
</main>

<!-- Track detail modal — populated by js/traseta-modal.js on row click
     (design approved 2026-11-19): heading, key numbers, map + profile,
     typical finish times and finishers per year, sticky action bar. -->
<div class="tsr-modal" id="tsr-track-modal" hidden>
	<div class="tsr-modal__overlay" data-close></div>
	<div class="tsr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tsr-modal-title">
		<button class="tsr-modal__close" type="button" data-close aria-label="Затвори">&times;</button>
		<header class="tsr-md__head">
			<p class="tsr-md__kicker" id="tsr-modal-kicker"></p>
			<h2 class="tsr-modal__title" id="tsr-modal-title"></h2>
		</header>
		<div class="tsr-md__kpis" id="tsr-modal-stats"></div>
		<!-- Fetch status: spinner while the GPX loads, an error line when it
		     fails. aria-live so screen readers hear the state change. -->
		<div class="tsr-modal__status" id="tsr-modal-status" role="status" aria-live="polite" hidden></div>
		<div class="tsr-modal__map" id="tsr-modal-map"></div>
		<div class="tsr-modal__chart-wrap" id="tsr-modal-chart-wrap" hidden>
			<svg class="tsr-modal__chart" id="tsr-modal-chart"
			     viewBox="0 0 800 240" preserveAspectRatio="xMidYMid meet"
			     role="img" aria-label="Профил на изкачването"></svg>
			<div class="tsr-chart-tooltip" id="tsr-modal-chart-tooltip" hidden></div>
			<div class="tsr-chart-legend">
				<span class="tsr-chart-legend__item"><span class="tsr-chart-legend__swatch tsr-chart-legend__swatch--climb"></span>Изкачване</span>
				<span class="tsr-chart-legend__item"><span class="tsr-chart-legend__swatch tsr-chart-legend__swatch--flat"></span>Равно</span>
				<span class="tsr-chart-legend__item"><span class="tsr-chart-legend__swatch tsr-chart-legend__swatch--descent"></span>Спускане</span>
			</div>
		</div>
		<div class="tsr-md__sec" id="tsr-modal-results" hidden></div>
		<div class="tsr-md__acts">
			<a class="tsr-md__btn tsr-md__btn--gpx" id="tsr-modal-gpx" href="#" download hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-download"/></svg>
				GPX
			</a>
			<a class="tsr-md__btn" id="tsr-modal-kml" href="#" download hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-download"/></svg>
				KML
			</a>
			<a class="tsr-md__btn tsr-md__btn--strava" id="tsr-modal-strava" href="#" target="_blank" rel="noopener" hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-strava"/></svg>
				Strava
			</a>
			<a class="tsr-md__btn tsr-md__btn--garmin" id="tsr-modal-garmin" href="#" target="_blank" rel="noopener" hidden>
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor"><use href="#tsr-i-garmin"/></svg>
				Garmin
			</a>
			<span class="tsr-md__sp"></span>
			<button class="tsr-md__btn" id="tsr-modal-copy" type="button">
				<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><use href="#tsr-i-link"/></svg>
				<span>Копирай линк</span>
			</button>
			<a class="tsr-md__btn" id="tsr-modal-results-link" href="#" hidden></a>
			<a class="tsr-md__btn" id="tsr-modal-race" href="#" hidden>За състезанието ›</a>
		</div>
	</div>
</div>

<?php get_footer(); ?>
