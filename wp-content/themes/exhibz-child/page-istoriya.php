<?php
declare( strict_types=1 );
/**
 * Template Name: История
 *
 * Template for the История page (slug: istoriya).
 *
 * Layout (design pass 3, owner-approved mockup 2026-11-14): the founders,
 * then the years that have a photo as "photo + text" blocks joined by a
 * dashed trail, the remaining years as compact cards, and the jubilee as a
 * closing photo band.
 *
 * To add a photo to a year: put a JPEG in images/history/ and add the year
 * to $tsr_media below (file, 'portrait' for upright photos, 'band' for a
 * full-width photo with the text on it). Texts live in $tsr_timeline.
 *
 * @package exhibz-child
 */

get_header();

/**
 * Timeline texts — each entry: year, label ("N-ти сезон — заглавие"), desc.
 *
 * @var array<int, array{year: int, label: string, desc: string}>
 */
$tsr_timeline = array(
	array(
		'year'  => 2012,
		'label' => '1-ви сезон — Основаване',
		'desc'  => 'През октомври 2012 г. Илиян Лазаров и Тони Петков поставят началото на TrailSeries.bg. Софийският маратон не се провежда тази година, а извън календара на БФЛА масовите трейл събития у нас са рядкост. Първото издание се провежда над с. Железница — скромно начало на най-дълголетната трейл серия в България.',
	),
	array(
		'year'  => 2013,
		'label' => '2-ри сезон — The Cactus Run и първите елитни имена',
		'desc'  => 'Първото официално издание на The Cactus Run бележи истинския старт на сериите, които вече официално се обединяват под името Trail Series с подкрепата на спонсорите SLS и Mizuno. Baba Marta Run получава медийно отразяване от БТВ и БНТ, а на старта излизат имена като Йоло Николов, Митко Ценов, Кирил Николов „Дизела", Андрей Гридин и Антония Григорова.',
	),
	array(
		'year'  => 2014,
		'label' => '3-ти сезон — Фокус върху столичните планини',
		'desc'  => 'Организаторите вземат решение, което определя облика на сериите за години напред — да се съсредоточат единствено върху петте планини около София: Витоша, Люлин, Лозен, Плана и Стара планина. Далечните локации отпадат от календара в полза на достъпност и предвидимост за бегачите.',
	),
	array(
		'year'  => 2015,
		'label' => '4-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2016,
		'label' => '5-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2017,
		'label' => '6-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2018,
		'label' => '7-ми сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2019,
		'label' => '8-ми сезон — стартира Zero to HERO',
		'desc'  => 'Стартира рубриката Zero to HERO — поредица от лични истории на бегачи от общността, разказани с техните думи. Тя дава глас на аматьорите, за които всяко финиширано трасе е лична победа, а не просто ред в класирането.',
	),
	array(
		'year'  => 2020,
		'label' => '8-ми сезон (продължава) — Пандемията прекъсва серията',
		'desc'  => 'Пандемията от COVID-19 прекъсва непрекъснатата поредица от сезони за първи път от основаването на сериите. Наложената пауза налага структурна промяна — от следващия сезон TrailSeries.bg преминава от модел октомври-до-октомври към сезони, обвързани с календарната година.',
	),
	array(
		'year'  => 2021,
		'label' => '9-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2022,
		'label' => '10-ти сезон — Юбилей',
		'desc'  => '10 години планинско бягане с TrailSeries.bg.',
	),
	array(
		'year'  => 2023,
		'label' => '11-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2024,
		'label' => '12-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2025,
		'label' => '13-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2026,
		'label' => '14-ти сезон — Архивът: 899 класирания, 19 299 финиша',
		'desc'  => 'От основаването си през 2012 г. до 2026 г. TrailSeries.bg изминава 14 сезона, а архивът пази 899 състезателни класирания с 19 299 финиширали бегачи — всички дигитализирани и съхранени за бъдещите поколения трейл бегачи. Серията навлиза в своята 15-та година, а погледите вече са насочени напред — през октомври 2027 г. TrailSeries.bg ще отпразнува своя 15-годишен юбилей.',
	),
	array(
		'year'  => 2027,
		'label' => '15-годишен юбилей — Октомври 2027',
		'desc'  => 'Тържествено отбелязване на 15 години TrailSeries.bg — петнадесет сезона планинско бягане, хиляди финиши и една общност, която продължава да расте с всяко издание.',
	),
);

