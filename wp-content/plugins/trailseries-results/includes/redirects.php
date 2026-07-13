<?php
/**
 * URL redirect and 410 Gone handler.
 *
 * Auto-generated from migration/redirect-map.csv — do not edit manually.
 * Regenerate: python migration/generate_redirects.py
 *
 * 301 redirects: 92 rules
 * 410 gone:      51 rules
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

add_action(
	'template_redirect',
	static function (): void {
		// Decode and strip trailing slash so we match regardless of slash style.
		$raw  = (string) ( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/' );
		$path = untrailingslashit( urldecode( $raw ) );
		if ( '' === $path ) {
			$path = '/';
		}

		// ── 301 Redirects ────────────────────────────────────────────────────
		$redirects = array(
			'/all-seasons-rankings' => '/klasiraniya/',
			'/category/covid-19' => '/novini/',
			'/category/covid-19/page/1' => '/novini/',
			'/category/covid-19/page/2' => '/novini/',
			'/category/uncategorized' => '/novini/',
			'/category/zero-to-hero' => '/novini/',
			'/category/новини' => '/novini/',
			'/category/новини/page/1' => '/novini/',
			'/category/новини/page/2' => '/novini/',
			'/category/новини/page/3' => '/novini/',
			'/event-directory' => '/calendar/',
			'/event-location/banderishka-polyana' => '/calendar/',
			'/event-location/bankya' => '/calendar/',
			'/event-location/boyansko-hanche' => '/calendar/',
			'/event-location/buhovo' => '/calendar/',
			'/event-location/german' => '/calendar/',
			'/event-location/kokalyane' => '/calendar/',
			'/event-location/lokorsko' => '/calendar/',
			'/event-location/path-of-health' => '/calendar/',
			'/event-location/simeonovski-ezera' => '/calendar/',
			'/event-location/zheleznitsa' => '/calendar/',
			'/event-location/zhelyava' => '/calendar/',
			'/event-location/с-ярлово' => '/calendar/',
			'/event-organizer/trail-series' => '/calendar/',
			'/event-organizer/tri-series' => '/calendar/',
			'/event-type/trail' => '/calendar/',
			'/event-type/tri-series' => '/calendar/',
			'/events/7-hills-run' => '/calendar/',
			'/events/baba-marta-run' => '/calendar/',
			'/events/beglika-3' => '/calendar/',
			'/events/birthday-run' => '/calendar/',
			'/events/boyana-x-trail' => '/calendar/',
			'/events/buhovo-half-marathon' => '/calendar/',
			'/events/duathlon-buhovo' => '/calendar/',
			'/events/golyam-sechko-run' => '/calendar/',
			'/events/lyulin-trail-run' => '/calendar/',
			'/events/malak-sechko-run' => '/calendar/',
			'/events/malak-sechko-run-25' => '/calendar/',
			'/events/pancharevo-night-run' => '/calendar/',
			'/events/pirin-skyrun' => '/calendar/',
			'/events/pirin-vertical' => '/calendar/',
			'/events/plovdiv-hills' => '/calendar/',
			'/events/simeonovo-run' => '/calendar/',
			'/events/the-cactus-run25' => '/calendar/',
			'/events/the-christmas-run' => '/calendar/',
			'/events/полумаратон-палакария' => '/calendar/',
			'/overal-ranking' => '/klasiraniya/',
			'/overal-ranking/bankia-24mart' => '/bankia-24mart/',
			'/overal-ranking/iran-run18-results' => '/iran-run18-results/',
			'/overal-ranking/lokorsko-23-fevruari' => '/lokorsko-23-fevruari/',
			'/overal-ranking/runbg-trail-series-железница-13-октомври-класиране' => '/runbg-trail-series-железница-13-октомври-класиране/',
			'/overal-ranking/vladaya-21-april' => '/vladaya-21-april/',
			'/overal-ranking/генерално-класиране' => '/klasiraniya/',
			'/overal-ranking/панчарево-10-ноември' => '/панчарево-10-ноември/',
			'/page/1' => '/novini/',
			'/page/2' => '/novini/',
			'/page/3' => '/novini/',
			'/втори-сезон-20132014' => '/klasiraniya/',
			'/генерално-класиране-сезон-8' => '/klasiraniya/',
			'/генерално-класиране22' => '/klasiraniya/',
			'/за-trail-series' => '/istoriya/',
			'/за-trail-series/calendar' => '/calendar/',
			'/за-trail-series/rules' => '/pravila/',
			'/класиране/генерално-класиране' => '/klasiraniya/',
			'/победители-за-сезон-2022' => '/klasiraniya/',
			'/победители-за-сезон-2023' => '/klasiraniya/',
			'/победители-за-сезон-2024' => '/klasiraniya/',
			'/сезон-1-резултати' => '/klasiraniya/',
			'/сезон-10' => '/klasiraniya/',
			'/сезон-10-резултати' => '/klasiraniya/',
			'/сезон-11' => '/klasiraniya/',
			'/сезон-11-резултати' => '/klasiraniya/',
			'/сезон-12' => '/klasiraniya/',
			'/сезон-12-резултати' => '/klasiraniya/',
			'/сезон-13' => '/klasiraniya/',
			'/сезон-13-класиране' => '/klasiraniya/',
			'/сезон-2-резултати' => '/klasiraniya/',
			'/сезон-3-резултати' => '/klasiraniya/',
			'/сезон-4' => '/klasiraniya/',
			'/сезон-4-резултати' => '/klasiraniya/',
			'/сезон-5' => '/klasiraniya/',
			'/сезон-5-резултати' => '/klasiraniya/',
			'/сезон-6' => '/klasiraniya/',
			'/сезон-6-генерално-класиране' => '/klasiraniya/',
			'/сезон-6-резултати' => '/klasiraniya/',
			'/сезон-7' => '/klasiraniya/',
			'/сезон-7-резултати' => '/klasiraniya/',
			'/сезон-8' => '/klasiraniya/',
			'/сезон-8-резултати' => '/klasiraniya/',
			'/сезон-9' => '/klasiraniya/',
			'/сезон-9-резултати' => '/klasiraniya/',
			'/трети-сезон-20142015' => '/klasiraniya/',
		);

		if ( isset( $redirects[ $path ] ) ) {
			wp_redirect( home_url( $redirects[ $path ] ), 301, 'TrailSeries' );
			exit;
		}

		// ── 410 Gone ─────────────────────────────────────────────────────────
		$gone = array(
			'/100-регистрирани-за-thechristmasrun' => 1,
			'/117' => 1,
			'/135-регистрирани-за-thechristmasrun' => 1,
			'/1539' => 1,
			'/170-коледни-бегачи' => 1,
			'/2012-2' => 1,
			'/2143-2' => 1,
			'/41-регистрирани-за-christmas-run' => 1,
			'/81-регистрирани-за-thechristmasrun-04-12' => 1,
			'/90-регистрирани-за-thechristmasrun' => 1,
			'/feedback' => 1,
			'/heat-stroke-run-gallery' => 1,
			'/koledni-vaucheri' => 1,
			'/koy-oglavyava-top-10' => 1,
			'/maliovitsa-skyrun-photos' => 1,
			'/race-photos' => 1,
			'/registration' => 1,
			'/registration/notsuccessful' => 1,
			'/registration/successful' => 1,
			'/sabitia' => 1,
			'/sabitia/zheleznitsa-2-0/jordan-petrov' => 1,
			'/snimki' => 1,
			'/snimki/baba-marta-run' => 1,
			'/snimki/malak-sechko-run' => 1,
			'/snimki/runbg-trail-series-vladaya' => 1,
			'/snimki/runbg-trail-series-zheleznitsа' => 1,
			'/snimki/runbg-trail-series-панчарево' => 1,
			'/snimki/the-chrismas-run' => 1,
			'/tag/sls' => 1,
			'/tag/trail' => 1,
			'/tag/trail-series' => 1,
			'/tag/zero-to-hero' => 1,
			'/tag/генерално-класиране' => 1,
			'/tag/избрано' => 1,
			'/tag/коледно-бягане-регистрирани' => 1,
			'/tag/намаления' => 1,
			'/tag/новини' => 1,
			'/tag/регистрирани-за-участие' => 1,
			'/tag/сезон-8' => 1,
			'/tag/слс' => 1,
			'/thechristmasrun-стартов-списък' => 1,
			'/zheleznitsa-2-0-20-jan/olympus-digital-camera' => 1,
			'/днес-затваря-регистрацията-за-коледн' => 1,
			'/доброволчество' => 1,
			'/за-trail-series/feedback' => 1,
			'/за-trail-series/t-shirt-poruchka-no' => 1,
			'/за-trail-series/t-shirt-poruchka-y' => 1,
			'/за-trail-series/контакти' => 1,
			'/лагера-на-бегача-пиратите' => 1,
			'/обб-vertical-run-2019-снимки' => 1,
			'/спонсори' => 1,
		);

		if ( isset( $gone[ $path ] ) ) {
			status_header( 410 );
			nocache_headers();
			wp_die(
				esc_html__( 'This page has been permanently removed.', 'trailseries-results' ),
				esc_html__( 'Gone', 'trailseries-results' ),
				array( 'response' => 410 )
			);
		}
	},
	0   // before redirect_canonical (priority 1) and any theme hooks
);
