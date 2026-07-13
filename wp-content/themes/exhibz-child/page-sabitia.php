<?php
declare( strict_types=1 );
/**
 * Събития — index of all migrated event landing pages (slug: sabitia).
 *
 * The /sabitia/ page itself is created by migration/import-event-pages.php
 * as the parent for the seven hierarchical /sabitia/<slug>/ children; this
 * slug-matched template (page-{slug}.php, same mechanism as page-novini.php)
 * turns it into a season-grouped directory of every imported landing page.
 *
 * Pages are identified by the _tsr_live_id meta the import script stamps
 * on each migrated page (the /sabitia/ parent itself has none, so it can
 * never list itself). Grouping is by the calendar year of post_date —
 * preserved from the live site during migration — NOT by the season labels
 * page-rezultati.php uses: early seasons straddle two calendar years, and
 * mapping a page's calendar year onto a season label would file e.g. a
 * February 2013 event (season 1) under "Сезон 2 (2013–2014)".
 *
 * @package exhibz-child
 */

// ── Data: all migrated event landing pages, grouped by year ──────────────────

$tsr_event_pages = get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'meta_key'       => '_tsr_live_id',
		'meta_compare'   => 'EXISTS',
	)
);

/**
 * @var array<int, WP_Post[]> $tsr_by_year year => pages in chronological order
 */
$tsr_by_year = array();
foreach ( $tsr_event_pages as $tsr_ep ) {
	$tsr_by_year[ (int) get_the_date( 'Y', $tsr_ep ) ][] = $tsr_ep;
}
krsort( $tsr_by_year, SORT_NUMERIC );

$tsr_newest_year = (int) array_key_first( $tsr_by_year );

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Събития</h1>
		<p class="tsr-page-hero__subtitle">
			Всички събития от сериите — по години, от 2012 до днес
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Събития' ); ?>

		<?php if ( empty( $tsr_by_year ) ) : ?>

			<div class="tsr-notice tsr-notice--info">
				<p>Все още няма импортирани страници на събития.</p>
			</div>

		<?php else : ?>

			<div class="tsr-results-archive">

				<?php foreach ( $tsr_by_year as $tsr_year => $tsr_pages ) : ?>

					<details class="tsr-year-group" <?php echo $tsr_year === $tsr_newest_year ? 'open' : ''; ?>>
						<summary class="tsr-year-group__summary">
							<span class="tsr-year-group__year"><?php echo esc_html( (string) $tsr_year ); ?></span>
							<span class="tsr-year-group__count">
								<?php
								/* translators: %d = number of events in this year */
								printf( esc_html( _n( '%d събитие', '%d събития', count( $tsr_pages ), 'exhibz-child' ) ), count( $tsr_pages ) );
								?>
							</span>
						</summary>

						<ul class="tsr-event-list">
							<?php foreach ( $tsr_pages as $tsr_ep ) : ?>
								<li class="tsr-event-item">
									<a class="tsr-event-item__name"
									   href="<?php echo esc_url( get_permalink( $tsr_ep ) ); ?>">
										<?php echo esc_html( get_the_title( $tsr_ep ) ); ?>
									</a>
									<span class="tsr-sabitia-date">
										<?php echo esc_html( get_the_date( 'j F', $tsr_ep ) ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>

				<?php endforeach; ?>

			</div>

		<?php endif; ?>

	</div>
</main>

<?php get_footer(); ?>