/**
 * Photos per year (files in images/history/).
 *
 * @var array<int, array{file: string, layout?: 'band', portrait?: bool, credit?: string}>
 */
$tsr_media = array(
	2012 => array( 'file' => '2012-nachalo.jpg' ),
	2013 => array( 'file' => '2013-kiril-nikolov.jpg', 'portrait' => true ),
	2014 => array( 'file' => 'vitosha.jpg', 'layout' => 'band' ),
	2015 => array( 'file' => '2015.jpg' ),
	2016 => array( 'file' => '2016.jpg' ),
	2017 => array( 'file' => '2017-pirin-vertical.jpg' ),
	2018 => array( 'file' => '2018.jpg' ),
	2019 => array( 'file' => '2019-zero-to-hero.jpg', 'portrait' => true ),
	2020 => array( 'file' => '2020.jpg' ),
	2021 => array( 'file' => '2021.jpg' ),
	2022 => array( 'file' => '2022-yubiley.jpg' ),
	2023 => array( 'file' => '2023.jpg' ),
	2024 => array( 'file' => '2024-cactus-run.jpg' ),
	2025 => array( 'file' => '2025.jpg' ),
	2026 => array( 'file' => '2026.jpg' ),
);
$tsr_closing_year  = 2027; // rendered as the closing band
$tsr_closing_photo = 'planini-sofia-pateka.jpg';
$tsr_photo_credit   = 'KrisoK Photography'; // one credit line at the bottom of the page (owner, 2026-11-14)

$tsr_by_year = array_column( $tsr_timeline, null, 'year' );

/**
 * Races of the season a timeline year stands for, from the published
 * results (same count as on /klasiraniya/). 2020 has none of its own:
 * season 8 ran from October 2019 to December 2020 and is counted at 2019.
 */
$tsr_year_races = static function ( int $year ): string {
	if ( 2020 === $year ) {
		return '';
	}
	$n = tsr_season_race_count( $year );
	return $n > 0 ? tsr_races_label( $n ) : '';
};

/**
 * <img> for a history photo (lazy, with its real dimensions).
 */
$tsr_history_img = static function ( string $file, string $class = '' ): string {
	$path = get_stylesheet_directory() . '/images/history/' . $file;
	if ( ! is_readable( $path ) ) {
		return '';
	}
	$size = getimagesize( $path );
	return sprintf(
		'<img%s src="%s" width="%d" height="%d" alt="" loading="lazy" decoding="async">',
		'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
		esc_url( get_stylesheet_directory_uri() . '/images/history/' . $file . '?v=' . filemtime( $path ) ),
		(int) ( $size[0] ?? 0 ),
		(int) ( $size[1] ?? 0 )
	);
};

/** "N-ти сезон — Заглавие" → [ season, title ]. */
$tsr_split_label = static function ( string $label ): array {
	$parts = explode( ' — ', $label, 2 );
	return 2 === count( $parts ) ? $parts : array( '', $label );
};

/**
 * Dashed trail between two photos. $from / $to are the horizontal centres
 * of the photos in percent; on phones a straight centred line shows instead.
 */
$tsr_trail = static function ( float $from, float $to ): void {
	printf(
		'<div class="tsr-trail" aria-hidden="true"><svg class="tsr-trail__curve" viewBox="0 0 100 100" preserveAspectRatio="none" focusable="false"><path d="M %1$s 0 C %1$s 55, %2$s 45, %2$s 100" vector-effect="non-scaling-stroke"/></svg><span class="tsr-trail__straight"></span></div>',
		esc_attr( (string) $from ),
		esc_attr( (string) $to )
	);
};

