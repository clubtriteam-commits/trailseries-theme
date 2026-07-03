<?php
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

declare( strict_types=1 );

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
		'desc'  => 'Старт на TrailSeries.bg — първата организирана серия от планински бягания в България. Поставено началото на традицията.',
	),
	array(
		'year'  => 2013,
		'label' => '2-ри сезон',
		'desc'  => 'Серията разширява календара. Нарастващ брой участници и нови маршрути.',
	),
	array(
		'year'  => 2014,
		'label' => '3-ти сезон',
		'desc'  => 'Въвеждане на точковата система за сезонно класиране.',
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
		'label' => '8-ми сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2020,
		'label' => '9-ти сезон',
		'desc'  => '',
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
		'label' => '14-ти сезон',
		'desc'  => '',
	),
	array(
		'year'  => 2026,
		'label' => '15-ти сезон (в момента)',
		'desc'  => 'Серията навлиза в своята 15-та година.',
	),
	array(
		'year'  => 2027,
		'label' => '15-годишен юбилей — Октомври 2027',
		'desc'  => 'Тържествено отбелязване на 15 години TrailSeries.bg.',
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

<main class="tsr-page-content">
	<div class="tsr-container">

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
