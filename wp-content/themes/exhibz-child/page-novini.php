<?php
declare( strict_types=1 );
/**
 * Новини — news listing on the static page with slug "novini".
 *
 * WHY THIS FILE: /novini/ is a WP *Page* (ID 1540), not a category
 * archive — the child theme's archive.php only serves real archive
 * queries (/category/новини/), so the page fell through to the parent
 * theme's page.php and rendered its widget sidebar. page-{slug}.php
 * outranks page.php in the template hierarchy, which makes this fire
 * for exactly this page with no admin/routing changes. A
 * category-{slug}.php was rejected: the category slug is the
 * percent-encoded Cyrillic string, and it still wouldn't apply to
 * /novini/.
 *
 * Lists all posts in the Новини category (resolved by name — term IDs
 * differ between live and staging), excluding Zero to HERO, newest
 * first, 9 per page with /novini/page/N/ pagination.
 *
 * @package exhibz-child
 */

// ── Query ─────────────────────────────────────────────────────────────────────

// Static pages carry /page/N/ in 'paged' but /novini/N/ in 'page' — accept both.
$tsr_paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

// Resolve categories by name/slug, never hardcoded IDs (live id=12, staging id=8).
$tsr_news_cat = get_term_by( 'name', 'Новини', 'category' );
$tsr_zero_cat = get_category_by_slug( 'zero-to-hero' );

$tsr_query_args = array(
	'post_type'        => 'post',
	'post_status'      => 'publish',
	'posts_per_page'   => 9,
	'paged'            => $tsr_paged,
	'orderby'          => 'date',
	'order'            => 'DESC',
	'category__not_in' => $tsr_zero_cat instanceof WP_Term ? array( (int) $tsr_zero_cat->term_id ) : array(),
);
if ( $tsr_news_cat instanceof WP_Term ) {
	$tsr_query_args['category__in'] = array( (int) $tsr_news_cat->term_id );
}
// When the Новини term is missing (shouldn't happen), the query degrades to
// "all posts except Zero to HERO" instead of an empty page.

$tsr_news_q = new WP_Query( $tsr_query_args );

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Новини</h1>
		<p class="tsr-page-hero__subtitle">
			Новини и истории от планинските бягания на сериите
		</p>
	</div>
</div>

<main id="main" class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Новини' ); ?>

		<?php if ( $tsr_news_q->have_posts() ) : ?>
			<div class="tsr-grid tsr-news-archive">
				<?php
				while ( $tsr_news_q->have_posts() ) :
					$tsr_news_q->the_post();
					$tsr_thumb = get_the_post_thumbnail_url( null, 'medium_large' )
						?: get_the_post_thumbnail_url( null, 'full' );
					// Same excerpt hardening as the front page: a manual
					// post_excerpt is used verbatim by get_the_excerpt(), so
					// leftover unregistered shortcode markup must be stripped
					// by pattern, not just by strip_shortcodes().
					$tsr_source  = '' !== trim( (string) get_post()->post_excerpt )
						? get_post()->post_excerpt
						: get_post()->post_content;
					$tsr_excerpt = wp_trim_words(
						wp_strip_all_tags( tsr_strip_shortcode_syntax( strip_shortcodes( $tsr_source ) ) ),
						22,
						'…'
					);
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
								<?php echo esc_html( $tsr_excerpt ); ?>
							</p>
							<a class="tsr-card__link" href="<?php the_permalink(); ?>">
								Прочети →
							</a>
						</div>
					</article>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			</div>

			<?php if ( $tsr_news_q->max_num_pages > 1 ) : ?>
				<nav class="tsr-pagination" aria-label="Навигация по страници">
					<div class="nav-links">
						<?php
						// paginate_links infers /novini/page/N/ from the current
						// URL; total/current come from the custom query. The
						// .nav-links wrapper reuses the styles written for
						// the_posts_pagination() in archive.php.
						echo wp_kses_post(
							(string) paginate_links(
								array(
									'total'     => (int) $tsr_news_q->max_num_pages,
									'current'   => $tsr_paged,
									'prev_text' => '← По-нови',
									'next_text' => 'По-стари →',
									'mid_size'  => 2,
								)
							)
						);
						?>
					</div>
				</nav>
			<?php endif; ?>

		<?php else : ?>
			<section class="tsr-prose-section">
				<p class="tsr-empty">Все още няма публикувани новини.</p>
			</section>
		<?php endif; ?>

	</div>
</main>

<?php get_footer(); ?>