// Blocks in page order with the horizontal centre of each photo (percent of
// the container): text 5 cols + photo 7 cols → 71; flipped → 29; upright
// photos take 5 cols → 79 / 21; a band is full width → 50.
$tsr_blocks = array();
$tsr_flip   = false; // the founders block (photo left) comes first
foreach ( $tsr_media as $tsr_year => $tsr_m ) {
	if ( ! isset( $tsr_by_year[ $tsr_year ] ) ) {
		continue;
	}
	$tsr_is_band = 'band' === ( $tsr_m['layout'] ?? '' );
	$tsr_upright = ! empty( $tsr_m['portrait'] );
	if ( $tsr_is_band ) {
		$tsr_x = 50.0;
	} else {
		$tsr_x    = $tsr_flip ? ( $tsr_upright ? 21.0 : 29.0 ) : ( $tsr_upright ? 79.0 : 71.0 );
	}
	$tsr_blocks[] = array(
		'year'    => (int) $tsr_year,
		'band'    => $tsr_is_band,
		'flip'    => ! $tsr_is_band && $tsr_flip,
		'upright' => $tsr_upright,
		'file'    => $tsr_m['file'],
		'credit'  => (string) ( $tsr_m['credit'] ?? '' ),
		'x'       => $tsr_x,
	);
	if ( ! $tsr_is_band ) {
		$tsr_flip = ! $tsr_flip;
	}
}
$tsr_plain_years = array_filter(
	$tsr_timeline,
	static fn( array $e ): bool => ! isset( $tsr_media[ $e['year'] ] ) && $e['year'] < $tsr_closing_year
);
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">История</h1>
		<p class="tsr-page-hero__subtitle">
			15 сезона планинско бягане в България — от 2012 до 2027
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content tsr-history">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'История' ); ?>

		<!-- За Trail Series (owner's text, 2026-11-14) -->
		<section class="tsr-about" aria-labelledby="tsr-about-h">
			<p class="tsr-story__season">За Trail Series</p>
			<h2 class="tsr-about__title" id="tsr-about-h">15 години. 147 състезания. Една пътека.</h2>
			<div class="tsr-about__text">
				<p>
					Всичко започна с една проста идея: да тичаме там, където свършва асфалтът. Без големи
					бюджети и без парични награди. Само маркирана пътека, планина и хора, които обичат да
					тичат сред природата. Така се роди RunBG Trail Series, серия от състезания по трейл и
					планинско бягане, които се провеждат всеки месец, сезон след сезон.
				</p>
				<p>
					Петнадесет години по-късно зад гърба си имаме 147 събития. Пътеките ни са
					минали през Люлин, Локорско, Бухово, Симеоново, Панчарево, Банкя, Бояна, склоновете на
					Пирин над Банско и много други места. Всяко състезание е различно, с нова дистанция, нов
					терен и ново предизвикателство. Затова серията не остарява. Тя се променя заедно с
					хората, които тичат в нея.
				</p>
				<p>
					През годините на нашите стартове са застанали хиляди бегачи. Някои дойдоха за първото си
					състезание, а днес гонят ултрадистанции. Други започнаха като деца, а сега тичат заедно
					с децата си. Има и такива, които са с нас от първия сезон и досега не са изпуснали
					годишно класиране. Trail Series винаги е била отворена за всеки любител. Не ти трябват
					клуб, картотека или лиценз. Достатъчно е желание.
				</p>
			</div>

			<h3 class="tsr-about__h3">Какво се е запазило от самото начало</h3>
			<ul class="tsr-values">
				<li>
					<h4>Състезателен дух за всички</h4>
					<p>
						Класираме мъжете и жените поотделно на дълга, средна и къса дистанция. Медали
						получават само първите трима. В годишното класиране влизат бегачите с поне три
						пробега през сезона, а в края му излъчваме годишен победител и ранглиста на топ 10
						при мъжете и при жените.
					</p>
				</li>
				<li>
					<h4>Феърплей и уважение</h4>
					<p>
						Към другите бегачи, към доброволците и към планината. Тичаме само по маркираното
						трасе и не оставяме боклук след себе си.
					</p>
				</li>
				<li>
					<h4>Свобода на пътеката</h4>
					<p>
						Ние маркираме трасето и осигуряваме сигурен старт и финал. Останалото зависи от теб.
					</p>
				</li>
			</ul>

			<p class="tsr-about__closing">
				147 събития по-късно Trail Series вече е нещо повече от календар със състезания. Тя е
				общност, която всеки месец се събира в планината, за да тича, да се подкрепя и да се
				връща отново. Благодарим на всеки бегач, доброволец и приятел, който е извървял и
				изтичал този път с нас.
			</p>
		</section>

		<!-- Хората зад Trail Series (owner's text, 2026-11-14) -->
		<section class="tsr-story tsr-story--flip tsr-story--creators" aria-labelledby="tsr-people-h">
			<div class="tsr-story__text">
				<p class="tsr-story__season">Организатори</p>
				<h2 class="tsr-story__title" id="tsr-people-h">Хората зад Trail Series</h2>
				<p>
					Trail Series се роди от срещата на двама души, които тръгнаха от различни места, но
					имаха една обща цел: повече хора да тичат и повече от тях да тичат в планината.
				</p>
			</div>
			<figure class="tsr-story__media">
				<?php echo $tsr_history_img( 'sazdateli.jpg' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_url/esc_attr. ?>
				<figcaption>Илиян Лазаров и Тони Петков, създатели на Trail Series</figcaption>
			</figure>
		</section>

		<div class="tsr-people">
			<article class="tsr-person">
				<h3>Илиян Лазаров</h3>
				<p>
					Илиян е бегач любител, минал през няколко спорта, преди бягането да се превърне в
					негова страст. По онова време той пише статии за runbg.net и търси начини да
					популяризира любителското бягане в България. През 2010 г. организира първите групови
					бягания в Борисова градина. От тези срещи постепенно израсна общността, от която
					по-късно тръгна и Trail Series.
				</p>
			</article>
			<article class="tsr-person">
				<h3>Тони Петков</h3>
				<p>
					Тони идва от леката атлетика, но истинската му любов са планината и планинското
					бягане. Той е човекът, който измисли първите трасета. Задачата никак не беше лесна:
					пътеките трябваше да са живописни и да предизвикват бегачите, но в същото време да са
					достъпни за възможно най-много хора. Този баланс между красота, предизвикателство и
					достъпност и днес отличава всяко трасе на серията.
				</p>
			</article>
		</div>
		<p class="tsr-people__closing">
			На 13 октомври 2012 г. се проведе първото състезание от Trail Series. Оттогава идеята на
			двамата се превърна в традиция, която продължава и до днес.
		</p>

		<?php
		$tsr_prev_x     = 50.0; // starts under the centred "13 октомври 2012" sentence
		$tsr_open_wrap  = true; // inside .tsr-container
		foreach ( $tsr_blocks as $tsr_b ) :
			$tsr_e                       = $tsr_by_year[ $tsr_b['year'] ];
			[ $tsr_season, $tsr_title ] = $tsr_split_label( $tsr_e['label'] );
			$tsr_trail( $tsr_prev_x, $tsr_b['x'] );
			$tsr_prev_x = $tsr_b['x'];

			if ( $tsr_b['band'] ) :
				// A band is full width: leave the container, print, re-enter.
				?>
				</div>
				<section class="tsr-band">
					<?php echo $tsr_history_img( $tsr_b['file'], 'tsr-band__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="tsr-container">
						<div class="tsr-band__in">
							<p class="tsr-story__year"><?php echo esc_html( (string) $tsr_b['year'] ); ?></p>
							<?php if ( '' !== $tsr_season ) : ?>
								<p class="tsr-story__season"><?php echo esc_html( $tsr_season ); ?></p>
							<?php endif; ?>
							<h2 class="tsr-story__title"><?php echo esc_html( $tsr_title ); ?></h2>
							<?php if ( '' !== $tsr_year_races( $tsr_b['year'] ) ) : ?>
								<p class="tsr-story__races"><?php echo esc_html( $tsr_year_races( $tsr_b['year'] ) ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $tsr_e['desc'] ) : ?>
								<p><?php echo esc_html( $tsr_e['desc'] ); ?></p>
							<?php endif; ?>
						</div>
						<?php if ( '' !== $tsr_b['credit'] ) : ?>
							<p class="tsr-band__credit">Снимка: <?php echo esc_html( $tsr_b['credit'] ); ?></p>
						<?php endif; ?>
					</div>
				</section>
				<div class="tsr-container">
				<?php
			else :
				?>
				<section class="tsr-story<?php echo $tsr_b['flip'] ? ' tsr-story--flip' : ''; ?><?php echo $tsr_b['upright'] ? ' tsr-story--upright' : ''; ?>">
					<div class="tsr-story__text">
						<p class="tsr-story__year"><?php echo esc_html( (string) $tsr_b['year'] ); ?></p>
						<?php if ( '' !== $tsr_season ) : ?>
							<p class="tsr-story__season"><?php echo esc_html( $tsr_season ); ?></p>
						<?php endif; ?>
						<h2 class="tsr-story__title"><?php echo esc_html( $tsr_title ); ?></h2>
							<?php if ( '' !== $tsr_year_races( $tsr_b['year'] ) ) : ?>
								<p class="tsr-story__races"><?php echo esc_html( $tsr_year_races( $tsr_b['year'] ) ); ?></p>
							<?php endif; ?>
						<?php if ( '' !== $tsr_e['desc'] ) : ?>
							<p><?php echo esc_html( $tsr_e['desc'] ); ?></p>
						<?php endif; ?>
					</div>
					<figure class="tsr-story__media">
						<?php echo $tsr_history_img( $tsr_b['file'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</figure>
				</section>
				<?php
			endif;
		endforeach;
		?>

		<?php if ( array() !== $tsr_plain_years ) : ?>
			<section class="tsr-history__years" aria-labelledby="tsr-years-h">
				<h2 class="tsr-history__h2" id="tsr-years-h">Сезоните накратко</h2>
				<ol class="tsr-years">
					<?php foreach ( $tsr_plain_years as $tsr_e ) : ?>
						<li>
							<span class="tsr-years__y"><?php echo esc_html( (string) $tsr_e['year'] ); ?></span>
							<div>
								<h3><?php echo esc_html( $tsr_e['label'] ); ?></h3>
								<?php if ( '' !== $tsr_e['desc'] ) : ?>
									<p><?php echo esc_html( $tsr_e['desc'] ); ?></p>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

	</div>

	<?php if ( isset( $tsr_by_year[ $tsr_closing_year ] ) ) : ?>
		<?php [ , $tsr_closing_title ] = $tsr_split_label( $tsr_by_year[ $tsr_closing_year ]['label'] ); ?>
		<section class="tsr-band tsr-band--closing">
			<?php echo $tsr_history_img( $tsr_closing_photo, 'tsr-band__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="tsr-container">
				<div class="tsr-band__in">
					<p class="tsr-story__year"><?php echo esc_html( (string) $tsr_closing_year ); ?></p>
					<h2 class="tsr-story__title"><?php echo esc_html( $tsr_by_year[ $tsr_closing_year ]['label'] ); ?></h2>
					<?php if ( ! empty( $tsr_by_year[ $tsr_closing_year ]['desc'] ) ) : ?>
						<p><?php echo esc_html( $tsr_by_year[ $tsr_closing_year ]['desc'] ); ?></p>
					<?php endif; ?>
				</div>
				<p class="tsr-band__credit">Снимки: <?php echo esc_html( $tsr_photo_credit ); ?></p>
			</div>
		</section>
	<?php endif; ?>
</main>

<?php get_footer(); ?>
