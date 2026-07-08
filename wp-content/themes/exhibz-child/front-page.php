<?php
/**
 * Homepage template — TrailSeries.bg.
 *
 * Sections (in order):
 *   1. Hero with countdown to the 15th anniversary (1 October 2027)
 *   2. Upcoming events — next 3 from EventON plugin (post type ajde_events)
 *   3. Past events   — last 5 published ts_result posts with links
 *   4. Latest news   — last 3 standard WP posts
 *   5. Quick stats   — seasons (hardcoded 14), total finishers, total races
 *
 * Everything here is display-only. No results logic lives in the theme.
 *
 * @package exhibz-child
 */

declare( strict_types=1 );

// ── Data collection (before any HTML output) ──────────────────────────────────

// 1. Upcoming events via EventON (graceful fallback when plugin inactive).
$tsr_upcoming = array();
if ( post_type_exists( 'ajde_events' ) ) {
	$tsr_eq = new WP_Query(
		array(
			'post_type'      => 'ajde_events',
			'posts_per_page' => 3,
			'post_status'    => 'publish',
			'meta_key'       => 'evcal_srow',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => 'evcal_srow',
					'value'   => time(),
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
			'no_found_rows'  => true,
		)
	);
	while ( $tsr_eq->have_posts() ) {
		$tsr_eq->the_post();
		$tsr_id         = get_the_ID();
		$tsr_upcoming[] = array(
			'title'         => get_the_title(),
			'url'           => get_permalink(),
			'start_ts'      => (int) get_post_meta( $tsr_id, 'evcal_srow', true ),
			'time_ts'       => (int) get_post_meta( $tsr_id, '_evcal_etime', true ),
			'location'      => (string) ( get_post_meta( $tsr_id, 'evcal_location_raw', true ) ?: '' ),
			'thumbnail_url' => (string) ( get_the_post_thumbnail_url( $tsr_id, 'large' ) ?: '' ),
		);
	}
	wp_reset_postdata();
}

// 2. Last 5 ts_result posts.
$tsr_past_results = get_posts(
	array(
		'post_type'   => 'ts_result',
		'numberposts' => 5,
		'post_status' => 'publish',
		'orderby'     => 'date',
		'order'       => 'DESC',
	)
);

// 3. Last 3 news posts — exclude Zero to HERO category.
$tsr_zero_cat    = get_category_by_slug( 'zero-to-hero' );
$tsr_zero_cat_id = $tsr_zero_cat ? (int) $tsr_zero_cat->term_id : 0;

$tsr_news = get_posts(
	array(
		'post_type'        => 'post',
		'numberposts'      => 3,
		'post_status'      => 'publish',
		'orderby'          => 'date',
		'order'            => 'DESC',
		'category__not_in' => $tsr_zero_cat_id ? array( $tsr_zero_cat_id ) : array(),
	)
);

// 4. Zero to HERO stories — all posts from the "zero-to-hero" category.
$tsr_zero = get_posts(
	array(
		'post_type'     => 'post',
		'numberposts'   => -1,
		'post_status'   => 'publish',
		'category_name' => 'zero-to-hero',
		'orderby'       => 'date',
		'order'         => 'DESC',
		'no_found_rows' => true,
	)
);

// 4. Stats.
$tsr_total_races     = tsr_homepage_total_races();
$tsr_total_finishers = tsr_homepage_total_finishers();

get_header();
?>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 1 — HERO + COUNTDOWN
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-hero" aria-label="Начало">
	<div class="tsr-hero__inner">
		<p class="tsr-hero__eyebrow">Серия планинско бягане &middot; България</p>

		<h1 class="tsr-hero__title">Trail<span>Series</span>.bg</h1>

		<p class="tsr-hero__subtitle">14 сезона по планините на България</p>

		<div class="tsr-countdown"
		     id="tsrCountdown"
		     aria-live="off"
		     aria-label="Обратно броене до 15-ия юбилей">
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrDays">--</span>
				<span class="tsr-countdown__label">дни</span>
			</div>
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrHours">--</span>
				<span class="tsr-countdown__label">часа</span>
			</div>
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrMins">--</span>
				<span class="tsr-countdown__label">минути</span>
			</div>
			<div class="tsr-countdown__block">
				<span class="tsr-countdown__num" id="tsrSecs">--</span>
				<span class="tsr-countdown__label">секунди</span>
			</div>
		</div>

		<p class="tsr-hero__anniversary">до 15-ия юбилей &mdash; октомври 2027</p>
	</div>
