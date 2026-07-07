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
	<article <?php post_class(); ?>>
		<h1 class="entry-title"><?php echo esc_html( get_the_title() ); ?></h1>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>

		<?php
		if ( function_exists( 'tsr_render_results' ) ) {
			echo tsr_render_results( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin renderer escapes all output.
		}
		?>
	</article>
	<?php
endwhile;

get_footer();
