<?php
/**
 * Site header — all pages.
 *
 * Single-row layout: [logo] ........... [nav menu]
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
		</div>

		<button class="tsr-nav-toggle" type="button"
		        aria-expanded="false" aria-controls="tsr-primary-nav"
		        aria-label="<?php esc_attr_e( 'Отвори менюто', 'exhibz-child' ); ?>">
			<span class="tsr-nav-toggle__bar" aria-hidden="true"></span>
			<span class="tsr-nav-toggle__bar" aria-hidden="true"></span>
			<span class="tsr-nav-toggle__bar" aria-hidden="true"></span>
		</button>

		<nav id="tsr-primary-nav" class="tsr-header-nav" aria-label="<?php esc_attr_e( 'Основна навигация', 'exhibz-child' ); ?>">
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

	// Mobile nav toggle.
	var toggle = h.querySelector( '.tsr-nav-toggle' );
	if ( ! toggle ) { return; }
	toggle.addEventListener( 'click', function () {
		var open = h.classList.toggle( 'tsr-nav-open' );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		toggle.setAttribute( 'aria-label', open ? 'Затвори менюто' : 'Отвори менюто' );
	} );
	// Close the menu when the viewport grows past the mobile breakpoint,
	// so a stale open state never lingers after rotation/resize.
	window.addEventListener( 'resize', function () {
		if ( window.innerWidth > 768 && h.classList.contains( 'tsr-nav-open' ) ) {
			h.classList.remove( 'tsr-nav-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );
		}
	} );
}());
</script>
