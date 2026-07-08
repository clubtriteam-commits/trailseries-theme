<?php
/**
 * Site header — all pages.
 *
 * Single-row layout: [logo] [TrailSeries.bg] ........... [nav menu]
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
	<div class="tsr-container tsr-header-inner">

		<div class="tsr-header-brand">
			<?php if ( has_custom_logo() ) : ?>
			<div class="tsr-header-logo">
				<?php the_custom_logo(); ?>
			</div>
			<?php endif; ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tsr-header-name__link" rel="home">
				<span class="tsr-header-name__title">TrailSeries<span class="tsr-header-name__tld">.bg</span></span>
			</a>
		</div>

		<nav class="tsr-header-nav" aria-label="<?php esc_attr_e( 'Основна навигация', 'exhibz-child' ); ?>">
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
		</nav>

	</div>
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
