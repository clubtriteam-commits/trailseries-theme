<?php
/**
 * Single race-result page.
 *
 * Two render modes:
 *
 *  - Standalone: a section post with no extending siblings renders its own
 *    hero + table, exactly as before (linked from /rezultati/ etc.).
 *
 *  - Hub: a post whose slug other posts extend ("{slug}-{cat}") renders ALL
 *    those sections as an accordion — one per category. This preserves the
 *    old site's single-page-multi-category format at the exact legacy URL
 *    (SEO, iron rule 3), e.g. /baba-marta-run26-results/ shows 16КМ МЪЖЕ,
 *    16КМ ЖЕНИ, 10КМ МЪЖЕ, ... together.
 *
 * Slug reality: bulk-import derives "{page_slug}--{cat_part}" but
 * wp_insert_post()'s sanitize_title() collapses the double dash, so stored
 * sibling slugs are "{page_slug}-{cat_part}" (Cyrillic parts URL-encoded).
 * Verified against the migration manifest: no legacy page slug is a dash
 * prefix of another page's slug, so a hub only ever absorbs its own page's
 * sections. The only prefix-extension pairs WITHIN a page are duplicate
 * category headers ("...-мъже" / "...-мъже-2"); a direct visit to such a
 * section URL therefore renders a mini-hub with its continuation table —
 * intended, since "-2" files are the same category's second table.
 *
 * Siblings are matched by slug prefix, NOT by _tsr_event_base/_tsr_season
 * meta: the prefix exactly reconstructs the legacy page (meta could merge two
 * different legacy pages of the same event+season, duplicating content), and
 * it works even before `wp tsr backfill-meta` has run. Section order is post
 * ID ascending = bulk-import order = the old page's top-to-bottom order.
 *
 * The table itself comes from tsr_render_results() in the trailseries-results
 * plugin — the theme has no ability to alter columns, by design (ADR-002).
 *
 * @package exhibz-child
 */

get_header();

/**
 * Return all section posts of the legacy page this post heads, in original
 * page order (including the post itself), or [] when the post is not a hub.
 *
 * @param WP_Post $post Candidate hub post.
 * @return WP_Post[] Ordered sections, or empty array.
 */
function tsr_hub_sections( WP_Post $post ): array {
	global $wpdb;
	$sibling_ids = array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status = 'publish' AND post_name LIKE %s",
				$post->post_type,
				$wpdb->esc_like( $post->post_name . '-' ) . '%'
			)
		)
	);
	if ( array() === $sibling_ids ) {
		return array();
	}

	// The hub post was the page's first imported section; ID order = import
	// order = the legacy page's section order.
	$ids = array_merge( array( $post->ID ), $sibling_ids );
	sort( $ids );

	return array_values( array_filter( array_map( 'get_post', $ids ) ) );
}

/**
 * Category label for one section — bulk-import appends " — {category_raw}"
 * to the legacy page title, so take the part after the LAST em-dash.
 * Falls back to the slug's category part (everything after the hub slug,
 * URL-decoded for Cyrillic) when the title has no suffix.
 *
 * @param WP_Post $post     Section post.
 * @param string  $hub_slug post_name of the hub post heading this page.
 */
function tsr_section_label( WP_Post $post, string $hub_slug ): string {
	$pos = mb_strrpos( $post->post_title, ' — ' );
	if ( false !== $pos ) {
		$label = trim( mb_substr( $post->post_title, $pos + 3 ) );
		if ( '' !== $label ) {
			return $label;
		}
	}
	if ( str_starts_with( $post->post_name, $hub_slug . '-' ) ) {
		$cat_part = urldecode( substr( $post->post_name, strlen( $hub_slug ) + 1 ) );
		// Unnamed tables on very old pages produce "all", "all-2", ... parts.
		if ( preg_match( '/^all(?:-(\d+))?$/', $cat_part, $m ) ) {
			return isset( $m[1] ) ? 'Резултати — част ' . $m[1] : 'Резултати';
		}
		return mb_strtoupper( str_replace( '-', ' ', $cat_part ), 'UTF-8' );
	}
	return 'Резултати';
}

/**
 * Hub page heading: the legacy page title without this post's own
 * " — {category}" suffix (the old page's H1 had no category in it).
 */
function tsr_hub_title( WP_Post $post ): string {
	$pos = mb_strrpos( $post->post_title, ' — ' );
	return false !== $pos ? trim( mb_substr( $post->post_title, 0, $pos ) ) : $post->post_title;
}

while ( have_posts() ) :
	the_post();

	$tsr_sections = tsr_hub_sections( get_post() );
	$tsr_is_hub   = count( $tsr_sections ) > 1;
	?>
	<div class="tsr-page-hero">
		<div class="tsr-container">
			<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
			<h1 class="tsr-page-hero__title">
				<?php echo esc_html( $tsr_is_hub ? tsr_hub_title( get_post() ) : get_the_title() ); ?>
			</h1>
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

				<?php if ( $tsr_is_hub ) : ?>
					<div class="tsr-result-hub">
						<?php foreach ( $tsr_sections as $tsr_i => $tsr_section ) : ?>
							<details class="tsr-result-hub__section"<?php echo 0 === $tsr_i ? ' open' : ''; ?>>
								<summary class="tsr-result-hub__summary">
									<?php echo esc_html( tsr_section_label( $tsr_section, get_post()->post_name ) ); ?>
								</summary>
								<?php
								if ( function_exists( 'tsr_render_results' ) ) {
									echo tsr_render_results( $tsr_section->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin renderer escapes all output.
								}
								?>
							</details>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<?php
					if ( function_exists( 'tsr_render_results' ) ) {
						echo tsr_render_results( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin renderer escapes all output.
					}
					?>
				<?php endif; ?>

			</article>
		</div>
	</main>
	<?php
endwhile;

get_footer();