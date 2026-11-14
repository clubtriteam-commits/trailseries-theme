<?php
declare( strict_types=1 );
/**
 * Runner profile (/runner/): search index for the autocomplete and the
 * profile itself (owner-approved mockup, 2026-11-14 — docs/RUNNER-PROFILE-AUDIT.md).
 *
 * Nothing here changes stored results. One person = one canonical name
 * (tsr_runner_canonical(): automatic Cyrillic/Latin merges + owner aliases).
 *
 * @package exhibz-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Search index ─────────────────────────────────────────────────────────────

/**
 * One entry per runner for the autocomplete:
 * [ name, starts, last race year, club (latest known), other spelling ].
 * No ages. Built once per results cache generation.
 *
 * @return list<array{0:string,1:int,2:int,3:string,4:string}>
 */
function tsr_runner_search_index(): array {
	$key    = 'tsr_runner_search_g' . tsr_cache_gen();
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	global $wpdb;
	$index  = tsr_results_index();
	$people = array();
	$seen   = array(); // names hash: a table published twice counts once
	foreach ( array_chunk( array_keys( $index ), 100 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blobs = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tsr_result_set' AND post_id IN ( {$in} ) ORDER BY post_id" );
		foreach ( $blobs as $blob ) {
			$entry = $index[ (int) $blob->post_id ];
			if ( '' !== $entry['hash'] ) {
				if ( isset( $seen[ $entry['hash'] ] ) ) {
					continue;
				}
				$seen[ $entry['hash'] ] = true;
			}
			$data = json_decode( (string) $blob->meta_value, true );
			if ( ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
				continue;
			}
			$year = (int) $entry['season'];
			foreach ( $data['rows'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$raw = trim( (string) preg_replace( '/\s+/u', ' ', ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) );
				if ( '' === $raw ) {
					continue;
				}
				$name = tsr_runner_canonical( $raw );
				$k    = mb_strtolower( $name );
				$p    = &$people[ $k ];
				if ( null === $p ) {
					$p = array( $name, 0, 0, '', '', 0 );
				}
				++$p[1];
				$team = trim( (string) ( $row['team'] ?? '' ) );
				if ( $year >= $p[2] ) {
					$p[2] = $year;
				}
				if ( '' !== $team && $year >= $p[5] ) {
					$p[3] = $team;
					$p[5] = $year;
				}
				// The spelling in the other script, to tell the visitor it is the same person.
				if ( '' === $p[4] && mb_strtolower( $raw ) !== $k && (bool) preg_match( '/\p{Cyrillic}/u', $raw ) !== (bool) preg_match( '/\p{Cyrillic}/u', $name ) ) {
					$p[4] = $raw;
				}
				unset( $p );
			}
		}
	}
	$out = array();
	foreach ( $people as $p ) {
		$out[] = array( $p[0], $p[1], $p[2], $p[3], $p[4] );
	}
	usort( $out, static fn( array $a, array $b ): int => array( $b[1], $a[0] ) <=> array( $a[1], $b[0] ) );
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route(
		'tsr/v1',
		'/runners',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true', // the same names as the public results tables
			'callback'            => static function (): WP_REST_Response {
				$res = new WP_REST_Response( tsr_runner_search_index() );
				// The URL carries the cache generation (?g=), so it can be cached hard.
				$res->header( 'Cache-Control', 'public, max-age=86400' );
				$res->header( 'X-Robots-Tag', 'noindex' );
				return $res;
			},
		)
	);
} );

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! is_page_template( 'page-runner.php' ) ) {
		return;
	}
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	foreach ( array( 'runner-search', 'runner-profile' ) as $name ) {
		wp_enqueue_script( 'tsr-' . $name, "{$uri}/js/{$name}.js", array(), (string) filemtime( "{$dir}/js/{$name}.js" ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
}, 25 );

// ── Helpers ──────────────────────────────────────────────────────────────────

/** One name per race across its spellings over the years ("The Chrismas Run", "GOLYAM SECHKO RUN"). */
function tsr_runner_event_name( string $base ): string {
	static $needles = array(
		'7 hills' => '7 Hills Run', 'baba marta' => 'Baba Marta Run', 'birthday' => 'Birthday Run', 'boyana' => 'Boyana X Trail',
		'buhovo half' => 'Buhovo Half Marathon', 'buhovo' => 'Buhovo Trail Run', 'golyam se' => 'Golyam Sechko Run',
		'golyamse' => 'Golyam Sechko Run', 'iran' => 'iRan Run', 'lyulin' => 'Lyulin Trail Run', 'malak se' => 'Malak Sechko Run',
		'palakaria' => 'Palakaria', 'палакария' => 'Palakaria', 'pancharevo' => 'Pancharevo Night Run',
		'pirin skyrun' => 'Pirin Skyrun', 'pirin vertical' => 'Pirin Vertical', 'simeonovo' => 'Simeonovo Run',
		'cactus' => 'The Cactus Run', 'christmas' => 'The Christmas Run', 'chrismas' => 'The Christmas Run', 'коледн' => 'The Christmas Run',
	);
	$b = mb_strtolower( $base );
	foreach ( $needles as $needle => $name ) {
		if ( false !== mb_strpos( $b, $needle ) ) {
			return $name;
		}
	}
	return trim( $base );
}

/**
 * The measured track of a race distance (data/tracks.json): same race, GPX
 * distance within 1 km of the results' distance; a track of that year wins.
 *
 * @return array{km: float, up: int}|null
 */
function tsr_runner_track( string $event_name, float $km, int $year ): ?array {
	static $by = null;
	if ( null === $by ) {
		$by = array();
		foreach ( tsr_tracks_events() as $event ) {
			$name = tsr_runner_event_name( (string) ( $event['name'] ?? '' ) );
			foreach ( (array) ( $event['tracks'] ?? array() ) as $t ) {
				if ( ! empty( $t['distance_km'] ) && isset( $t['ascent_m'] ) ) {
					$by[ $name ][] = array( (float) $t['distance_km'], (int) $t['ascent_m'], (int) ( $t['year'] ?? 0 ) );
				}
			}
		}
		// The half marathon's shorter distance runs the Buhovo Trail Run course.
		$by['Buhovo Half Marathon'] = array_merge( $by['Buhovo Half Marathon'] ?? array(), $by['Buhovo Trail Run'] ?? array() );
	}
	$best = null;
	foreach ( $by[ $event_name ] ?? array() as $t ) {
		$diff = abs( $t[0] - $km );
		if ( $diff > 1.0 ) {
			continue;
		}
		$score = array( $diff, $t[2] === $year ? 0 : 1 );
		if ( null === $best || $score < $best[0] ) {
			$best = array( $score, $t );
		}
	}
	return null === $best ? null : array( 'km' => $best[1][0], 'up' => $best[1][1] );
}

/** "1:02:03" → seconds, or null. */
function tsr_runner_seconds( string $time ): ?int {
	return preg_match( '/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $time, $m ) ? (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] : null;
}

/** Seconds → "1:02:03". */
function tsr_runner_hms( int $s ): string {
	return sprintf( '%d:%02d:%02d', intdiv( $s, 3600 ), intdiv( $s % 3600, 60 ), $s % 60 );
}

/** 15.0 → "15 км", 10.75 → "10,75 км". */
function tsr_runner_km( float $km ): string {
	return str_replace( '.', ',', rtrim( rtrim( number_format( $km, 2, '.', '' ), '0' ), '.' ) ) . ' км';
}

/** 26481 → "26 481". */
function tsr_runner_num( float $n ): string {
	return number_format( round( $n ), 0, ',', "\u{00a0}" );
}

/**
 * Calendar start times: race year → lower-case race name → timestamp
 * (evcal_srow holds the typed local time as a UTC timestamp).
 *
 * @return array<int, array<string, int>>
 */
function tsr_runner_calendar(): array {
	static $cal = null;
	if ( null === $cal ) {
		$cal = array();
		if ( post_type_exists( 'ajde_events' ) ) {
			foreach ( get_posts( array( 'post_type' => 'ajde_events', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $eid ) {
				$title = html_entity_decode( get_the_title( $eid ), ENT_QUOTES, 'UTF-8' );
				$ts    = (int) get_post_meta( $eid, 'evcal_srow', true );
				$year  = tsr_title_year( $title ) ?? (int) gmdate( 'Y', $ts );
				$cal[ $year ][ tsr_runner_event_name( tsr_event_base_name( $title ) ) ] = $ts;
			}
		}
	}
	return $cal;
}

// ── Profile data ─────────────────────────────────────────────────────────────

/**
 * Starts of one runner with everything the profile shows, oldest first.
 *
 * @param list<array<string, mixed>> $cands Rows collected by page-runner.php.
 * @return list<array<string, mixed>>
 */
function tsr_runner_rows( array $cands ): array {
	$cal  = tsr_runner_calendar();
	$rows = array();
	$seen = array();
	foreach ( $cands as $c ) {
		$event = tsr_runner_event_name( (string) $c['event'] );
		$year  = (int) $c['year'];
		$km    = (float) $c['km'];
		$fin   = 'FIN' === $c['status'];
		$sec   = tsr_runner_seconds( (string) $c['time'] );
		// The same result in two tables of one race (a republished table) is one start.
		$dup = implode( '|', array( $event, $year, $km, (string) $c['place'], (string) $c['time'], $c['status'] ) );
		if ( isset( $seen[ $dup ] ) ) {
			continue;
		}
		$seen[ $dup ] = true;
		$track = $km > 0 ? tsr_runner_track( $event, $km, $year ) : null;
		$ts    = $cal[ $year ][ $event ] ?? 0;
		$rows[] = array(
			'event'  => $event,
			'year'   => $year,
			'series' => (int) $c['series'],
			'order'  => $year * 10000 + ( $ts > 0 ? (int) gmdate( 'n', $ts ) * 100 + (int) gmdate( 'j', $ts ) : tsr_race_usual_month( (string) $c['event'] ) * 100 ),
			'id'     => (int) $c['post_id'],
			'km'     => $km,
			'cat'    => 'bonus' === $c['cat'] ? 'long' : (string) $c['cat'], // legacy 'bonus' = 21+ km
			'half'   => 'bonus' === $c['cat'],
			'place'  => $fin ? $c['place'] : null,
			'n'      => (int) $c['n'],
			'sec'    => $fin ? $sec : null,
			'status' => (string) $c['status'],
			'fin'    => $fin,
			'pct'    => ( $fin && null !== $c['place'] && $c['n'] > 0 ) ? (int) round( 100 * ( $c['n'] - $c['place'] ) / $c['n'] ) : null,
			'tkm'    => $track['km'] ?? null,
			'up'     => $track['up'] ?? null,
			'eff'    => ( $fin && null !== $sec && null !== $track ) ? $sec / 60 / ( $track['km'] + $track['up'] / 100 ) : null,
			'team'   => (string) $c['team'],
			'url'    => (string) $c['url'],
			'odd'    => false,
			'pr'     => false,
		);
	}
	usort( $rows, static fn( array $a, array $b ): int => array( $a['order'], $a['id'] ) <=> array( $b['order'], $b['id'] ) );

	// A time far faster than the runner's own level on comparable terrain
	// means the distance in the page title is wrong (a shortened course):
	// kept in the history, left out of records and averages.
	$eff = array_values( array_filter( array_column( $rows, 'eff' ), static fn( $e ): bool => null !== $e ) );
	if ( count( $eff ) >= 4 ) {
		sort( $eff );
		$median = $eff[ intdiv( count( $eff ), 2 ) ];
		foreach ( $rows as &$r ) {
			$r['odd'] = null !== $r['eff'] && $r['eff'] < 0.6 * $median;
		}
		unset( $r );
	}

	// Personal record per repeated course (same race + distance, 2+ finishes).
	$best = array();
	$runs = array();
	foreach ( $rows as $i => $r ) {
		if ( ! $r['fin'] || null === $r['sec'] || $r['km'] <= 0 || $r['odd'] ) {
			continue;
		}
		$k          = $r['event'] . '|' . $r['km'];
		$runs[ $k ] = ( $runs[ $k ] ?? 0 ) + 1;
		if ( ! isset( $best[ $k ] ) || $r['sec'] < $rows[ $best[ $k ] ]['sec'] ) {
			$best[ $k ] = $i;
		}
	}
	foreach ( $best as $k => $i ) {
		$rows[ $i ]['pr'] = $runs[ $k ] > 1;
	}
	return $rows;
}

/** Average of the non-null values of a column, or null. */
function tsr_runner_avg( array $rows, string $col ): ?float {
	$v = array_filter( array_column( $rows, $col ), static fn( $x ): bool => null !== $x );
	return array() === $v ? null : array_sum( $v ) / count( $v );
}

/** Kilometres of a start: the measured track, else the distance in the results. */
function tsr_runner_row_km( array $r ): float {
	return (float) ( $r['tkm'] ?? $r['km'] );
}

/** "5 старта" / "1 старт". */
function tsr_runner_count( int $n, string $one, string $many ): string {
	return $n . "\u{00a0}" . ( 1 === $n ? $one : $many );
}

/**
 * The runner's line in the season standings, if that table is cached
 * (it is computed by /klasiraniya/; the profile never recomputes it).
 *
 * @return array{rank:int, points:int, of:int}|null
 */
function tsr_runner_standing( string $name, int $season ): ?array {
	$hit = get_transient( sprintf( 'tsr_standings4_g%d_s%d', tsr_cache_gen(), $season ) );
	if ( ! is_array( $hit ) || ! isset( $hit['standings'] ) ) {
		return null;
	}
	foreach ( array( 'm', 'f' ) as $g ) {
		foreach ( (array) ( $hit['standings'][ $g ] ?? array() ) as $i => $a ) {
			if ( 0 === strcasecmp( (string) $a['name'], $name ) ) {
				return array( 'rank' => $i + 1, 'points' => (int) $a['points'], 'of' => count( $hit['standings'][ $g ] ) );
			}
		}
	}
	return null;
}

// ── Profile output ───────────────────────────────────────────────────────────

/**
 * Print the whole profile.
 *
 * @param list<array<string, mixed>> $rows tsr_runner_rows().
 */
function tsr_runner_profile_print( string $name, array $rows ): void {
	$fins   = array_values( array_filter( $rows, static fn( array $r ): bool => $r['fin'] ) );
	$clean  = array_values( array_filter( $fins, static fn( array $r ): bool => ! $r['odd'] ) );
	$years  = array_values( array_unique( array_column( $rows, 'year' ) ) );
	$starts = count( $rows );
	$km     = array_sum( array_map( 'tsr_runner_row_km', $fins ) );
	$up     = (int) array_sum( array_column( $fins, 'up' ) );
	$podium = array_values( array_filter( $fins, static fn( array $r ): bool => null !== $r['place'] && $r['place'] <= 3 ) );
	$placed = array_values( array_filter( $fins, static fn( array $r ): bool => null !== $r['place'] ) );
	$best   = null;
	foreach ( $placed as $r ) {
		if ( null === $best || $r['place'] < $best['place'] ) {
			$best = $r;
		}
	}
	$avg_pct = tsr_runner_avg( $clean, 'pct' );
	$club    = '';
	$aka     = array();
	foreach ( $rows as $r ) {
		if ( '' !== $r['team'] ) {
			$club = $r['team'];
		}
	}
	foreach ( tsr_runner_alias_map() as $spelling => $canon ) {
		if ( $canon === $name && (string) $spelling !== mb_strtolower( $name ) ) {
			$aka[] = mb_convert_case( (string) $spelling, MB_CASE_TITLE );
		}
	}
	$last = $rows[ $starts - 1 ];

	// Current season = the newest season with results.
	$season = 0;
	foreach ( tsr_results_index() as $e ) {
		$season = max( $season, (int) $e['series'] );
	}
	$cur  = array_values( array_filter( $rows, static fn( array $r ): bool => $r['series'] === $season ) );
	$prev = array_values( array_filter( $rows, static fn( array $r ): bool => $r['series'] === $season - 1 ) );
	$cal  = tsr_runner_calendar()[ $season ] ?? array();
	asort( $cal );
	$now  = time();
	$next = null;
	foreach ( $cal as $ev => $ts ) {
		if ( $ts > $now ) {
			$next = array( 'event' => $ev, 'ts' => $ts );
			break;
		}
	}
	?>

	<section class="tsr-rp-summary" aria-label="Обобщение">
		<p class="tsr-rp-kicker">Профил на бегач</p>
		<h2 class="tsr-rp-name"><?php echo esc_html( $name ); ?></h2>
		<p class="tsr-rp-sub">
			<?php
			echo esc_html( tsr_runner_count( count( $years ), 'сезон', 'сезона' ) . ' · от ' . min( $years ) . ' г.' );
			if ( '' !== $club ) {
				echo ' · клуб <b>' . esc_html( $club ) . '</b>';
			}
			?>
		</p>
		<?php if ( array() !== $aka ) : ?>
			<p class="tsr-rp-aka">В протоколите и като: <?php echo esc_html( implode( ', ', array_slice( $aka, 0, 3 ) ) ); ?></p>
		<?php endif; ?>

		<p class="tsr-rp-insight">
			<?php
			$same = array_values( array_filter( $clean, static fn( array $r ): bool => $r['cat'] === $last['cat'] && null !== $r['pct'] ) );
			$top  = array() !== $same ? max( array_column( $same, 'pct' ) ) : null;
			$what = '<b>' . esc_html( $last['event'] . ( $last['km'] > 0 ? ' ' . tsr_runner_km( $last['km'] ) : '' ) ) . '</b> (' . (int) $last['year'] . ')';
			$cats = array( 'short' => 'къса', 'medium' => 'средна', 'long' => 'дълга' );
			if ( 1 === $starts && $last['fin'] && null !== $last['pct'] ) {
				echo 'Първи старт в Trail Series: ' . $what . ' — място ' . (int) $last['place'] . ' от ' . (int) $last['n'] . '.'; // phpcs:ignore WordPress.Security.EscapeOutput -- parts escaped above.
			} elseif ( $last['fin'] && null !== $last['pct'] && ! $last['odd'] && count( $same ) >= 3 && $last['pct'] === $top && isset( $cats[ $last['cat'] ] ) ) {
				echo 'На ' . $what . ' изпревари <b>' . (int) $last['pct'] . '%</b> от финиширалите — най-доброто представяне на ' . esc_html( $cats[ $last['cat'] ] ) . ' дистанция от ' . count( $same ) . ' старта.'; // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( $last['fin'] && null !== $last['pct'] ) {
				echo 'Последен старт: ' . $what . ' — място ' . (int) $last['place'] . ' от ' . (int) $last['n'] . ', пред ' . (int) $last['pct'] . '% от финиширалите.'; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				echo 'Последен старт: ' . $what . '.'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</p>

		<div class="tsr-rp-kpis">
			<?php
			$kpis = array(
				array( (string) $starts, 1 === $starts ? 'старт' : 'старта', count( $fins ) . ' финиша (' . (int) round( 100 * count( $fins ) / $starts ) . '%)' ),
				array( tsr_runner_num( $km ), 'км в състезания', $up > 0 ? 'по измерените трасета' : 'по дистанциите в протоколите' ),
				array( $up > 0 ? tsr_runner_num( $up ) : '—', 'м изкачване', $up >= 8849 ? str_replace( '.', ',', (string) ( floor( $up / 884.9 ) / 10 ) ) . ' × Еверест' : 'където има измерено трасе' ),
				array(
					(string) count( $podium ),
					1 === count( $podium ) ? 'подиум' : 'подиума',
					array() !== $podium
						? '🥇 ' . count( array_filter( $podium, static fn( $r ) => 1 === $r['place'] ) ) . ' · 🥈 ' . count( array_filter( $podium, static fn( $r ) => 2 === $r['place'] ) ) . ' · 🥉 ' . count( array_filter( $podium, static fn( $r ) => 3 === $r['place'] ) )
						: ( null !== $best ? 'най-близо: място ' . $best['place'] : '' ),
				),
				array( null !== $best ? (string) $best['place'] : '—', 'най-добро място', null !== $best ? $best['event'] . ' ' . $best['year'] : '' ),
				array( null !== $avg_pct ? round( $avg_pct ) . '%' : '—', 'среден процентил', 'изпреварени финиширали' ),
			);
			foreach ( $kpis as $k ) {
				printf( '<div class="tsr-rp-kpi"><div class="tsr-rp-kpi__n">%s</div><div class="tsr-rp-kpi__l">%s</div><div class="tsr-rp-kpi__s">%s</div></div>', esc_html( $k[0] ), esc_html( $k[1] ), esc_html( $k[2] ) );
			}
			?>
		</div>
	</section>

	<div class="tsr-rp-grid">

		<?php // ── Season ─────────────────────────────────────────────────── ?>
		<section class="tsr-rp-card" aria-labelledby="tsr-rp-season">
			<h2 id="tsr-rp-season"><?php echo esc_html( tsr_season_label( $season ) ); ?></h2>
			<p class="tsr-rp-card__sub">Къде си в момента и какво остава</p>
			<?php
			$done = array();
			foreach ( $cur as $r ) {
				if ( $r['fin'] ) {
					$done[ $r['event'] ] = true;
				}
			}
			$total    = max( count( $cal ), count( $done ) );
			$held     = count( array_filter( $cal, static fn( int $ts ): bool => $ts <= $now ) );
			$standing = array() !== $cur ? tsr_runner_standing( $name, $season ) : null;
			$circ     = 2 * M_PI * 40;
			?>
			<div class="tsr-rp-season">
				<div class="tsr-rp-ring">
					<svg width="92" height="92" viewBox="0 0 92 92" aria-hidden="true"><circle cx="46" cy="46" r="40" fill="none" stroke="#e2e6ea" stroke-width="9"/><circle cx="46" cy="46" r="40" fill="none" stroke="#0088bb" stroke-width="9" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr( round( $total > 0 ? $circ * count( $done ) / $total : 0, 1 ) . ' ' . round( $circ, 1 ) ); ?>"/></svg>
					<div class="tsr-rp-ring__t"><?php echo esc_html( count( $done ) . '/' . $total ); ?><small>състезания</small></div>
				</div>
				<ul class="tsr-rp-facts">
					<?php if ( array() === $cur ) : ?>
						<li>Още няма старт този сезон.</li>
						<li>Последен старт: <?php echo esc_html( $last['event'] . ' ' . $last['year'] ); ?>.</li>
					<?php else : ?>
						<li>
							<?php if ( count( $done ) >= 3 ) : ?>
								<span class="tsr-rp-tag tsr-rp-tag--ok">В класирането</span> нужни са 3 финиша, има <?php echo count( $done ); ?>
							<?php else : ?>
								<span class="tsr-rp-tag tsr-rp-tag--warn">Още <?php echo esc_html( tsr_runner_count( 3 - count( $done ), 'старт', 'старта' ) ); ?> до класиране</span> нужни са 3 финиша
							<?php endif; ?>
						</li>
						<?php if ( null !== $standing ) : ?>
							<li><b><?php echo esc_html( tsr_runner_count( $standing['points'], 'точка', 'точки' ) ); ?></b> · място <?php echo (int) $standing['rank']; ?> от <?php echo (int) $standing['of']; ?> в <a href="<?php echo esc_url( home_url( '/klasiraniya/' ) ); ?>">годишния ранкинг</a></li>
						<?php else : ?>
							<li><a href="<?php echo esc_url( home_url( '/klasiraniya/' ) ); ?>">Виж годишния ранкинг</a></li>
						<?php endif; ?>
						<?php if ( $held > 0 ) : ?>
							<li><?php echo esc_html( count( $done ) . ' от ' . $held . ' проведени състезания' ); ?></li>
						<?php endif; ?>
					<?php endif; ?>
				</ul>
			</div>

			<?php if ( array() !== $cal ) : ?>
				<ul class="tsr-rp-races" aria-label="Състезания от сезона">
					<?php foreach ( $cal as $ev => $ts ) : ?>
						<?php if ( isset( $done[ $ev ] ) ) : ?>
							<li class="is-done">✓ <?php echo esc_html( $ev ); ?></li>
						<?php elseif ( $ts > $now ) : ?>
							<li class="is-next"><?php echo esc_html( $ev . ' · ' . gmdate( 'j', $ts ) . ' ' . tsr_bg_month_short( (int) gmdate( 'n', $ts ) ) ); ?></li>
						<?php else : ?>
							<li class="is-missed"><?php echo esc_html( $ev ); ?></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( null !== $next ) : ?>
				<div class="tsr-rp-next">
					<b>Следва: <?php echo esc_html( $next['event'] . ', ' . gmdate( 'j', $next['ts'] ) . ' ' . tsr_bg_month( (int) gmdate( 'n', $next['ts'] ) ) ); ?>.</b>
					<?php
					$there = array_values( array_filter( $rows, static fn( array $r ): bool => $r['event'] === $next['event'] ) );
					if ( array() === $there ) {
						echo 'Още не си го бягал — добра възможност за нова значка.';
					} else {
						$ok = array_values( array_filter( $there, static fn( array $r ): bool => $r['fin'] && null !== $r['sec'] && ! $r['odd'] ) );
						usort( $ok, static fn( array $a, array $b ): int => $a['sec'] <=> $b['sec'] );
						$lt = $there[ count( $there ) - 1 ];
						echo esc_html( 'Бягал си го ' . tsr_runner_count( count( $there ), 'път', 'пъти' ) . '.' );
						if ( array() !== $ok ) {
							echo ' Най-добро време: <b>' . esc_html( tsr_runner_hms( $ok[0]['sec'] ) ) . '</b>' . esc_html( ( $ok[0]['km'] > 0 ? ' на ' . tsr_runner_km( $ok[0]['km'] ) : '' ) . ' (' . $ok[0]['year'] . ').' );
						}
						if ( $lt['fin'] && null !== $lt['place'] ) {
							echo esc_html( ' Последно участие (' . $lt['year'] . '): място ' . $lt['place'] . ' от ' . $lt['n'] . '.' );
						}
					}
					?>
				</div>
			<?php endif; ?>

			<?php if ( array() !== $cur && array() !== $prev ) : ?>
				<div class="tsr-rp-cmp" aria-label="Сравнение с миналия сезон">
					<?php
					$delta = static function ( float $a, float $b, string $unit ) use ( $season ): string {
						$d = $a - $b;
						if ( abs( $d ) < 0.5 ) {
							return '<em>без промяна</em>';
						}
						return sprintf( '<em class="%s">%s%d%s спрямо %d</em>', $d > 0 ? 'is-up' : 'is-down', $d > 0 ? '+' : '−', abs( round( $d ) ), esc_html( $unit ), $season - 1 );
					};
					$ck = array_sum( array_map( 'tsr_runner_row_km', array_filter( $cur, static fn( $r ) => $r['fin'] ) ) );
					$pk = array_sum( array_map( 'tsr_runner_row_km', array_filter( $prev, static fn( $r ) => $r['fin'] ) ) );
					$cp = tsr_runner_avg( array_filter( $cur, static fn( $r ) => ! $r['odd'] ), 'pct' );
					$pp = tsr_runner_avg( array_filter( $prev, static fn( $r ) => ! $r['odd'] ), 'pct' );
					// phpcs:disable WordPress.Security.EscapeOutput -- $delta() returns escaped markup.
					echo '<div><b>' . count( $cur ) . '</b><span>' . ( 1 === count( $cur ) ? 'старт' : 'старта' ) . '</span>' . $delta( count( $cur ), count( $prev ), '' ) . '</div>';
					echo '<div><b>' . esc_html( tsr_runner_num( $ck ) ) . '</b><span>км</span>' . $delta( $ck, $pk, ' км' ) . '</div>';
					if ( null !== $cp && null !== $pp ) {
						echo '<div><b>' . (int) round( $cp ) . '%</b><span>процентил</span>' . $delta( $cp, $pp, ' п.п.' ) . '</div>';
					}
					// phpcs:enable
					?>
				</div>
			<?php endif; ?>
		</section>

		<?php // ── Charts (drawn by js/runner-profile.js from the data below) ── ?>
		<?php if ( count( $fins ) >= 3 ) : ?>
			<section class="tsr-rp-card tsr-rp-chart" aria-labelledby="tsr-rp-form" hidden data-tsr-rp-chart>
				<h2 id="tsr-rp-form">Форма</h2>
				<p class="tsr-rp-card__sub">Колко процента от финиширалите в същата дистанция и пол са изпреварени. По-високо е по-добре. Линията е средното за годината.</p>
				<div data-chart="form"></div>
				<ul class="tsr-rp-legend"><li><i style="background:#00aadd"></i>къса</li><li><i style="background:#0a1628"></i>средна</li><li><i style="background:#e05c1e"></i>дълга</li></ul>
				<p class="tsr-rp-readout" aria-live="polite">Докосни точка, за да видиш състезанието.</p>
			</section>
			<?php if ( count( $years ) >= 2 ) : ?>
				<section class="tsr-rp-card tsr-rp-chart" aria-labelledby="tsr-rp-vol" hidden data-tsr-rp-chart>
					<h2 id="tsr-rp-vol">Обем по години</h2>
					<p class="tsr-rp-card__sub">Километри в състезания; числото над колоната е броят стартове.</p>
					<div data-chart="volume"></div>
				</section>
				<section class="tsr-rp-card tsr-rp-chart" aria-labelledby="tsr-rp-pace" hidden data-tsr-rp-chart>
					<h2 id="tsr-rp-pace">Темпо спрямо терена</h2>
					<p class="tsr-rp-card__sub">Минути на „изравнен“ километър (100 м изкачване = 1 км). Сравнимо между различни трасета; по-ниско е по-бързо.</p>
					<div data-chart="pace"></div>
				</section>
			<?php endif; ?>
			<script type="application/json" id="tsr-rp-data"><?php
			echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput -- JSON in a data block.
				array_map(
					static fn( array $r ): array => array( 'e' => $r['event'], 'y' => $r['year'], 'km' => $r['km'], 'k' => tsr_runner_row_km( $r ), 'cat' => $r['cat'], 'pl' => $r['place'], 'n' => $r['n'], 'sec' => $r['sec'], 'pct' => $r['pct'], 'eff' => null !== $r['eff'] ? round( $r['eff'], 3 ) : null, 'odd' => $r['odd'], 'fin' => $r['fin'] ),
					$rows
				),
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
			);
			?></script>
		<?php endif; ?>

		<?php // ── Achievements ───────────────────────────────────────────── ?>
		<?php
		$badges = array(); // [ icon, title, note, earned, progress 0..1 ]
		$tiers  = array( 10, 25, 50, 100, 150 );
		foreach ( $tiers as $t ) {
			if ( $starts >= $t ) {
				$badges[] = array( (string) $t, $t . ' старта', $rows[ $t - 1 ]['event'] . ' ' . $rows[ $t - 1 ]['year'], true, 1 );
			} else {
				$badges[] = array( (string) $t, $t . ' старта', $starts . ' от ' . $t, false, $starts / $t );
				break;
			}
		}
		$streak = 1;
		for ( $i = count( $years ) - 1; $i > 0 && 1 === $years[ $i ] - $years[ $i - 1 ]; $i-- ) {
			++$streak;
		}
		if ( $streak >= 2 ) {
			$badges[] = array( (string) $streak, $streak . ' поредни години', $years[ count( $years ) - $streak ] . '–' . max( $years ) . ', без прекъсване', true, 1 );
		}
		if ( $starts >= 5 ) {
			$badges[] = array( '100%', 'Винаги до финала', count( $fins ) . ' от ' . $starts . ' старта завършени', count( $fins ) === $starts, count( $fins ) / $starts );
		}
		$cats_done = count( array_intersect( array( 'short', 'medium', 'long' ), array_unique( array_column( $fins, 'cat' ) ) ) );
		$badges[]  = array( 'К·С·Д', 'Всички дистанции', 3 === $cats_done ? 'къса, средна и дълга' : $cats_done . ' от 3: къса, средна, дълга', 3 === $cats_done, $cats_done / 3 );
		if ( array() !== $cal ) {
			$coll     = count( array_intersect( array_keys( $cal ), array_unique( array_column( $fins, 'event' ) ) ) );
			$badges[] = array( $coll . '/' . count( $cal ), 'Колекционер', 'финиш на всяко състезание от календара', $coll === count( $cal ), $coll / count( $cal ) );
			$badges[] = array( count( $cal ) . '/' . count( $cal ), 'Пълен сезон', $season . ': ' . count( $done ) . ' от ' . count( $cal ) . ' състезания', count( $done ) === count( $cal ), count( $done ) / count( $cal ) );
		}
		$halves = array_values( array_filter( $fins, static fn( array $r ): bool => $r['half'] ) );
		if ( array() !== $halves ) {
			$badges[] = array( '21', 'Полумаратон в планината', $halves[0]['event'] . ' ' . tsr_runner_km( $halves[0]['km'] ) . ', ' . $halves[0]['year'], true, 1 );
		}
		if ( array() !== $podium ) {
			$badges[] = array( '🏅', 'На подиума', tsr_runner_count( count( $podium ), 'път', 'пъти' ) . ' в топ 3', true, 1 );
		}
		if ( $up > 0 ) {
			$ev       = (int) floor( $up / 8849 );
			$badges[] = $ev >= 1
				? array( $ev . '×', 1 === $ev ? 'Един Еверест' : $ev . ' Евереста', tsr_runner_num( $up ) . ' м изкачване общо', true, 1 )
				: array( '1×', 'Един Еверест', tsr_runner_num( $up ) . ' от 8 849 м изкачване', false, $up / 8849 );
			if ( $ev >= 1 ) {
				$badges[] = array( ( $ev + 1 ) . '×', ( $ev + 1 ) . ' Евереста', tsr_runner_num( $up ) . ' от ' . tsr_runner_num( ( $ev + 1 ) * 8849 ) . ' м', false, $up / ( ( $ev + 1 ) * 8849 ) );
			}
		}
		usort( $badges, static fn( array $a, array $b ): int => (int) $b[3] <=> (int) $a[3] );
		$earned = count( array_filter( $badges, static fn( array $b ): bool => $b[3] ) );
		?>
		<section class="tsr-rp-card tsr-rp-span" aria-labelledby="tsr-rp-badges">
			<h2 id="tsr-rp-badges">Постижения</h2>
			<p class="tsr-rp-card__sub"><?php echo esc_html( $earned . ' от ' . count( $badges ) . ' отключени' ); ?></p>
			<ul class="tsr-rp-badges">
				<?php foreach ( $badges as $b ) : ?>
					<li class="tsr-rp-badge<?php echo $b[3] ? '' : ' is-locked'; ?>">
						<div class="tsr-rp-badge__i" aria-hidden="true"><?php echo esc_html( $b[0] ); ?></div>
						<div class="tsr-rp-badge__t">
							<b><?php echo esc_html( $b[1] ); ?></b>
							<span><?php echo esc_html( $b[2] ); ?></span>
							<?php if ( ! $b[3] ) : ?>
								<div class="tsr-rp-bar" role="img" aria-label="<?php echo esc_attr( 'Прогрес ' . (int) round( 100 * $b[4] ) . '%' ); ?>"><i style="width:<?php echo (int) round( 100 * min( 1, $b[4] ) ); ?>%"></i></div>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<?php // ── Personal records ───────────────────────────────────────── ?>
		<?php
		$courses = array();
		foreach ( $clean as $r ) {
			if ( null !== $r['sec'] && $r['km'] > 0 ) {
				$courses[ $r['event'] . '|' . $r['km'] ][] = $r;
			}
		}
		$courses = array_filter( $courses, static fn( array $a ): bool => count( $a ) >= 2 );
		uasort( $courses, static fn( array $a, array $b ): int => array( count( $b ), $b[ count( $b ) - 1 ]['year'] ) <=> array( count( $a ), $a[ count( $a ) - 1 ]['year'] ) );
		?>
		<?php if ( array() !== $courses ) : ?>
			<section class="tsr-rp-card" aria-labelledby="tsr-rp-prs">
				<h2 id="tsr-rp-prs">Лични рекорди</h2>
				<p class="tsr-rp-card__sub">Трасета, бягани поне два пъти (същото състезание и дистанция).</p>
				<ul class="tsr-rp-prs">
					<?php foreach ( array_slice( $courses, 0, 6 ) as $runs ) : ?>
						<?php
						$pr = $runs[0];
						foreach ( $runs as $r ) {
							if ( $r['sec'] < $pr['sec'] ) {
								$pr = $r;
							}
						}
						$la   = $runs[ count( $runs ) - 1 ];
						$secs = array_column( $runs, 'sec' );
						$mn   = min( $secs );
						$mx   = max( $secs );
						$pts  = array();
						foreach ( $runs as $i => $r ) {
							$pts[] = array( round( 6 + 288 * $i / ( count( $runs ) - 1 ), 1 ), round( 6 + 22 * ( $mx === $mn ? 0.5 : ( $r['sec'] - $mn ) / ( $mx - $mn ) ), 1 ), $r === $pr );
						}
						$d = $la['sec'] - $pr['sec'];
						?>
						<li class="tsr-rp-pr">
							<div class="tsr-rp-pr__c"><?php echo esc_html( $pr['event'] . ' · ' . tsr_runner_km( $pr['km'] ) ); ?></div>
							<div class="tsr-rp-pr__t"><?php echo esc_html( tsr_runner_hms( $pr['sec'] ) ); ?></div>
							<div class="tsr-rp-pr__m"><?php echo esc_html( 'рекорд от ' . $pr['year'] . ' · ' . count( $runs ) . ' участия (' . $runs[0]['year'] . '–' . $la['year'] . ')' ); ?></div>
							<div class="tsr-rp-pr__l">
								<?php if ( $la === $pr ) : ?>
									<span class="tsr-rp-tag tsr-rp-tag--ok">рекорд при последното участие</span>
								<?php else : ?>
									<?php echo esc_html( 'последно (' . $la['year'] . '): ' ); ?><b>+<?php echo esc_html( sprintf( '%d:%02d', intdiv( $d, 60 ), $d % 60 ) ); ?></b>
								<?php endif; ?>
							</div>
							<svg class="tsr-rp-spark" viewBox="0 0 300 34" width="100%" height="34" preserveAspectRatio="none" aria-hidden="true">
								<path d="<?php echo esc_attr( implode( '', array_map( static fn( array $p, int $i ): string => ( $i ? 'L' : 'M' ) . $p[0] . ' ' . $p[1], $pts, array_keys( $pts ) ) ) ); ?>" fill="none" stroke="#c5ccd6" stroke-width="2" vector-effect="non-scaling-stroke"/>
								<?php foreach ( $pts as $p ) : ?>
									<circle cx="<?php echo esc_attr( (string) $p[0] ); ?>" cy="<?php echo esc_attr( (string) $p[1] ); ?>" r="3.5" fill="<?php echo $p[2] ? '#16a34a' : '#0a1628'; ?>"/>
								<?php endforeach; ?>
							</svg>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php // ── History ────────────────────────────────────────────────── ?>
		<section class="tsr-rp-card<?php echo array() === $courses ? ' tsr-rp-span' : ''; ?>" aria-labelledby="tsr-rp-hist">
			<h2 id="tsr-rp-hist">История на стартовете</h2>
			<p class="tsr-rp-card__sub">Лентата показва каква част от финиширалите са изпреварени.</p>
			<?php foreach ( array_reverse( $years ) as $yi => $year ) : ?>
				<?php
				$list = array_reverse( array_values( array_filter( $rows, static fn( array $r ): bool => $r['year'] === $year ) ) );
				$ap   = tsr_runner_avg( array_filter( $list, static fn( $r ) => ! $r['odd'] ), 'pct' );
				$ykm  = array_sum( array_map( 'tsr_runner_row_km', array_filter( $list, static fn( $r ) => $r['fin'] ) ) );
				?>
				<details class="tsr-rp-year"<?php echo 0 === $yi ? ' open' : ''; ?>>
					<summary>
						<?php echo $year > 0 ? (int) $year : 'Без година'; ?>
						<span><?php echo esc_html( tsr_runner_count( count( $list ), 'старт', 'старта' ) . ' · ' . round( $ykm ) . ' км' . ( null !== $ap ? ' · среден процентил ' . round( $ap ) . '%' : '' ) ); ?></span>
					</summary>
					<?php foreach ( $list as $r ) : ?>
						<div class="tsr-rp-run">
							<div class="tsr-rp-run__e">
								<a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['event'] ); ?></a>
								<?php if ( $r['pr'] ) : ?><span class="tsr-rp-tag tsr-rp-tag--ok">личен рекорд</span><?php endif; ?>
								<?php if ( $r['odd'] ) : ?><span class="tsr-rp-tag tsr-rp-tag--warn" title="Времето не отговаря на дистанцията в протокола">скъсено трасе?</span><?php endif; ?>
							</div>
							<div class="tsr-rp-run__t"><?php echo esc_html( null !== $r['sec'] ? tsr_runner_hms( $r['sec'] ) : $r['status'] ); ?></div>
							<div class="tsr-rp-run__k"><?php echo esc_html( ( $r['km'] > 0 ? tsr_runner_km( $r['km'] ) : 'дистанция —' ) . ( null !== $r['up'] ? ' · ' . $r['up'] . ' м D+' : '' ) ); ?></div>
							<div class="tsr-rp-run__p"><?php echo esc_html( ( $r['fin'] && null !== $r['place'] ) ? 'място ' . $r['place'] . ' от ' . $r['n'] : '' ); ?></div>
							<?php if ( null !== $r['pct'] ) : ?>
								<div class="tsr-rp-bar" role="img" aria-label="<?php echo esc_attr( 'Пред ' . $r['pct'] . '% от финиширалите' ); ?>"><i style="width:<?php echo (int) max( 2, $r['pct'] ); ?>%"></i></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</details>
			<?php endforeach; ?>
		</section>

	</div><!-- .tsr-rp-grid -->

	<?php
	// Merging / splitting profiles is done by the organisers on request.
	$mail = 'mailto:info@trailseries.bg?subject=' . rawurlencode( 'Профил на бегач: ' . $name ) . '&body=' . rawurlencode( "Здравейте,\n\nпрофилът „{$name}“ в trailseries.bg не е точен:\n\n[ ] липсват мои резултати (под друго изписване на името: ...)\n[ ] в профила има чужди резултати (състезание и година: ...)\n\n" . home_url( '/runner/?runner=' . rawurlencode( $name ) ) );
	?>
	<p class="tsr-rp-report">
		Липсват твои резултати или виждаш чужди? Имената се обединяват автоматично само когато данните са еднозначни.
		<a href="<?php echo esc_url( $mail ); ?>">Пиши ни и ще го оправим</a>.
	</p>
	<?php
}
