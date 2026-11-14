<?php
declare( strict_types=1 );
/**
 * Template Name: Правила
 *
 * Template for the Правила page (slug: pravila).
 *
 * Static content — the scoring system is explained here.
 * No database queries needed.
 *
 * @package exhibz-child
 */

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Правила за класиране</h1>
		<p class="tsr-page-hero__subtitle">
			Система за точки, категории дистанции и условия за участие в сезонното класиране
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Правила' ); ?>

		<!-- ─── Intro ──────────────────────────────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Как работи класирането?</h2>
			<p>
				TrailSeries.bg събира резултатите от всички включени състезания в сезона
				и изгражда годишен ранкинг — отделно за мъже и за жени. Точките се
				присъждат спрямо заетото място в съответната дистанция, в класирането
				на съответния пол. Възрастта няма значение.
			</p>
			<p>
				Само финиширалите участници получават точки. DNS, DNF, DSQ и OTL не
				носят точки.
			</p>
		</section>

		<!-- ─── Distance categories ──────────────────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Категории дистанции</h2>
			<p>
				Всяка дистанция попада в една от три категории според дължината си.
				Категорията определя максималния брой точки и броя на класираните
				места. Изключение са бонус състезанията, описани по-долу.
			</p>

			<div class="tsr-scoring-grid">

				<div class="tsr-scoring-card tsr-scoring-card--short">
					<div class="tsr-scoring-card__badge">Категория A</div>
					<h3 class="tsr-scoring-card__title">Къса дистанция <small>под 8 км</small></h3>
					<div class="tsr-scoring-card__max">макс <strong>5</strong> точки</div>
					<p class="tsr-scoring-card__formula">
						Формула: <code>6 &minus; място</code>
					</p>
					<table class="tsr-scoring-card__table">
						<thead>
							<tr><th>Място</th><th>Точки</th></tr>
						</thead>
						<tbody>
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<tr>
									<td><?php echo esc_html( $i ); ?></td>
									<td><strong><?php echo esc_html( 6 - $i ); ?></strong></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>

				<div class="tsr-scoring-card tsr-scoring-card--medium">
					<div class="tsr-scoring-card__badge">Категория B</div>
					<h3 class="tsr-scoring-card__title">Средна дистанция <small>8–13,9 км</small></h3>
					<div class="tsr-scoring-card__max">макс <strong>10</strong> точки</div>
					<p class="tsr-scoring-card__formula">
						Формула: <code>11 &minus; място</code>
					</p>
					<table class="tsr-scoring-card__table">
						<thead>
							<tr><th>Място</th><th>Точки</th></tr>
						</thead>
						<tbody>
							<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
								<tr>
									<td><?php echo esc_html( $i ); ?></td>
									<td><strong><?php echo esc_html( 11 - $i ); ?></strong></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>

				<div class="tsr-scoring-card tsr-scoring-card--long">
					<div class="tsr-scoring-card__badge">Категория C</div>
					<h3 class="tsr-scoring-card__title">Дълга дистанция <small>14 км и повече</small></h3>
					<div class="tsr-scoring-card__max">макс <strong>15</strong> точки</div>
					<p class="tsr-scoring-card__formula">
						Формула: <code>16 &minus; място</code>
					</p>
					<table class="tsr-scoring-card__table">
						<thead>
							<tr><th>Място</th><th>Точки</th></tr>
						</thead>
						<tbody>
							<?php for ( $i = 1; $i <= 15; $i++ ) : ?>
								<tr>
									<td><?php echo esc_html( $i ); ?></td>
									<td><strong><?php echo esc_html( 16 - $i ); ?></strong></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>

			</div><!-- .tsr-scoring-grid -->
		</section>

		<!-- ─── Bonus races (season 14 / 2026) ───────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Бонус състезания</h2>
			<p>
				През сезон 14 (2026) три състезания са бонус:
				<strong>7 Hills Run</strong>, <strong>Buhovo Half Marathon</strong> и
				<strong>The Cactus Run</strong>. В тях точките не зависят от категорията
				на дистанцията, а от мястото ѝ сред дистанциите на самото състезание —
				най-дългата носи най-много.
			</p>
			<div class="tsr-results-wrap">
				<table class="tsr-results tsr-summary-table">
					<thead>
						<tr>
							<th>Дистанция в състезанието</th>
							<th>Класирани места</th>
							<th>Макс. точки</th>
							<th>Формула</th>
						</tr>
					</thead>
					<tbody>
						<tr><td>Най-дългата</td><td>Топ 20</td><td><strong>20</strong></td><td><code>21 &minus; място</code></td></tr>
						<tr><td>Втората по дължина</td><td>Топ 15</td><td><strong>15</strong></td><td><code>16 &minus; място</code></td></tr>
						<tr><td>Третата</td><td>Топ 10</td><td><strong>10</strong></td><td><code>11 &minus; място</code></td></tr>
						<tr><td>Четвъртата</td><td>Топ 5</td><td><strong>5</strong></td><td><code>6 &minus; място</code></td></tr>
					</tbody>
				</table>
			</div>
			<p>Така за сезон 2026 максималните точки са:</p>
			<div class="tsr-results-wrap">
				<table class="tsr-results tsr-summary-table">
					<thead>
						<tr><th>Състезание</th><th>20 точки</th><th>15 точки</th><th>10 точки</th><th>5 точки</th></tr>
					</thead>
					<tbody>
						<tr><td><strong>7 Hills Run</strong></td><td>26 км</td><td>19 км</td><td>13 км</td><td>6 км</td></tr>
						<tr><td><strong>Buhovo Half Marathon</strong></td><td>21 км</td><td>10 км</td><td>—</td><td>—</td></tr>
						<tr><td><strong>The Cactus Run</strong></td><td>21 км</td><td>15 км</td><td>7 км</td><td>—</td></tr>
					</tbody>
				</table>
			</div>
			<p>
				Пример: победителят на 7 км в The Cactus Run получава 10 точки вместо
				5, а 20-ият на 26 км в 7 Hills Run получава 1 точка.
			</p>
		</section>

		<!-- ─── General rules ────────────────────────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Общи правила</h2>
			<ul class="tsr-rules-list">
				<li>
					<strong>Два ранкинга</strong> — един за мъже и един за жени, по
					едни и същи правила.
				</li>
				<li>
					<strong>Без задължителни старта</strong> — натрупват се точки от
					всички завършени старта в сезона.
				</li>
				<li>
					<strong>Само финиш носи точки</strong> — DNS, DNF, DSQ и OTL не
					носят точки и не влизат в класирането.
				</li>
				<li>
					<strong>Категорията е на дистанцията, не на пола</strong> — всяка
					дистанция на дадено събитие се класифицира веднъж.
				</li>
				<li>
					<strong>При равни точки</strong> — предимство има участникът с
					повече подиуми (места от 1 до 3); при равен брой подиуми — с повече
					първи, после втори, после трети места.
				</li>
			</ul>
		</section>

		<!-- ─── Summary table ─────────────────────────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Обобщение</h2>
			<div class="tsr-results-wrap">
				<table class="tsr-results tsr-summary-table">
					<thead>
						<tr>
							<th>Категория</th>
							<th>Тип дистанция</th>
							<th>Класирани места</th>
							<th>Макс. точки</th>
							<th>Формула</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--a">A</span></td>
							<td>Къса (под 8 км)</td>
							<td>Топ 5</td>
							<td><strong>5</strong></td>
							<td><code>6 &minus; място</code></td>
						</tr>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--b">B</span></td>
							<td>Средна (8–13,9 км)</td>
							<td>Топ 10</td>
							<td><strong>10</strong></td>
							<td><code>11 &minus; място</code></td>
						</tr>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--c">C</span></td>
							<td>Дълга (14 км и повече)</td>
							<td>Топ 15</td>
							<td><strong>15</strong></td>
							<td><code>16 &minus; място</code></td>
						</tr>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--d">★</span></td>
							<td>Бонус състезание</td>
							<td>Топ 20 / 15 / 10 / 5</td>
							<td><strong>20 / 15 / 10 / 5</strong></td>
							<td>според реда на дистанцията</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

	</div><!-- .tsr-container -->
</main>

<?php get_footer(); ?>
