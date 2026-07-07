<?php
/**
 * Site header — all pages.
 *
 * Overrides the Exhibz parent theme header entirely. Layout (top to bottom):
 *   Row 1 — logo (left) + site name / subtitle / tagline block (right of logo)
 *   Row 2 — primary navigation menu
 *
 * @package exhibz-child
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Към съдържанието', 'exhibz-child' ); ?></a>

<header id="masthead" class="tsr-site-header" role="banner">

	<!-- Row 1: logo + site name block -->
	<div class="tsr-container tsr-header-brand">
		<?php if ( has_custom_logo() ) : ?>
		<div class="tsr-header-logo">
			<?php the_custom_logo(); ?>
		</div>
		<?php endif; ?>

		<div class="tsr-header-name">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tsr-header-name__link" rel="home">
				<span class="tsr-header-name__title">TrailSeries<span class="tsr-header-name__tld">.bg</span></span>
			</a>
			<p class="tsr-header-name__subtitle">Серия планинско бягане · България</p>
			<p class="tsr-header-name__tagline">От 2012 година</p>
		</div>
	</div>

	<!-- Row 2: primary navigation -->
	<nav class="tsr-header-nav" aria-label="<?php esc_attr_e( 'Основна навигация', 'exhibz-child' ); ?>">
		<div class="tsr-container">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'menu_class'     => 'tsr-nav-list',
					'container'      => false,
					'fallback_cb'    => '__return_false',
				)
			);
			?>
		</div>
	</nav>

</header><!-- #masthead -->
<script>
(function () {
	var h = document.getElementById( 'masthead' );
	if ( ! h ) { return; }
	window.addEventListener( 'scroll', function () {
		h.classList.toggle( 'tsr-header--scrolled', window.scrollY > 80 );
	}, { passive: true } );
}());
</script>
