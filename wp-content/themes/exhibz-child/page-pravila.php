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

<main class="tsr-page-content">
	<div class="tsr-container">

		<!-- ─── Intro ──────────────────────────────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Как работи класирането?</h2>
			<p>
				TrailSeries.bg събира резултатите от всички включени състезания в сезона
				и изгражда едно общо класиране за мъже и жени заедно. Точките се
				присъждат спрямо заетото място в съответната категория дистанция.
				Класира се всеки финишър — без значение пол или възраст.
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
				Всяко включено състезание се класифицира в една от четирите категории
				дистанции. Категорията определя максималния брой точки и броя на
				класираните места.
			</p>

			<div class="tsr-scoring-grid">

				<div class="tsr-scoring-card tsr-scoring-card--short">
					<div class="tsr-scoring-card__badge">Категория A</div>
					<h3 class="tsr-scoring-card__title">Къса дистанция</h3>
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
					<h3 class="tsr-scoring-card__title">Средна дистанция</h3>
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
					<h3 class="tsr-scoring-card__title">Дълга дистанция</h3>
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

				<div class="tsr-scoring-card tsr-scoring-card--bonus">
					<div class="tsr-scoring-card__badge">Категория D</div>
					<h3 class="tsr-scoring-card__title">Бонус дълга дистанция</h3>
					<div class="tsr-scoring-card__max">макс <strong>20</strong> точки</div>
					<p class="tsr-scoring-card__formula">
						Формула: <code>21 &minus; място</code>
					</p>
					<table class="tsr-scoring-card__table">
						<thead>
							<tr><th>Място</th><th>Точки</th></tr>
						</thead>
						<tbody>
							<?php for ( $i = 1; $i <= 20; $i++ ) : ?>
								<tr>
									<td><?php echo esc_html( $i ); ?></td>
									<td><strong><?php echo esc_html( 21 - $i ); ?></strong></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>

			</div><!-- .tsr-scoring-grid -->
		</section>

		<!-- ─── General rules ────────────────────────────────────────────────── -->
		<section class="tsr-prose-section">
			<h2>Общи правила</h2>
			<ul class="tsr-rules-list">
				<li>
					<strong>Едно общо класиране</strong> — мъже и жени се класират
					заедно при равни условия.
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
					по-голям брой финиши; при равенство — по-ранният краен резултат
					в сезона.
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
							<td>Къса</td>
							<td>Топ 5</td>
							<td><strong>5</strong></td>
							<td><code>6 &minus; място</code></td>
						</tr>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--b">B</span></td>
							<td>Средна</td>
							<td>Топ 10</td>
							<td><strong>10</strong></td>
							<td><code>11 &minus; място</code></td>
						</tr>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--c">C</span></td>
							<td>Дълга</td>
							<td>Топ 15</td>
							<td><strong>15</strong></td>
							<td><code>16 &minus; място</code></td>
						</tr>
						<tr>
							<td><span class="tsr-cat-badge tsr-cat-badge--d">D</span></td>
							<td>Бонус дълга</td>
							<td>Топ 20</td>
							<td><strong>20</strong></td>
							<td><code>21 &minus; място</code></td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

	</div><!-- .tsr-container -->
</main>

<?php get_footer(); ?>
