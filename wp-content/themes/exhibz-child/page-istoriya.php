<?php
declare( strict_types=1 );
/**
 * Template Name: История
 *
 * Template for the История page (slug: istoriya).
 *
 * Static timeline from 2012 to the current season.
 * To add events: edit the $tsr_timeline array below.
 *
 * @package exhibz-child
 */

get_header();

/**
 * Timeline data — each entry: year, label, description.
 * Add or expand as needed; the template renders the list automatically.
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
		'label' => '9-ти сезон — Пандемията прекъсва серията',
		'desc'  => 'Пандемията от COVID-19 прекъсва непрекъснатата поредица от сезони за първи път от основаването на сериите. Наложената пауза налага структурна промяна — от следващия сезон TrailSeries.bg преминава от модел октомври-до-октомври към сезони, обвързани с календарната година.',
	),
	array(
		'year'  => 2021,
		'label' => '10-ти сезон — Юбилей',
		'desc'  => '10 години планинско бягане с TrailSeries.bg.',
	),
	array(
		'year'  => 2022,
		'label' => '11-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2023,
		'label' => '12-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2024,
		'label' => '13-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2025,
		'label' => '14-ти сезон — Архивът: 899 класирания, 18 899 финиша',
		'desc'  => 'От основаването си през 2012 г. до 2025 г. TrailSeries.bg изминава 14 сезона, а архивът пази 899 състезателни класирания с 18 899 финиширали бегачи — всички дигитализирани и съхранени за бъдещите поколения трейлъри.',
	),
	array(
		'year'  => 2026,
		'label' => '15-ти сезон — Наближава голям юбилей',
		'desc'  => 'Серията навлиза в своята 15-та година, а погледите вече са насочени напред — през октомври 2027 г. TrailSeries.bg ще отпразнува своя 15-годишен юбилей.',
	),
	array(
		'year'  => 2027,
		'label' => '15-годишен юбилей — Октомври 2027',
		'desc'  => 'Тържествено отбелязване на 15 години TrailSeries.bg — петнадесет сезона планинско бягане, хиляди финиши и една общност, която продължава да расте с всяко издание.',
	),
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

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'История' ); ?>

		<section class="tsr-prose-section">
			<p>
				TrailSeries.bg е поредицата планински бягания, която обединява трейл
				общността в България. Всеки сезон включва поредица от маршрути с
				различна дължина и денивелация — от достъпни за начинаещи до
				предизвикателни за опитни планинари.
			</p>
		</section>

		<section class="tsr-prose-section">
			<ol class="tsr-timeline" reversed>
				<?php foreach ( array_reverse( $tsr_timeline ) as $entry ) : ?>
					<li class="tsr-timeline__item<?php echo 2027 === $entry['year'] ? ' tsr-timeline__item--future' : ''; ?>">
						<div class="tsr-timeline__year"><?php echo esc_html( $entry['year'] ); ?></div>
						<div class="tsr-timeline__body">
							<h3 class="tsr-timeline__label">
								<?php echo esc_html( $entry['label'] ); ?>
							</h3>
							<?php if ( '' !== $entry['desc'] ) : ?>
								<p class="tsr-timeline__desc">
									<?php echo esc_html( $entry['desc'] ); ?>
								</p>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>

	</div>
</main>

<?php get_footer(); ?>
