<?php
declare( strict_types=1 );
/**
 * Archive template — used for the Новини posts archive.
 *
 * WordPress uses this file for standard post archives when no more
 * specific template (e.g. category.php) exists. Set the "Posts page"
 * in Settings → Reading to a page with slug "novini" to activate it.
 *
 * @package exhibz-child
 */

get_header();

$tsr_archive_title = get_the_archive_title() ?: 'Новини';
$tsr_archive_desc  = get_the_archive_description();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">
			<?php echo wp_kses_post( $tsr_archive_title ); ?>
		</h1>
		<?php if ( $tsr_archive_desc ) : ?>
			<p class="tsr-page-hero__subtitle">
				<?php echo wp_kses_post( $tsr_archive_desc ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php if ( have_posts() ) : ?>
			<div class="tsr-grid tsr-news-archive">
				<?php
				while ( have_posts() ) :
					the_post();
					$tsr_thumb = get_the_post_thumbnail_url( null, 'medium' );
					?>
					<article class="tsr-card" id="post-<?php the_ID(); ?>">
						<?php if ( $tsr_thumb ) : ?>
							<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
								<img class="tsr-card__thumb"
								     src="<?php echo esc_url( $tsr_thumb ); ?>"
								     alt=""
								     loading="lazy"
								     decoding="async">
							</a>
						<?php endif; ?>
						<div class="tsr-card__body">
							<p class="tsr-card__meta">
								<?php echo esc_html( get_the_date( 'j F Y' ) ); ?>
							</p>
							<h2 class="tsr-card__title">
								<a href="<?php the_permalink(); ?>"
								   style="color:inherit;text-decoration:none;">
									<?php the_title(); ?>
								</a>
							</h2>
							<p class="tsr-card__meta">
								<?php
								$excerpt = get_the_excerpt();
								echo esc_html( wp_trim_words( $excerpt, 22, '…' ) );
								?>
							</p>
							<a class="tsr-card__link" href="<?php the_permalink(); ?>">
								Прочети
							</a>
						</div>
					</article>
				<?php endwhile; ?>
			</div>

			<!-- Pagination -->
			<nav class="tsr-pagination" aria-label="Навигация по страници">
				<?php
				the_posts_pagination(
					array(
						'prev_text' => '← По-стари',
						'next_text' => 'По-нови →',
						'class'     => 'tsr-pagination__nav',
					)
				);
				?>
			</nav>

		<?php else : ?>
			<section class="tsr-prose-section">
				<p class="tsr-empty">Все още няма публикувани новини.</p>
			</section>
		<?php endif; ?>

	</div>
</main>

<?php get_footer(); ?>
