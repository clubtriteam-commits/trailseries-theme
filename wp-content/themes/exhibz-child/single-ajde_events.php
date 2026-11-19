<?php
declare( strict_types=1 );
/**
 * Single calendar event (EventON `ajde_events`) — the site's own page.
 *
 * Owner-approved 2026-11-19 (mockup 2026-11-14 + answers): photo hero with
 * season, date, countdown, place and start time, buttons (calendar file,
 * "Как да стигна", last results); sticky section tabs; distances from
 * data/tracks.json; programme (admin field); last edition's winners; history
 * with edition years, totals and course records (KOM / QOM, owner's
 * variant Г). Loaded through the template_include filter in functions.php
 * (priority 100, after EventON's own loader).
 *
 * Times: EventON stores the wall-clock time the organiser typed as a UTC
 * timestamp — always gmdate(), never wp_date().
 *
 * @package exhibz-child
 */

get_header();

while ( have_posts() ) :
	the_post();

	$tsr_id    = get_the_ID();
	$tsr_title = html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' );
	$tsr_race  = tsr_runner_event_name( tsr_event_base_name( $tsr_title ) );
	[ $tsr_start ] = tsr_event_times( $tsr_id );
	$tsr_year  = $tsr_start > 0 ? (int) gmdate( 'Y', $tsr_start ) : (int) ( tsr_title_year( $tsr_title ) ?? 0 );
	$tsr_place = trim( (string) get_post_meta( $tsr_id, 'evcal_location_raw', true ) );
	$tsr_today = strtotime( wp_date( 'Y-m-d', time(), new DateTimeZone( 'Europe/Sofia' ) ) . ' UTC' );
	$tsr_days  = $tsr_start > 0 ? (int) round( ( strtotime( gmdate( 'Y-m-d', $tsr_start ) . ' UTC' ) - $tsr_today ) / DAY_IN_SECONDS ) : null;
	$tsr_dirs  = tsr_event_directions_url( $tsr_id );
	$tsr_prog  = tsr_event_programme( $tsr_id );
	$tsr_desc  = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $tsr_id ) ) );

	// Hero photo: this event's, else the latest photo of the same race.
	$tsr_photo_id = (int) get_post_thumbnail_id( $tsr_id );
	if ( ! $tsr_photo_id ) {
		foreach ( get_posts( array( 'post_type' => 'ajde_events', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'meta_value_num', 'meta_key' => 'evcal_srow', 'order' => 'DESC', 'fields' => 'ids' ) ) as $tsr_other ) { // phpcs:ignore WordPress.DB.SlowDBQuery -- ~20 events.
			if ( get_post_thumbnail_id( $tsr_other ) && tsr_runner_event_name( tsr_event_base_name( html_entity_decode( get_the_title( $tsr_other ), ENT_QUOTES, 'UTF-8' ) ) ) === $tsr_race ) {
				$tsr_photo_id = (int) get_post_thumbnail_id( $tsr_other );
				break;
			}
		}
	}

	// Current tracks of the race, longest first (ADR-004).
	$tsr_events_json = tsr_tracks_events();
	$tsr_tracks      = array();
	foreach ( $tsr_events_json as $tsr_te ) {
		if ( tsr_runner_event_name( (string) ( $tsr_te['name'] ?? '' ) ) === $tsr_race ) {
			foreach ( (array) ( $tsr_te['tracks'] ?? array() ) as $tsr_tr ) {
				if ( 'current' === tsr_track_status( $tsr_tr ) && null !== tsr_track_nominal_km( $tsr_tr ) ) {
					$tsr_tracks[] = $tsr_tr;
				}
			}
		}
	}
	usort( $tsr_tracks, static fn( array $a, array $b ): int => tsr_track_nominal_km( $b ) <=> tsr_track_nominal_km( $a ) );
	$tsr_profiles = $tsr_tracks ? tsr_track_profiles( $tsr_events_json ) : array();
	$tsr_colour   = static function ( array $tr ): string {
		return in_array( $tr['slug'] ?? '', TSR_TRACK_XL_SLUGS, true ) ? 'xl' : tsr_track_cat( tsr_track_nominal_km( $tr ) );
	};
	$tsr_km_label = static fn( array $tr ): string => str_replace( '.', ',', rtrim( rtrim( number_format( (float) tsr_track_nominal_km( $tr ), 1, '.', '' ), '0' ), '.' ) ) . ' км';

	// Results of the race: every edition, and the tables of the last one
	// (up to this event's year) matched to the current tracks (±1 km).
	$tsr_seasons  = array(); // season => event_base
	$tsr_finishes = 0;
	$tsr_by_year  = array(); // season => list of [id, km, gender]
	foreach ( tsr_results_index() as $tsr_rid => $tsr_e ) {
		if ( tsr_runner_event_name( (string) $tsr_e['event_base'] ) !== $tsr_race ) {
			continue;
		}
		$tsr_s                   = (int) $tsr_e['season'];
		$tsr_seasons[ $tsr_s ]   = $tsr_seasons[ $tsr_s ] ?? (string) $tsr_e['event_base'];
		$tsr_by_year[ $tsr_s ][] = array( (int) $tsr_rid, (float) $tsr_e['km'], (string) $tsr_e['gender'] );
	}
	// Finishes over all editions: one JSON_LENGTH query, not every blob.
	$tsr_ids = array();
	foreach ( $tsr_by_year as $tsr_list ) {
		$tsr_ids = array_merge( $tsr_ids, array_column( $tsr_list, 0 ) );
	}
	if ( $tsr_ids ) {
		global $wpdb;
		$tsr_finishes = (int) $wpdb->get_var( "SELECT SUM( JSON_LENGTH( meta_value, '$.rows' ) ) FROM {$wpdb->postmeta} WHERE meta_key = '_tsr_result_set' AND post_id IN (" . implode( ',', array_map( 'intval', $tsr_ids ) ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- integer IDs.
	}
	krsort( $tsr_seasons );
	$tsr_last = 0;
	foreach ( array_keys( $tsr_seasons ) as $tsr_s ) {
		if ( 0 === $tsr_year || $tsr_s <= $tsr_year ) {
			$tsr_last = $tsr_s;
			break;
		}
	}
	$tsr_last_url = $tsr_last ? tsr_event_results_url( $tsr_seasons[ $tsr_last ], $tsr_last ) : '';

	$tsr_records = tsr_track_records();
	$tsr_bad_ids = array();
	foreach ( $tsr_records['dropped'] as $tsr_d ) {
		if ( 'table' === $tsr_d['why'] ) {
			$tsr_bad_ids[ $tsr_d['id'] ] = true;
		}
	}

	// Last edition's winners per current track and gender.
	$tsr_winners = array(); // slug => gender => [name, time|'', finishers, url]
	foreach ( $tsr_by_year[ $tsr_last ] ?? array() as [ $tsr_rid, $tsr_km, $tsr_g ] ) {
		if ( 'M' !== $tsr_g && 'F' !== $tsr_g ) {
			continue;
		}
		$tsr_pick = null;
		foreach ( $tsr_tracks as $tsr_tr ) {
			$tsr_diff = abs( tsr_track_nominal_km( $tsr_tr ) - $tsr_km );
			if ( $tsr_diff <= 1.0 && ( null === $tsr_pick || $tsr_diff < $tsr_pick[1] ) ) {
				$tsr_pick = array( $tsr_tr['slug'], $tsr_diff );
			}
		}
		if ( null === $tsr_pick ) {
			continue;
		}
		$tsr_set  = json_decode( (string) get_post_meta( $tsr_rid, '_tsr_result_set', true ), true );
		$tsr_best = null;
		$tsr_n    = 0;
		foreach ( (array) ( $tsr_set['rows'] ?? array() ) as $tsr_row ) {
			$tsr_sec = tsr_runner_seconds( (string) ( $tsr_row['finish_time'] ?? '' ) );
			if ( null === $tsr_sec || $tsr_sec < 300 ) {
				continue;
			}
			++$tsr_n;
			if ( null === $tsr_best || $tsr_sec < $tsr_best[0] ) {
				$tsr_best = array( $tsr_sec, trim( ( $tsr_row['first_name'] ?? '' ) . ' ' . ( $tsr_row['last_name'] ?? '' ) ) );
			}
		}
		if ( null !== $tsr_best ) {
			$tsr_winners[ $tsr_pick[0] ][ $tsr_g ] = array(
				tsr_runner_canonical( $tsr_best[1] ),
				isset( $tsr_bad_ids[ $tsr_rid ] ) ? '' : tsr_runner_hms( $tsr_best[0] ),
				$tsr_n,
				(string) get_permalink( $tsr_rid ),
			);
		}
	}

	$tsr_runner_url = static fn( string $name ): string => add_query_arg( 'runner', rawurlencode( $name ), home_url( '/runner/' ) );
	$tsr_has_rec    = false;
	foreach ( $tsr_tracks as $tsr_tr ) {
		$tsr_has_rec = $tsr_has_rec || ! empty( $tsr_records['tracks'][ $tsr_tr['slug'] ] );
	}

	// schema.org SportsEvent — wall-clock time in Sofia with its real offset.
	if ( $tsr_start > 0 ) {
		$tsr_tz = new DateTimeZone( 'Europe/Sofia' );
		$tsr_ld = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'SportsEvent',
			'name'                => $tsr_title,
			'startDate'           => ( new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $tsr_start ), $tsr_tz ) )->format( 'c' ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'url'                 => get_permalink(),
			'organizer'           => array( '@type' => 'Organization', 'name' => 'TrailSeries.bg', 'url' => home_url( '/' ) ),
		);
		if ( '' !== $tsr_place ) {
			$tsr_ld['location'] = array( '@type' => 'Place', 'name' => $tsr_place, 'address' => $tsr_place . ', България' );
		}
		if ( $tsr_photo_id ) {
			$tsr_ld['image'] = array( (string) wp_get_attachment_image_url( $tsr_photo_id, 'large' ) );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $tsr_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	$tsr_tabs = array();
	if ( $tsr_tracks ) {
		$tsr_tabs['ev-dist'] = 'Дистанции';
	}
	if ( $tsr_prog ) {
		$tsr_tabs['ev-prog'] = 'Програма';
	}
	if ( $tsr_winners ) {
		$tsr_tabs['ev-res'] = 'Резултати';
	}
	if ( $tsr_seasons || $tsr_has_rec ) {
		$tsr_tabs['ev-hist'] = 'История и рекорди';
	}
	?>

<div class="tsr-ev">
	<section class="tsr-ev__hero<?php echo $tsr_photo_id ? ' tsr-ev__hero--photo' : ''; ?>">
		<?php
		if ( $tsr_photo_id ) {
			echo wp_get_attachment_image( $tsr_photo_id, 'full', false, array( 'class' => 'tsr-ev__hero-img', 'alt' => '', 'sizes' => '100vw', 'loading' => 'eager', 'fetchpriority' => 'high' ) );
		}
		?>
		<div class="tsr-container">
			<p class="tsr-ev__kicker">Trail Series<?php echo $tsr_year ? ' · ' . esc_html( tsr_season_label( $tsr_year ) ) : ''; ?></p>
			<?php if ( $tsr_start > 0 ) : ?>
				<div class="tsr-ev__pills">
					<time class="tsr-ev__date" datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $tsr_start ) ); ?>"><b><?php echo esc_html( gmdate( 'j', $tsr_start ) ); ?></b> <?php echo esc_html( tsr_bg_month_short( (int) gmdate( 'n', $tsr_start ) ) . ' ' . gmdate( 'Y', $tsr_start ) ); ?></time>
					<span class="tsr-ev__in<?php echo $tsr_days < 0 ? ' tsr-ev__in--past' : ''; ?>"><?php echo esc_html( $tsr_days < 0 ? 'преминало' : tsr_in_days( (int) $tsr_days ) ); ?></span>
				</div>
			<?php endif; ?>
			<h1 class="tsr-ev__title"><?php echo esc_html( $tsr_title ); ?></h1>
			<?php if ( '' !== $tsr_place || $tsr_start > 0 ) : ?>
				<p class="tsr-ev__where">
					<?php if ( '' !== $tsr_place ) : ?><b><?php echo esc_html( $tsr_place ); ?></b><?php endif; ?>
					<?php echo ( '' !== $tsr_place && $tsr_start > 0 ) ? ' · ' : ''; ?>
					<?php echo $tsr_start > 0 ? 'старт ' . esc_html( gmdate( 'H:i', $tsr_start ) ) : ''; ?>
				</p>
			<?php endif; ?>
			<div class="tsr-ev__cta">
				<?php if ( $tsr_start > 0 && $tsr_days >= 0 ) : ?>
					<a class="tsr-ev__btn tsr-ev__btn--main" href="<?php echo esc_url( add_query_arg( 'ics', '1', get_permalink() ) ); ?>" rel="nofollow">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2v3M17 2v3M3.5 9h17M5 4.5h14A1.5 1.5 0 0 1 20.5 6v13A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V6A1.5 1.5 0 0 1 5 4.5Zm7 8v5m-2.5-2.5h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
						Добави в календара
					</a>
				<?php endif; ?>
				<?php if ( '' !== $tsr_dirs ) : ?>
					<a class="tsr-ev__btn" href="<?php echo esc_url( $tsr_dirs ); ?>" target="_blank" rel="noopener">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Zm0-8.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
						Как да стигна<span class="screen-reader-text"> (нов прозорец)</span>
					</a>
				<?php endif; ?>
				<?php if ( '' !== $tsr_last_url ) : ?>
					<a class="tsr-ev__btn" href="<?php echo esc_url( $tsr_last_url ); ?>">Резултати <?php echo esc_html( (string) $tsr_last ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php if ( count( $tsr_tabs ) > 1 ) : ?>
		<nav class="tsr-ev__tabs" aria-label="Раздели на страницата">
			<ul class="tsr-container">
				<?php foreach ( $tsr_tabs as $tsr_anchor => $tsr_label ) : ?>
					<li><a href="#<?php echo esc_attr( $tsr_anchor ); ?>"><?php echo esc_html( $tsr_label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<script>
		(function () {
			var nav = document.currentScript.previousElementSibling, head = document.getElementById( 'masthead' );
			var links = [].slice.call( nav.querySelectorAll( 'a' ) );
			var place = function () {
				var h = head ? Math.round( head.getBoundingClientRect().height ) : 0;
				nav.style.top = h + 'px';
				document.documentElement.style.setProperty( '--tsr-ev-offset', ( h + nav.offsetHeight + 12 ) + 'px' );
			};
			window.addEventListener( 'scroll', place, { passive: true } );
			window.addEventListener( 'resize', place );
			place();
			if ( ! ( 'IntersectionObserver' in window ) ) { return; }
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( e ) {
					if ( ! e.isIntersecting ) { return; }
					links.forEach( function ( a ) {
						var on = a.getAttribute( 'href' ) === '#' + e.target.id;
						a.classList.toggle( 'is-on', on );
						if ( on ) { a.setAttribute( 'aria-current', 'true' ); } else { a.removeAttribute( 'aria-current' ); }
					} );
				} );
			}, { rootMargin: '-40% 0px -55% 0px' } );
			links.forEach( function ( a ) { var s = document.getElementById( a.getAttribute( 'href' ).slice( 1 ) ); if ( s ) { io.observe( s ); } } );
		}());
		</script>
	<?php endif; ?>

	<main id="main" class="tsr-ev__main">
		<div class="tsr-container">
			<?php tsr_page_breadcrumbs( $tsr_title, array( array( 'label' => 'Календар', 'url' => home_url( '/calendar/' ) ) ) ); ?>

			<?php if ( '' !== $tsr_desc ) : ?>
				<div class="tsr-ev__desc tsr-single-content">
					<?php echo wp_kses_post( do_blocks( (string) get_post_field( 'post_content', $tsr_id ) ) ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $tsr_tracks ) : ?>
				<section class="tsr-ev__sec" id="ev-dist" aria-labelledby="ev-dist-h">
					<h2 class="tsr-ev__h2" id="ev-dist-h">Дистанции</h2>
					<p class="tsr-ev__sub">Измерени по GPX трасетата.</p>
					<div class="tsr-ev__grid">
						<?php foreach ( $tsr_tracks as $tsr_tr ) : ?>
							<?php
							$tsr_c    = $tsr_colour( $tsr_tr );
							$tsr_laps = tsr_track_laps( $tsr_tr );
							$tsr_prof = $tsr_profiles[ (string) ( $tsr_tr['points_file'] ?? '' ) ] ?? array();
							$tsr_catn = array( 'short' => 'къса', 'medium' => 'средна', 'long' => 'дълга', 'xl' => 'дълга' )[ $tsr_c ] ?? '';
							?>
							<article class="tsr-ev__d tsr-ev--<?php echo esc_attr( $tsr_c ); ?>">
								<div class="tsr-ev__d-head">
									<h3 class="tsr-ev__d-km"><?php echo esc_html( $tsr_km_label( $tsr_tr ) ); ?></h3>
									<?php if ( '' !== $tsr_catn ) : ?><span class="tsr-ev__cat"><?php echo esc_html( $tsr_catn ); ?></span><?php endif; ?>
								</div>
								<dl class="tsr-ev__d-stats">
									<div><dt>По GPX</dt><dd><?php echo esc_html( number_format_i18n( (float) $tsr_tr['distance_km'], 1 ) ); ?> км</dd></div>
									<?php if ( isset( $tsr_tr['ascent_m'] ) ) : ?><div><dt>Изкачване</dt><dd><?php echo esc_html( number_format_i18n( (int) $tsr_tr['ascent_m'] ) ); ?> м</dd></div><?php endif; ?>
									<?php if ( isset( $tsr_tr['highest_m'] ) ) : ?><div><dt>Най-висока</dt><dd><?php echo esc_html( number_format_i18n( (int) $tsr_tr['highest_m'] ) ); ?> м</dd></div><?php endif; ?>
								</dl>
								<?php echo tsr_track_spark( $tsr_prof ); // phpcs:ignore WordPress.Security.EscapeOutput -- numeric SVG. ?>
								<p class="tsr-ev__d-foot">
									<?php if ( $tsr_laps > 1 ) : ?><span class="tsr-ev__laps"><?php echo esc_html( $tsr_laps . ' обиколки' ); ?></span><?php endif; ?>
									<a class="tsr-ev__link" href="<?php echo esc_url( home_url( '/traseta/#t-' . $tsr_tr['slug'] ) ); ?>">Карта и профил<span class="screen-reader-text"> — <?php echo esc_html( $tsr_km_label( $tsr_tr ) ); ?></span> ›</a>
								</p>
							</article>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $tsr_prog ) : ?>
				<section class="tsr-ev__sec" id="ev-prog" aria-labelledby="ev-prog-h">
					<h2 class="tsr-ev__h2" id="ev-prog-h">Програма</h2>
					<ol class="tsr-ev__prog">
						<?php foreach ( $tsr_prog as [ $tsr_t, $tsr_txt ] ) : ?>
							<li><span class="tsr-ev__prog-t"><?php echo esc_html( $tsr_t ); ?></span><span><?php echo esc_html( $tsr_txt ); ?></span></li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

			<?php if ( $tsr_winners ) : ?>
				<section class="tsr-ev__sec" id="ev-res" aria-labelledby="ev-res-h">
					<h2 class="tsr-ev__h2" id="ev-res-h"><?php echo $tsr_last === $tsr_year ? 'Резултати' : 'Резултати от миналото издание'; ?></h2>
					<p class="tsr-ev__sub"><?php echo esc_html( $tsr_race . ' ' . $tsr_last ); ?> — победители по дистанции</p>
					<div class="tsr-ev__grid">
						<?php foreach ( $tsr_tracks as $tsr_tr ) : ?>
							<?php
							$tsr_w = $tsr_winners[ $tsr_tr['slug'] ] ?? null;
							if ( null === $tsr_w ) {
								continue;
							}
							$tsr_total = 0;
							$tsr_link  = '';
							?>
							<article class="tsr-ev__r tsr-ev--<?php echo esc_attr( $tsr_colour( $tsr_tr ) ); ?>">
								<h3 class="tsr-ev__r-h"><?php echo esc_html( $tsr_km_label( $tsr_tr ) ); ?></h3>
								<ul>
									<?php foreach ( array( 'M' => 'Мъже', 'F' => 'Жени' ) as $tsr_g => $tsr_gl ) : ?>
										<?php
										if ( empty( $tsr_w[ $tsr_g ] ) ) {
											continue;
										}
										[ $tsr_name, $tsr_time, $tsr_n, $tsr_url ] = $tsr_w[ $tsr_g ];
										$tsr_total += $tsr_n;
										$tsr_link   = '' !== $tsr_link ? $tsr_link : $tsr_url;
										?>
										<li>
											<span class="tsr-ev__r-g"><?php echo esc_html( $tsr_gl ); ?></span>
											<a href="<?php echo esc_url( $tsr_runner_url( $tsr_name ) ); ?>"><?php echo esc_html( $tsr_name ); ?></a>
											<span class="tsr-ev__r-t"><?php echo esc_html( '' !== $tsr_time ? $tsr_time : '—' ); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="tsr-ev__r-foot"><span><?php echo esc_html( $tsr_total . ' финиширали' ); ?></span> <a class="tsr-ev__link" href="<?php echo esc_url( $tsr_link ); ?>">Пълни резултати ›</a></p>
							</article>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $tsr_seasons || $tsr_has_rec ) : ?>
				<section class="tsr-ev__sec" id="ev-hist" aria-labelledby="ev-hist-h">
					<h2 class="tsr-ev__h2" id="ev-hist-h">История и рекорди</h2>

					<?php if ( $tsr_has_rec ) : ?>
						<h3 class="tsr-ev__h3">Рекорди на трасетата</h3>
						<p class="tsr-ev__sub">Най-бързите времена на сегашните трасета на <?php echo esc_html( $tsr_race ); ?></p>
						<div class="tsr-ev__grid">
							<?php foreach ( $tsr_tracks as $tsr_tr ) : ?>
								<?php
								$tsr_rec = $tsr_records['tracks'][ $tsr_tr['slug'] ] ?? array();
								if ( ! $tsr_rec ) {
									continue;
								}
								$tsr_from = tsr_track_records_from( $tsr_tr );
								$tsr_laps = tsr_track_laps( $tsr_tr );
								$tsr_eff  = (float) $tsr_tr['distance_km'];
								?>
								<article class="tsr-ev__rec tsr-ev--<?php echo esc_attr( $tsr_colour( $tsr_tr ) ); ?>">
									<div class="tsr-ev__rec-head">
										<div>
											<h4 class="tsr-ev__d-km"><?php echo esc_html( $tsr_km_label( $tsr_tr ) ); ?></h4>
											<?php if ( $tsr_from > 0 ) : ?><span class="tsr-ev__since"><?php echo esc_html( 'трасе от ' . $tsr_from ); ?></span><?php endif; ?>
										</div>
										<p class="tsr-ev__rec-meta">
											<?php echo esc_html( $tsr_laps . ' ' . ( 1 === $tsr_laps ? 'обиколка' : 'обиколки' ) ); ?><br>
											+<?php echo esc_html( number_format_i18n( (int) ( $tsr_tr['ascent_m'] ?? 0 ) ) ); ?> м
										</p>
									</div>
									<?php foreach ( array( 'M' => 'KOM', 'F' => 'QOM' ) as $tsr_g => $tsr_badge ) : ?>
										<?php
										if ( empty( $tsr_rec[ $tsr_g ] ) ) {
											continue;
										}
										$tsr_r    = $tsr_rec[ $tsr_g ];
										$tsr_pace = (int) round( $tsr_r['s'] / $tsr_eff );
										?>
										<div class="tsr-ev__rec-row">
											<span class="tsr-ev__kom" title="<?php echo esc_attr( 'M' === $tsr_g ? 'King of the Mountain — рекорд при мъжете' : 'Queen of the Mountain — рекорд при жените' ); ?>">
												<svg viewBox="0 0 24 18" aria-hidden="true" focusable="false"><path d="M2 5l5 4 5-8 5 8 5-4-2 11H4z" fill="currentColor"/><circle cx="2" cy="4" r="1.8" fill="currentColor"/><circle cx="12" cy="1.8" r="1.8" fill="currentColor"/><circle cx="22" cy="4" r="1.8" fill="currentColor"/></svg>
												<b><?php echo esc_html( $tsr_badge ); ?></b>
											</span>
											<a class="tsr-ev__rec-name" href="<?php echo esc_url( $tsr_runner_url( $tsr_r['name'] ) ); ?>"><?php echo esc_html( $tsr_r['name'] ); ?></a>
											<span class="tsr-ev__rec-time"><?php echo esc_html( tsr_runner_hms( $tsr_r['s'] ) ); ?></span>
											<span class="tsr-ev__rec-sm"><?php echo esc_html( (string) $tsr_r['year'] ); ?></span>
											<span class="tsr-ev__rec-sm tsr-ev__rec-sm--r"><?php echo esc_html( sprintf( '%d:%02d мин/км', intdiv( $tsr_pace, 60 ), $tsr_pace % 60 ) ); ?></span>
										</div>
									<?php endforeach; ?>
								</article>
							<?php endforeach; ?>
						</div>
						<p class="tsr-ev__note">Рекордите се смятат автоматично от резултатите — само за сегашните трасета. Невъзможни времена (грешка в класирането) не се показват.</p>
					<?php endif; ?>

					<?php if ( $tsr_seasons ) : ?>
						<div class="tsr-ev__hist">
							<div>
								<h3 class="tsr-ev__h3">Издания</h3>
								<ul class="tsr-ev__years">
									<?php foreach ( $tsr_seasons as $tsr_s => $tsr_base ) : ?>
										<?php $tsr_u = tsr_event_results_url( $tsr_base, $tsr_s ); ?>
										<li><?php if ( '' !== $tsr_u ) : ?><a href="<?php echo esc_url( $tsr_u ); ?>"><?php echo esc_html( (string) $tsr_s ); ?></a><?php else : ?><span><?php echo esc_html( (string) $tsr_s ); ?></span><?php endif; ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
							<div class="tsr-ev__totals">
								<div><b><?php echo esc_html( (string) count( $tsr_seasons ) ); ?></b><span>издания от <?php echo esc_html( (string) min( array_keys( $tsr_seasons ) ) ); ?> г.</span></div>
								<div><b><?php echo esc_html( number_format_i18n( $tsr_finishes ) ); ?></b><span>финиша общо</span></div>
								<a class="tsr-ev__link" href="<?php echo esc_url( add_query_arg( 'sabitie', sanitize_title( $tsr_race ), home_url( '/event/' ) ) ); ?>">Цялата история на състезанието ›</a>
							</div>
						</div>
					<?php endif; ?>
				</section>
			<?php endif; ?>
		</div>
	</main>
</div>

	<?php
endwhile;

get_footer();