</section>

<script>
/* global clearInterval */
(function () {
	'use strict';

	// Target: 1 October 2027, midnight (browser local time).
	// Adjust the ISO string to a specific hour if a precise Sofia-time
	// midnight is needed: '2027-10-01T00:00:00+03:00'.
	var TARGET = new Date('2027-10-01T00:00:00');

	var wrap  = document.getElementById('tsrCountdown');
	var elD   = document.getElementById('tsrDays');
	var elH   = document.getElementById('tsrHours');
	var elM   = document.getElementById('tsrMins');
	var elS   = document.getElementById('tsrSecs');

	function pad(n) {
		return String(n).padStart(2, '0');
	}

	function tick() {
		var diff = TARGET.getTime() - Date.now();

		if (diff <= 0) {
			wrap.innerHTML = '<p class="tsr-countdown__done">15 години TrailSeries.bg!</p>';
			clearInterval(timer); // eslint-disable-line no-use-before-define
			return;
		}

		var totalSec = Math.floor(diff / 1000);
		var d = Math.floor(totalSec / 86400);
		var h = Math.floor((totalSec % 86400) / 3600);
		var m = Math.floor((totalSec % 3600) / 60);
		var s = totalSec % 60;

		elD.textContent = d;
		elH.textContent = pad(h);
		elM.textContent = pad(m);
		elS.textContent = pad(s);
	}

	tick();
	var timer = setInterval(tick, 1000);
}());
</script>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 2 — UPCOMING EVENTS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-upcoming" aria-labelledby="tsr-upcoming-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-upcoming-title">Предстоящи събития</h2>

		<?php if ( ! empty( $tsr_upcoming ) ) : ?>
			<div class="tsr-event-cards">
				<?php foreach ( $tsr_upcoming as $ev ) :
					$tsr_day        = date_i18n( 'j', $ev['start_ts'] );
					$tsr_month      = date_i18n( 'M', $ev['start_ts'] );
					$tsr_time       = $ev['time_ts'] > 0 ? date_i18n( 'H:i', $ev['time_ts'] ) . 'ч' : '';
					$tsr_card_style = $ev['thumbnail_url']
						? 'background-image:url(' . esc_url( $ev['thumbnail_url'] ) . ')'
						: '';
					?>
					<article class="tsr-event-card"<?php echo $tsr_card_style ? ' style="' . $tsr_card_style . '"' : ''; ?>>
						<a class="tsr-event-card__link"
						   href="<?php echo esc_url( $ev['url'] ); ?>"
						   aria-label="<?php echo esc_attr( $ev['title'] ); ?>"></a>

						<div class="tsr-event-card__date">
							<span class="tsr-event-card__day"><?php echo esc_html( $tsr_day ); ?></span>
							<span class="tsr-event-card__month"><?php echo esc_html( $tsr_month ); ?></span>
						</div>

						<div class="tsr-event-card__content">
							<h3 class="tsr-event-card__title"><?php echo esc_html( $ev['title'] ); ?></h3>
							<?php if ( '' !== $tsr_time ) : ?>
								<p class="tsr-event-card__meta-row">
									<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
									<?php echo esc_html( $tsr_time ); ?>
								</p>
							<?php endif; ?>
							<?php if ( '' !== $ev['location'] ) : ?>
								<p class="tsr-event-card__meta-row">
									<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
									<?php echo esc_html( $ev['location'] ); ?>
								</p>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="tsr-empty">
				Няма предстоящи събития в момента &mdash; следете за обновления!
			</p>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link" href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>">
				Пълен календар
			</a>
		</p>
	</div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 3 — PAST EVENTS / RESULTS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-past" aria-labelledby="tsr-past-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-past-title">Последни резултати</h2>

		<?php if ( ! empty( $tsr_past_results ) ) : ?>
			<ul class="tsr-result-list">
				<?php foreach ( $tsr_past_results as $result_post ) : ?>
					<li class="tsr-result-list__item">
						<a class="tsr-result-list__title"
						   href="<?php echo esc_url( get_permalink( $result_post ) ); ?>">
							<?php echo esc_html( get_the_title( $result_post ) ); ?>
						</a>
						<span class="tsr-result-list__meta">
							<?php echo esc_html( get_the_date( 'j.m.Y', $result_post ) ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="tsr-empty">Все още няма публикувани резултати.</p>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link"
			   href="<?php echo esc_url( home_url( '/rezultati/' ) ); ?>">
				Всички резултати
			</a>
		</p>
	</div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 4 — LATEST NEWS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-news" aria-labelledby="tsr-news-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-news-title">Новини</h2>

		<?php if ( ! empty( $tsr_news ) ) : ?>
			<div class="tsr-grid">
				<?php foreach ( $tsr_news as $news_post ) :
					$tsr_thumb = get_the_post_thumbnail_url( $news_post, 'medium' );
					?>
					<article class="tsr-card">
						<?php if ( $tsr_thumb ) : ?>
							<img class="tsr-card__thumb"
							     src="<?php echo esc_url( $tsr_thumb ); ?>"
							     alt=""
							     loading="lazy"
							     decoding="async">
						<?php endif; ?>
						<div class="tsr-card__body">
							<p class="tsr-card__meta">
								<?php echo esc_html( get_the_date( 'j F Y', $news_post ) ); ?>
							</p>
							<h3 class="tsr-card__title">
								<?php echo esc_html( get_the_title( $news_post ) ); ?>
							</h3>
							<p class="tsr-card__meta">
								<?php
								$tsr_excerpt = $news_post->post_excerpt
									?: wp_trim_words( strip_shortcodes( $news_post->post_content ), 20, '…' );
								echo esc_html( $tsr_excerpt );
								?>
							</p>
							<a class="tsr-card__link"
							   href="<?php echo esc_url( get_permalink( $news_post ) ); ?>">
								Прочети
							</a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="tsr-empty">Все още няма публикувани новини.</p>
		<?php endif; ?>

		<p class="tsr-view-all">
			<a class="tsr-card__link" href="<?php echo esc_url( home_url( '/novini/' ) ); ?>">
				Всички новини
			</a>
		</p>
	</div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 5 — ZERO TO HERO
     ════════════════════════════════════════════════════════════════════════ -->
<?php if ( ! empty( $tsr_zero ) ) :
	$tsr_zero_visible = array_slice( $tsr_zero, 0, 3 );
	$tsr_zero_slides  = array_slice( $tsr_zero, 3 );
?>
<section class="tsr-section tsr-zero-section" aria-labelledby="tsr-zero-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-zero-title">Zero to HERO</h2>

		<!-- First 3 posts — always visible -->
		<div class="tsr-zero-grid">
			<?php foreach ( $tsr_zero_visible as $tsr_zp ) :
				$tsr_z_thumb   = get_the_post_thumbnail_url( $tsr_zp, 'large' );
				$tsr_z_excerpt = $tsr_zp->post_excerpt
					?: wp_trim_words( strip_shortcodes( $tsr_zp->post_content ), 18, '…' );
				?>
				<article class="tsr-zero-card"<?php echo $tsr_z_thumb ? ' style="background-image:url(' . esc_url( $tsr_z_thumb ) . ')"' : ''; ?>>
					<div class="tsr-zero-card__body">
						<h3 class="tsr-zero-card__title"><?php echo esc_html( get_the_title( $tsr_zp ) ); ?></h3>
						<p class="tsr-zero-card__excerpt"><?php echo esc_html( $tsr_z_excerpt ); ?></p>
						<a class="tsr-zero-card__link" href="<?php echo esc_url( get_permalink( $tsr_zp ) ); ?>">Прочети →</a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( ! empty( $tsr_zero_slides ) ) :
			$tsr_n   = count( $tsr_zero_slides );
			$tsr_dur = $tsr_n * 5;
			$tsr_vis = round( 100 / $tsr_n, 3 );
			$tsr_fd  = round( min( 3, $tsr_vis * 0.15 ), 3 );
			?>
			<style>
			@keyframes tsrZeroSlide {
				0%                              { opacity: 0; visibility: hidden; }
				<?php echo $tsr_fd; ?>%         { opacity: 1; visibility: visible; }
				<?php echo $tsr_vis - $tsr_fd; ?>% { opacity: 1; visibility: visible; }
				<?php echo $tsr_vis; ?>%        { opacity: 0; visibility: hidden; }
				100%                            { opacity: 0; visibility: hidden; }
			}
			</style>

			<!-- Remaining posts — CSS slideshow, 5 s per slide -->
			<div class="tsr-zero-slider" aria-label="Допълнителни истории">
				<?php foreach ( $tsr_zero_slides as $tsr_si => $tsr_zp ) :
					$tsr_z_thumb   = get_the_post_thumbnail_url( $tsr_zp, 'large' );
					$tsr_z_excerpt = $tsr_zp->post_excerpt
						?: wp_trim_words( strip_shortcodes( $tsr_zp->post_content ), 18, '…' );
					$tsr_delay     = $tsr_si * 5;
					?>
					<article class="tsr-zero-slide"
					         style="animation-duration:<?php echo esc_attr( $tsr_dur ); ?>s;animation-delay:<?php echo esc_attr( $tsr_delay ); ?>s<?php echo $tsr_z_thumb ? ';background-image:url(' . esc_url( $tsr_z_thumb ) . ')' : ''; ?>">
						<div class="tsr-zero-card__body">
							<h3 class="tsr-zero-card__title"><?php echo esc_html( get_the_title( $tsr_zp ) ); ?></h3>
							<p class="tsr-zero-card__excerpt"><?php echo esc_html( $tsr_z_excerpt ); ?></p>
							<a class="tsr-zero-card__link" href="<?php echo esc_url( get_permalink( $tsr_zp ) ); ?>">Прочети →</a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	</div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 6 — MAP: ТРАСЕТАТА
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-section tsr-map-section" aria-labelledby="tsr-map-title">
	<div class="tsr-container">
		<h2 class="tsr-section__title" id="tsr-map-title">Трасетата</h2>
	</div>
	<div id="tsr-map"
	     class="tsr-map"
	     role="application"
	     aria-label="Карта на трасетата около София"></div>
</section>

<script>
/* global L */
(function () {
	'use strict';

	var initialized = false;

	function initTsrMap() {
		if ( initialized || typeof L === 'undefined' ) { return; }
		initialized = true;

		var map = L.map( 'tsr-map', {
			center: [ 42.60, 23.32 ],
			zoom: 10,
			scrollWheelZoom: false,
		} );

		// Enable scroll zoom only while the map has focus.
		map.on( 'focus', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'blur',  function () { map.scrollWheelZoom.disable(); } );

		L.tileLayer(
			'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
			{
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
				subdomains: 'abcd',
				maxZoom: 19,
			}
		).addTo( map );

		var makePin = function ( cls ) {
			return L.divIcon( {
				className:   '',
				html:        '<div class="tsr-map-pin ' + cls + '"></div>',
				iconSize:    [ 20, 30 ],
				iconAnchor:  [ 10, 30 ],
				popupAnchor: [ 0, -32 ],
			} );
		};

		var active = [
			{ lat: 42.3800, lng: 23.5200, name: 'Golyam Sechko Run',    mountain: 'Плана',  month: 'Януари',    dist: '6 / 9 / 15 км' },
			{ lat: 42.4000, lng: 23.5000, name: 'Malak Sechko Run',     mountain: 'Плана',  month: 'Февруари',  dist: '6 / 13 / 19 км' },
			{ lat: 42.5500, lng: 23.4800, name: 'Baba Marta Run',       mountain: 'Лозен',  month: 'Март',      dist: '6 / 10 / 16 км' },
			{ lat: 42.6800, lng: 23.1900, name: 'Lyulin Trail Run',     mountain: 'Люлин',  month: 'Май',       dist: '5.5 / 11.5 / 17 км' },
			{ lat: 42.5700, lng: 23.2800, name: '7 Hills Run',          mountain: 'Витоша', month: 'Септември', dist: '6 / 13 / 19 / 26 км' },
			{ lat: 42.8300, lng: 23.5800, name: 'Buhovo Half Marathon', mountain: 'Мургаш', month: 'Октомври',  dist: '10.7 / 21 км' },
			{ lat: 42.6100, lng: 23.4500, name: 'The Cactus Run',       mountain: 'Лозен',  month: 'Ноември',   dist: '7 / 14 / 21 км' },
			{ lat: 42.6600, lng: 23.1800, name: 'The Christmas Run',    mountain: 'Люлин',  month: 'Декември',  dist: '5.5 / 11 / 15 км' },
		];

		var historical = [
			{ lat: 42.6400, lng: 23.3200, name: 'Simeonovo Run',        mountain: 'Витоша', note: 'последно издание 2023' },
			{ lat: 42.6200, lng: 23.3000, name: 'Birthday Run',         mountain: 'Витоша', note: 'последно издание 2024' },
			{ lat: 42.6000, lng: 23.4700, name: 'Pancharevo Night Run', mountain: 'Лозен',  note: 'последно издание 2021' },
			{ lat: 42.6900, lng: 23.2100, name: 'iRan Run',             mountain: 'Люлин',  note: 'последно издание 2019' },
		];

		active.forEach( function ( e ) {
			L.marker( [ e.lat, e.lng ], { icon: makePin( 'tsr-map-pin--active' ) } )
				.bindPopup(
					'<strong>' + e.name + '</strong><br>' +
					e.mountain + ' &middot; ' + e.month + '<br>' +
					'<span class="tsr-popup-dist">' + e.dist + '</span>'
				)
				.on( 'mouseover', function () { this.openPopup(); } )
				.on( 'mouseout',  function () { this.closePopup(); } )
				.addTo( map );
		} );

		historical.forEach( function ( e ) {
			L.marker( [ e.lat, e.lng ], { icon: makePin( 'tsr-map-pin--hist' ) } )
				.bindPopup(
					'<strong>' + e.name + '</strong><br>' +
					e.mountain + '<br>' +
					'<em>' + e.note + '</em>'
				)
				.on( 'mouseover', function () { this.openPopup(); } )
				.on( 'mouseout',  function () { this.closePopup(); } )
				.addTo( map );
		} );

		var legend = L.control( { position: 'bottomright' } );
		legend.onAdd = function () {
			var div = L.DomUtil.create( 'div', 'tsr-map-legend' );
			div.innerHTML =
				'<span class="tsr-map-legend__dot tsr-map-legend__dot--active"></span>Активни<br>' +
				'<span class="tsr-map-legend__dot tsr-map-legend__dot--hist"></span>Исторически';
			return div;
		};
		legend.addTo( map );
	}

	// If Leaflet loaded synchronously it's available now; otherwise wait for
	// DOMContentLoaded (fires after deferred scripts) and window.load (fires
	// after async scripts) so the map initialises regardless of how the parent
	// theme outputs the <script> tag.
	if ( typeof L !== 'undefined' ) {
		initTsrMap();
	} else {
		document.addEventListener( 'DOMContentLoaded', initTsrMap );
		window.addEventListener( 'load', initTsrMap );
	}
}() );
</script>

<!-- ════════════════════════════════════════════════════════════════════════════
     SECTION 7 — QUICK STATS
     ════════════════════════════════════════════════════════════════════════ -->
<section class="tsr-stats" aria-label="Статистика на сезоните">
	<div class="tsr-container">
		<div class="tsr-stats__grid">

			<div class="tsr-stat">
				<div class="tsr-stat__num">14</div>
				<div class="tsr-stat__label">сезона</div>
			</div>

			<div class="tsr-stat">
				<div class="tsr-stat__num">
					<?php
					if ( $tsr_total_finishers > 0 ) {
						echo esc_html( number_format( $tsr_total_finishers, 0, ',', '\u{00A0}' ) );
					} else {
						echo '&mdash;';
					}
					?>
				</div>
				<div class="tsr-stat__label">финиширали</div>
			</div>

			<div class="tsr-stat">
				<div class="tsr-stat__num">
					<?php echo esc_html( $tsr_total_races > 0 ? $tsr_total_races : '—' ); ?>
				</div>
				<div class="tsr-stat__label">класирания</div>
			</div>

		</div>
	</div>
</section>

<?php get_footer(); ?>
