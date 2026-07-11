<?php
declare( strict_types=1 );
/**
 * Template Name: Партньори
 *
 * Template for the Партньори page (slug: partniori).
 *
 * Flat single-tier grid of partner logos from the ts_partner CPT (see
 * functions.php): title = name, featured image = logo, `_tsr_partner_url`
 * meta = link target. Partners without an uploaded logo render as a
 * placeholder box with the partner's initials, so the layout can be
 * verified before the real artwork is in the media library.
 *
 * Ordered by menu_order (the "Order" box in wp-admin), then title.
 *
 * @package exhibz-child
 */

get_header();

$tsr_partners = get_posts(
	array(
		'post_type'   => 'ts_partner',
		'numberposts' => -1,
		'post_status' => 'publish',
		'orderby'     => array(
			'menu_order' => 'ASC',
			'title'      => 'ASC',
		),
	)
);

/**
 * Initials for the placeholder logo box: first letter of the first two
 * words ("The Barbarian" → "TB", "SLS" → "S").
 */
function tsr_partner_initials( string $name ): string {
	$words    = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	$initials = '';
	foreach ( array_slice( $words, 0, 2 ) as $word ) {
		$initials .= mb_substr( $word, 0, 1, 'UTF-8' );
	}
	return mb_strtoupper( $initials, 'UTF-8' );
}

/** Brand palette cycled across placeholder boxes. */
$tsr_ph_colors = array( '#00aadd', '#0a1628', '#e05c1e', '#0088bb', '#0d2040' );
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Партньори</h1>
		<p class="tsr-page-hero__subtitle">
			Марките и хората, които правят сериите възможни
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php if ( empty( $tsr_partners ) ) : ?>
			<p class="tsr-empty">Няма добавени партньори.</p>
		<?php else : ?>
			<section class="tsr-prose-section">
				<div class="tsr-partners-grid">
					<?php foreach ( $tsr_partners as $tsr_i => $tsr_partner ) : ?>
						<?php
						$tsr_url  = (string) get_post_meta( $tsr_partner->ID, '_tsr_partner_url', true );
						$tsr_name = get_the_title( $tsr_partner );
						$tsr_tag  = '' !== $tsr_url ? 'a' : 'div';
						?>
						<<?php echo $tsr_tag; // phpcs:ignore WordPress.Security.EscapeOutput -- 'a' or 'div' literal. ?>
							class="tsr-partner-card"
							<?php if ( '' !== $tsr_url ) : ?>
								href="<?php echo esc_url( $tsr_url ); ?>" target="_blank" rel="noopener sponsored"
							<?php endif; ?>
						>
							<?php if ( has_post_thumbnail( $tsr_partner ) ) : ?>
								<span class="tsr-partner-card__logo">
									<?php echo get_the_post_thumbnail( $tsr_partner, 'medium', array( 'alt' => $tsr_name ) ); ?>
								</span>
							<?php else : ?>
								<span class="tsr-partner-card__logo tsr-partner-card__logo--ph"
									style="background: <?php echo esc_attr( $tsr_ph_colors[ $tsr_i % count( $tsr_ph_colors ) ] ); ?>">
									<?php echo esc_html( tsr_partner_initials( $tsr_name ) ); ?>
								</span>
							<?php endif; ?>
							<span class="tsr-partner-card__name"><?php echo esc_html( $tsr_name ); ?></span>
						</<?php echo $tsr_tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="tsr-partner-cta">
			<h2 class="tsr-partner-cta__title">Стани партньор</h2>
			<p class="tsr-partner-cta__text">
				Подкрепи най-дълголетната трейл серия в България — 14 сезона,
				хиляди финиширали бегачи и общност, която расте с всяко издание.
				Пиши ни и ще намерим формата, който работи за твоята марка.
			</p>
			<a class="tsr-partner-cta__btn"
				href="mailto:clubtriteam@gmail.com?subject=<?php echo rawurlencode( 'Партньорство с TrailSeries.bg' ); ?>">
				Свържи се с нас
			</a>
		</section>

	</div>
</main>

<?php get_footer(); ?>
