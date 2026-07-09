<?php
/**
 * Single race-result page.
 *
 * The table itself comes from tsr_render_results() in the trailseries-results
 * plugin — the theme has no ability to alter columns, by design (ADR-002).
 *
 * @package exhibz-child
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<div class="tsr-page-hero">
		<div class="tsr-container">
			<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
			<h1 class="tsr-page-hero__title"><?php echo esc_html( get_the_title() ); ?></h1>
		</div>
	</div>

	<main class="tsr-page-content">
		<div class="tsr-container">
			<article <?php post_class( 'tsr-prose-section' ); ?>>

				<?php if ( trim( (string) get_the_content() ) !== '' ) : ?>
					<div class="entry-content">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>

				<?php
				if ( function_exists( 'tsr_render_results' ) ) {
					echo tsr_render_results( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin renderer escapes all output.
				}
				?>
			</article>
		</div>
	</main>
	<?php
endwhile;

get_footer();
