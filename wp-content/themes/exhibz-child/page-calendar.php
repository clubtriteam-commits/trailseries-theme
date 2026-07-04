<?php
/**
 * Template Name: Календар
 *
 * Template for the Календар page (slug: calendar).
 *
 * Two sections: upcoming events (next 6 months) and past events this year,
 * separated by a visual divider. Rendered via the [add_eventon_list]
 * shortcode. If EventON is not active a friendly notice is shown instead.
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

		<?php if ( shortcode_exists( 'add_eventon_list' ) ) : ?>

			<!-- Upcoming events -->
			<section class="tsr-prose-section tsr-cal-upcoming">
				<h2 class="tsr-cal-section__title">Предстоящи</h2>
				<?php echo do_shortcode( '[add_eventon_list number_of_months="6" hide_past="yes" hide_empty_months="yes"]' ); ?>
			</section>

			<hr class="tsr-cal-divider">

			<!-- Past events this year -->
			<section class="tsr-prose-section tsr-cal-past">
				<h2 class="tsr-cal-section__title tsr-cal-section__title--past">Изминали тази година</h2>
				<?php echo do_shortcode( '[add_eventon_list number_of_months="-6" hide_past="no" hide_empty_months="yes" show_upcoming="no"]' ); ?>
			</section>

		<?php else : ?>
			<section class="tsr-prose-section">
				<div class="tsr-notice">
					<p>Календарът временно не е достъпен. Следете новините ни за предстоящите състезания.</p>
				</div>
			</section>
		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
