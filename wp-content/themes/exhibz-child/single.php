<?php
declare( strict_types=1 );
/**
 * Single post template — overrides the Exhibz parent theme.
 *
 * Layout: tsr-page-hero (post title) + tsr-page-content (post body).
 * Matches the design of other page templates in this child theme.
 *
 * @package exhibz-child
 */

get_header();
?>

<?php while ( have_posts() ) : the_post(); ?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">
			<?php
			$categories = get_the_category();
			echo $categories ? esc_html( $categories[0]->name ) : 'TrailSeries.bg';
			?>
		</p>
		<h1 class="tsr-page-hero__title"><?php the_title(); ?></h1>
		<p class="tsr-page-hero__subtitle">
			<?php echo esc_html( get_the_date( 'd.m.Y' ) ); ?>
		</p>
	</div>
</div>

<main class="tsr-page-content" id="main">
	<div class="tsr-container">
		<article class="tsr-prose-section" id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

			<?php if ( has_post_thumbnail() ) : ?>
			<div class="tsr-single-thumbnail">
				<?php the_post_thumbnail( 'large' ); ?>
			</div>
			<?php endif; ?>

			<div class="tsr-single-content">
				<?php the_content(); ?>
			</div>

			<?php
			wp_link_pages(
				array(
					'before' => '<nav class="tsr-page-links">',
					'after'  => '</nav>',
				)
			);
			?>

		</article>
	</div>
</main>

<?php endwhile; ?>

<?php get_footer(); ?>
