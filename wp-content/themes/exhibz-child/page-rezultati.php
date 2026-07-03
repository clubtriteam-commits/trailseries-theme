<?php
/**
 * Template Name: Резултати
 *
 * Template for the Резултати archive page (slug: rezultati).
 *
 * Queries all published ts_result posts, groups them first by season (year
 * from post_date, newest first) then by event name (post_title before the
 * " — " separator that bulk-import appends for the category). Each year is
 * a native <details> accordion — open by default for the current year, closed
 * for older years. No JavaScript needed.
 *
 * Within each event group the "primary" post (the one whose slug contains no
 * "--" separator — i.e. the bare legacy slug preserved for SEO) becomes the
 * group-header link; the remaining category posts are listed as pill links.
 *
 * @package exhibz-child
 */

declare( strict_types=1 );

get_header();

// ── 1. Fetch all published ts_result posts ──────────────────────────────────
$all_posts = get_posts( array(
	'post_type'        => 'ts_result',
	'posts_per_page'   => -1,
	'post_status'      => 'publish',
	'orderby'          => array( 'date' => 'DESC', 'title' => 'ASC' ),
	'suppress_filters' => false,
) );

// ── 2. Group: year → event_name → [ WP_Post, … ] ───────────────────────────
$grouped = array();

foreach ( $all_posts as $post ) {
	$year = get_the_date( 'Y', $post );

	// Strip the " — Category" suffix bulk-import appends to get the event name.
	$sep        = mb_strpos( $post->post_title, ' — ' );
	$event_name = false !== $sep
		? mb_substr( $post->post_title, 0, $sep )
		: $post->post_title;

	$grouped[ $year ][ $event_name ][] = $post;
}

// Years newest-first; event order already date-DESC from the query.
krsort( $grouped );

$current_year = (int) gmdate( 'Y' );
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Резултати</h1>
		<p class="tsr-page-hero__subtitle">
			Всички резултати от сезоните — по година и дистанция
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php if ( empty( $grouped ) ) : ?>
			<div class="tsr-notice">
				<p>Все още няма публикувани резултати. Проверете отново след първото състезание.</p>
			</div>

		<?php else : ?>

			<div class="tsr-results-archive">

				<?php foreach ( $grouped as $year => $events ) : ?>
					<?php $is_current = ( (int) $year === $current_year ); ?>

					<details class="tsr-year-group" <?php echo $is_current ? 'open' : ''; ?>>
						<summary class="tsr-year-group__summary">
							<span class="tsr-year-group__year"><?php echo esc_html( (string) $year ); ?></span>
							<span class="tsr-year-group__count">
								<?php
								$n = 0;
								foreach ( $events as $event_posts ) {
									$n += count( $event_posts );
								}
								/* translators: %d = number of result sets */
								printf( esc_html( _n( '%d класиране', '%d класирания', $n, 'exhibz-child' ) ), $n );
								?>
							</span>
						</summary>

						<ul class="tsr-event-list">

							<?php foreach ( $events as $event_name => $event_posts ) : ?>

								<?php
								// Primary post: bare slug (no "--" separator) = the SEO-preserved URL.
								$primary = null;
								$cats    = array();

								foreach ( $event_posts as $p ) {
									if ( false === strpos( $p->post_name, '--' ) ) {
										$primary = $p;
									} else {
										$cats[] = $p;
									}
								}
								// Fallback: if every post has "--" (shouldn't happen) use the first.
								if ( null === $primary ) {
									$primary = array_shift( $event_posts );
								}
								// Sort category posts alphabetically by their label.
								usort( $cats, static fn( $a, $b ) => strcmp( $a->post_title, $b->post_title ) );
								?>

								<li class="tsr-event-item">

									<a class="tsr-event-item__name"
									   href="<?php echo esc_url( get_permalink( $primary ) ); ?>">
										<?php echo esc_html( $event_name ); ?>
									</a>

									<?php if ( ! empty( $cats ) ) : ?>
										<ul class="tsr-cat-list">
											<?php foreach ( $cats as $cat_post ) : ?>
												<?php
												$cat_sep   = mb_strpos( $cat_post->post_title, ' — ' );
												$cat_label = false !== $cat_sep
													? mb_substr( $cat_post->post_title, $cat_sep + 3 )
													: $cat_post->post_title;
												?>
												<li>
													<a class="tsr-cat-pill"
													   href="<?php echo esc_url( get_permalink( $cat_post ) ); ?>">
														<?php echo esc_html( $cat_label ); ?>
													</a>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>

								</li>

							<?php endforeach; ?>

						</ul><!-- .tsr-event-list -->

					</details><!-- .tsr-year-group -->

				<?php endforeach; ?>

			</div><!-- .tsr-results-archive -->

		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
