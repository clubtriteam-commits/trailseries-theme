<?php
/**
 * Template Name: Календар
 *
 * Template for the Календар page (slug: calendar).
 *
 * Renders the EventON calendar via the [add_eventon] shortcode. If EventON
 * is not active the shortcode is left unexpanded by WordPress, so a guard
 * shows a friendly notice instead.
 *
 * @package exhibz-child
 */

declare( strict_types=1 );

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Календар</h1>
		<p class="tsr-page-hero__subtitle">
			Предстоящи състезания от сериите — дати, локации и регистрация
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<section class="tsr-prose-section">
			<?php if ( shortcode_exists( 'add_eventon' ) ) : ?>
				<?php echo do_shortcode( '[add_eventon]' ); ?>
			<?php else : ?>
				<div class="tsr-notice">
					<p>Календарът временно не е достъпен. Следете новините ни за предстоящите състезания.</p>
				</div>
			<?php endif; ?>
		</section>

	</div>
</main>

<?php
get_footer();
