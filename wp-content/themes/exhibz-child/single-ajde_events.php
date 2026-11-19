<?php
declare( strict_types=1 );
/**
 * Single calendar event (EventON `ajde_events`) — the site's own page.
 *
 * Owner-approved 2026-11-19 (mockup v3): photo hero (season, date, countdown,
 * place, start; registration / calendar file / directions / last results);
 * sticky section tabs; left column: about (description, "със съдействието
 * на", highlight cards), distances from data/tracks.json, programme, fees and
 * registration, getting there; right sidebar: key facts and the season's
 * cause block (Tools → Кауза на сезона); then course records (KOM / QOM,
 * owner's variant Г) and the event's partners. Owner content comes from the
 * admin box "Страница на събитието" (functions.php). Loaded through the
 * template_include filter in functions.php (priority 100, after EventON).
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

	// Latest edition with results (up to this event's year) for the hero button.
	$tsr_seasons = array(); // season => event_base
	foreach ( tsr_results_index() as $tsr_e ) {
		if ( tsr_runner_event_name( (string) $tsr_e['event_base'] ) === $tsr_race ) {
			$tsr_seasons[ (int) $tsr_e['season'] ] = $tsr_seasons[ (int) $tsr_e['season'] ] ?? (string) $tsr_e['event_base'];
		}
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
	$tsr_records  = tsr_track_records();

	// Owner-edited content (admin box "Страница на събитието").
	$tsr_hl       = tsr_event_lines( $tsr_id, '_tsr_highlights' );
	$tsr_fees     = tsr_event_lines( $tsr_id, '_tsr_fees' );
	$tsr_go       = tsr_event_lines( $tsr_id, '_tsr_getting_there' );
	$tsr_reg      = (string) get_post_meta( $tsr_id, '_tsr_reg_url', true );
	$tsr_kids     = (string) get_post_meta( $tsr_id, '_tsr_kids', true );
	$tsr_surface  = (string) get_post_meta( $tsr_id, '_tsr_surface', true );
	$tsr_support  = (string) get_post_meta( $tsr_id, '_tsr_support', true );
	$tsr_sphoto   = (string) get_post_meta( $tsr_id, '_tsr_start_photo', true );
	$tsr_partners = tsr_event_partners( $tsr_id );
	$tsr_cause    = tsr_cause();
	$tsr_cause_on = $tsr_cause['on'] && '' !== $tsr_cause['title'] && '1' !== get_post_meta( $tsr_id, '_tsr_hide_cause', true );

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
	if ( '' !== $tsr_desc || $tsr_hl || '' !== $tsr_support ) {
		$tsr_tabs['ev-about'] = 'За състезанието';
	}
	if ( $tsr_tracks ) {
		$tsr_tabs['ev-dist'] = 'Дистанции';
	}
	if ( $tsr_prog ) {
		$tsr_tabs['ev-prog'] = 'Програма';
	}
	if ( $tsr_fees || '' !== $tsr_reg ) {
		$tsr_tabs['ev-fee'] = 'Такса и регистрация';
	}
	if ( $tsr_go || '' !== $tsr_sphoto ) {
		$tsr_tabs['ev-go'] = 'Как да стигна';
	}
	if ( $tsr_has_rec ) {
		$tsr_tabs['ev-rec'] = 'Рекорди';
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
				<?php if ( '' !== $tsr_reg && $tsr_days >= 0 ) : ?>
					<a class="tsr-ev__btn tsr-ev__btn--main" href="<?php echo esc_url( $tsr_reg ); ?>" target="_blank" rel="noopener">Регистрация<span class="screen-reader-text"> (нов прозорец)</span></a>
				<?php endif; ?>
				<?php if ( $tsr_start > 0 && $tsr_days >= 0 ) : ?>
					<a class="tsr-ev__btn<?php echo '' === $tsr_reg ? ' tsr-ev__btn--main' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'ics', '1', get_permalink() ) ); ?>" rel="nofollow">
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

			<div class="tsr-ev__layout">
				<div class="tsr-ev__body">

					<?php if ( '' !== $tsr_desc || $tsr_hl || '' !== $tsr_support ) : ?>
						<section class="tsr-ev__sec" id="ev-about" aria-labelledby="ev-about-h">
							<h2 class="tsr-ev__h2" id="ev-about-h">За състезанието</h2>
							<?php if ( '' !== $tsr_desc ) : ?>
								<div class="tsr-ev__desc tsr-single-content">
									<?php echo wp_kses_post( do_blocks( (string) get_post_field( 'post_content', $tsr_id ) ) ); ?>
								</div>
							<?php endif; ?>
							<?php if ( '' !== $tsr_support ) : ?>
								<p class="tsr-ev__with">Със съдействието на <b><?php echo esc_html( $tsr_support ); ?></b></p>
							<?php endif; ?>
							<?php if ( $tsr_hl ) : ?>
								<div class="tsr-ev__hl">
									<?php foreach ( $tsr_hl as $tsr_h ) : ?>
										<?php [ $tsr_ic, $tsr_ht, $tsr_hx ] = array_pad( count( $tsr_h ) >= 3 ? $tsr_h : array_merge( array( '' ), $tsr_h ), 3, '' ); ?>
										<article>
											<?php if ( '' !== $tsr_ic ) : ?><span class="tsr-ev__hl-ic" aria-hidden="true"><?php echo esc_html( $tsr_ic ); ?></span><?php endif; ?>
											<h3><?php echo esc_html( $tsr_ht ); ?></h3>
											<?php if ( '' !== $tsr_hx ) : ?><p><?php echo esc_html( $tsr_hx ); ?></p><?php endif; ?>
										</article>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</section>
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
											<?php if ( isset( $tsr_tr['highest_m'] ) ) : ?><div><dt>Връх</dt><dd><?php echo esc_html( number_format_i18n( (int) $tsr_tr['highest_m'] ) ); ?> м</dd></div><?php endif; ?>
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

					<?php if ( $tsr_fees || '' !== $tsr_reg ) : ?>
						<section class="tsr-ev__sec" id="ev-fee" aria-labelledby="ev-fee-h">
							<h2 class="tsr-ev__h2" id="ev-fee-h">Такса и регистрация</h2>
							<?php if ( $tsr_fees ) : ?>
								<div class="tsr-ev__fees">
									<?php foreach ( $tsr_fees as $tsr_i => $tsr_f ) : ?>
										<?php [ $tsr_amt, $tsr_ft, $tsr_fn ] = array_pad( $tsr_f, 3, '' ); ?>
										<article class="<?php echo 0 === $tsr_i ? 'is-main' : ''; ?>">
											<div class="tsr-ev__fee-amt"><?php echo esc_html( $tsr_amt ); ?></div>
											<?php if ( '' !== $tsr_ft ) : ?><h3><?php echo esc_html( $tsr_ft ); ?></h3><?php endif; ?>
											<?php if ( '' !== $tsr_fn ) : ?><p><?php echo esc_html( $tsr_fn ); ?></p><?php endif; ?>
										</article>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<?php if ( '' !== $tsr_reg ) : ?>
								<a class="tsr-ev__reg" href="<?php echo esc_url( $tsr_reg ); ?>" target="_blank" rel="noopener">Регистрация<span class="screen-reader-text"> (нов прозорец)</span> ›</a>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( $tsr_go || '' !== $tsr_sphoto ) : ?>
						<section class="tsr-ev__sec" id="ev-go" aria-labelledby="ev-go-h">
							<h2 class="tsr-ev__h2" id="ev-go-h">Как да стигна</h2>
							<div class="tsr-ev__go<?php echo '' !== $tsr_sphoto ? ' tsr-ev__go--photo' : ''; ?>">
								<div>
									<?php if ( $tsr_go ) : ?>
										<ul>
											<?php foreach ( $tsr_go as $tsr_g ) : ?>
												<?php [ $tsr_ic, $tsr_gt, $tsr_gx ] = array_pad( count( $tsr_g ) >= 3 ? $tsr_g : array_merge( array( '' ), $tsr_g ), 3, '' ); ?>
												<li><span class="tsr-ev__go-ic" aria-hidden="true"><?php echo esc_html( $tsr_ic ); ?></span><div><b><?php echo esc_html( $tsr_gt ); ?></b><?php if ( '' !== $tsr_gx ) : ?><span><?php echo esc_html( $tsr_gx ); ?></span><?php endif; ?></div></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
									<?php if ( '' !== $tsr_dirs ) : ?>
										<a class="tsr-ev__btn2" href="<?php echo esc_url( $tsr_dirs ); ?>" target="_blank" rel="noopener">Отвори в Google Maps<span class="screen-reader-text"> (нов прозорец)</span> ›</a>
									<?php endif; ?>
								</div>
								<?php if ( '' !== $tsr_sphoto ) : ?>
									<figure><img src="<?php echo esc_url( $tsr_sphoto ); ?>" alt="Мястото на старта" loading="lazy" decoding="async"><figcaption>Мястото на старта</figcaption></figure>
								<?php endif; ?>
							</div>
						</section>
					<?php endif; ?>
				</div>

				<aside class="tsr-ev__side" aria-label="Накратко">
					<div class="tsr-ev__card">
						<h2 class="tsr-ev__side-h">Накратко</h2>
						<dl class="tsr-ev__facts">
							<?php if ( $tsr_start > 0 ) : ?>
								<div><dt>Дата</dt><dd><?php echo esc_html( gmdate( 'j', $tsr_start ) . ' ' . tsr_bg_month_short( (int) gmdate( 'n', $tsr_start ) ) . ', ' . array( 'неделя', 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота' )[ (int) gmdate( 'w', $tsr_start ) ] ); ?></dd></div>
								<div><dt>Старт</dt><dd><?php echo esc_html( gmdate( 'H:i', $tsr_start ) ); ?></dd></div>
							<?php endif; ?>
							<?php if ( '' !== $tsr_place ) : ?><div><dt>Място</dt><dd><?php echo esc_html( $tsr_place ); ?></dd></div><?php endif; ?>
							<?php if ( $tsr_tracks ) : ?><div><dt>Дистанции</dt><dd><?php echo esc_html( implode( ' · ', array_map( $tsr_km_label, $tsr_tracks ) ) ); ?></dd></div><?php endif; ?>
							<?php if ( '' !== $tsr_surface ) : ?><div><dt>Настилка</dt><dd><?php echo esc_html( $tsr_surface ); ?></dd></div><?php endif; ?>
							<?php if ( '' !== $tsr_kids ) : ?><div><dt>Детско бягане</dt><dd><?php echo esc_html( $tsr_kids ); ?></dd></div><?php endif; ?>
						</dl>
						<?php if ( '' !== $tsr_reg && $tsr_days >= 0 ) : ?>
							<a class="tsr-ev__reg tsr-ev__reg--block" href="<?php echo esc_url( $tsr_reg ); ?>" target="_blank" rel="noopener">Регистрация<span class="screen-reader-text"> (нов прозорец)</span></a>
						<?php endif; ?>
						<?php if ( $tsr_start > 0 && $tsr_days >= 0 ) : ?>
							<a class="tsr-ev__side-link" href="<?php echo esc_url( add_query_arg( 'ics', '1', get_permalink() ) ); ?>" rel="nofollow">Добави в календара</a>
						<?php endif; ?>
					</div>
					<?php if ( $tsr_cause_on ) : ?>
						<div class="tsr-ev__card tsr-ev__cause">
							<?php if ( '' !== $tsr_cause['image'] ) : ?><img src="<?php echo esc_url( $tsr_cause['image'] ); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
							<div class="tsr-ev__cause-in">
								<?php if ( '' !== $tsr_cause['kicker'] ) : ?><span class="tsr-ev__cause-k"><?php echo esc_html( $tsr_cause['kicker'] ); ?></span><?php endif; ?>
								<h2 class="tsr-ev__side-h"><?php echo esc_html( $tsr_cause['title'] ); ?></h2>
								<?php if ( '' !== $tsr_cause['text'] ) : ?><p><?php echo esc_html( $tsr_cause['text'] ); ?></p><?php endif; ?>
								<?php foreach ( $tsr_cause['links'] as [ $tsr_lt, $tsr_lu ] ) : ?>
									<a href="<?php echo esc_url( $tsr_lu ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $tsr_lt ); ?> ›</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</aside>
			</div>

			<?php if ( $tsr_has_rec ) : ?>
				<section class="tsr-ev__sec" id="ev-rec" aria-labelledby="ev-rec-h">
					<h2 class="tsr-ev__h2" id="ev-rec-h">Рекорди на трасетата</h2>
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
										<h3 class="tsr-ev__d-km"><?php echo esc_html( $tsr_km_label( $tsr_tr ) ); ?></h3>
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
					<p class="tsr-ev__note">Рекордите се смятат автоматично от резултатите — само за сегашните трасета. Невъзможни времена (грешка в класирането) не се показват. <a class="tsr-ev__link" href="<?php echo esc_url( add_query_arg( 'sabitie', sanitize_title( $tsr_race ), home_url( '/event/' ) ) ); ?>">Цялата история на състезанието ›</a></p>
				</section>
			<?php endif; ?>

			<?php if ( $tsr_partners ) : ?>
				<section class="tsr-ev__partners" aria-labelledby="ev-partners-h">
					<h2 class="tsr-ev__h2" id="ev-partners-h">Партньори</h2>
					<ul class="tsr-ev__logos">
						<?php foreach ( $tsr_partners as $tsr_p ) : ?>
							<?php
							$tsr_pu   = (string) get_post_meta( $tsr_p->ID, '_tsr_partner_url', true );
							$tsr_pimg = get_the_post_thumbnail( $tsr_p, 'medium', array( 'alt' => get_the_title( $tsr_p ), 'loading' => 'lazy', 'decoding' => 'async' ) );
							$tsr_pname = get_the_title( $tsr_p );
							$tsr_inner = '' !== $tsr_pimg ? $tsr_pimg : '<span>' . esc_html( $tsr_pname ) . '</span>';
							?>
							<li>
								<?php if ( '' !== $tsr_pu ) : ?>
									<a href="<?php echo esc_url( $tsr_pu ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $tsr_pname ); ?>"><?php echo $tsr_inner; // phpcs:ignore WordPress.Security.EscapeOutput -- core image markup / escaped name. ?></a>
								<?php else : ?>
									<span class="tsr-ev__logo"><?php echo $tsr_inner; // phpcs:ignore WordPress.Security.EscapeOutput -- core image markup / escaped name. ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
	</main>
</div>

	<?php
endwhile;

get_footer();
