<?php
declare( strict_types=1 );
/**
 * Generic page fallback — tsr chrome for pages with no specific template.
 *
 * The 158 migrated event landing pages (import-event-pages.php) have no
 * slug-matched template, so without this file they fell through to the
 * Exhibz PARENT theme's page.php and rendered raw, unstyled content. This
 * child override gives every default-template page the standard hero,
 * breadcrumbs and .tsr-page-content wrapper.
 *
 * Template hierarchy note: page-{slug}.php (novini, sabitia, calendar, …)
 * and assigned templates (Класирания, Трасета, …) all outrank page.php,
 * so this file only ever serves pages without a more specific match —
 * exactly the migrated landing pages plus future generic pages.
 *
 * @package exhibz-child
 */

get_header();

while ( have_posts() ) :
	the_post();

	// Migrated event landing pages carry the import's _tsr_live_id meta and
	// sit under Начало / Събития in the breadcrumb trail (whether flat like
	// /lyulin-trail-run26/ or hierarchical under /sabitia/); everything else
	// gets the plain two-level trail.
	$tsr_is_event = '' !== (string) get_post_meta( get_the_ID(), '_tsr_live_id', true );
	?>

	<div class="tsr-page-hero">
		<div class="tsr-container">
			<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
			<h1 class="tsr-page-hero__title"><?php the_title(); ?></h1>
		</div>
	</div>

	<main id="main" class="tsr-page-content">
		<div class="tsr-container">

			<?php
			if ( $tsr_is_event ) {
				tsr_page_breadcrumbs(
					get_the_title(),
					array(
						array(
							'label' => 'Събития',
							'url'   => home_url( '/sabitia/' ),
						),
					)
				);
			} else {
				tsr_page_breadcrumbs( get_the_title() );
			}
			?>

			<section class="tsr-prose-section tsr-page-generic">
				<?php
				// Legacy 2012-era markup often has unclosed tags; an unbalanced
				// <div> swallows everything after it — including the footer,
				// which is why migrated pages appeared to render without one
				// (get_footer() below always ran). Balance AFTER the_content
				// filters so shortcodes/embeds expand first and their output
				// is balanced too.
				echo force_balance_tags( (string) apply_filters( 'the_content', get_the_content() ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output, filtered + balanced.
				?>
			</section>

		</div>
	</main>

	<?php
endwhile;

get_footer();
