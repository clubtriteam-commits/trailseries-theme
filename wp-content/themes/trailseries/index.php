<?php
/**
 * Generic fallback template.
 *
 * @package trailseries
 */

get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class(); ?>>
			<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
			<div class="entry-content"><?php the_excerpt(); ?></div>
		</article>
		<?php
	endwhile;

	the_posts_pagination();
else :
	?>
	<p><?php esc_html_e( 'Nothing found.', 'trailseries' ); ?></p>
	<?php
endif;

get_footer();
