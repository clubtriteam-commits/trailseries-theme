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
			// "Trail Series" mega menu (owner 2026-11-14, after goldentrailseries.com's
			// "Series"): main pages as buttons + every race with its history and
			// latest results. The rest of the bar stays the admin menu
			// (Appearance → Menus).
			$tsr_mega_links = array(
				'Календар'             => '/calendar/',
				'Резултати'            => '/rezultati/',
				'Ранкинг'              => '/klasiraniya/',
				'Бегаческа статистика' => '/runner/',
				'Трасета'              => '/traseta/',
				'Правила'              => '/pravila/',
				'Новини'               => '/novini/',
				'Рекорди'              => '/rekordi/',
				'Партньори'            => '/partniori/',
			);
			ob_start();
			?>
			<li class="tsr-mega-item">
				<button class="tsr-mega-toggle" type="button" aria-expanded="false" aria-controls="tsr-mega">
					Trail Series <span class="tsr-mega-toggle__chev" aria-hidden="true"></span>
				</button>
				<div class="tsr-mega" id="tsr-mega" hidden>
					<div class="tsr-mega__head">
						<p class="tsr-mega__title">Trail Series</p>
						<a class="tsr-mega__more" href="<?php echo esc_url( home_url( '/klasiraniya/' ) ); ?>">Ранкинг <?php echo esc_html( gmdate( 'Y' ) ); ?> ›</a>
					</div>
					<ul class="tsr-mega__main">
						<?php foreach ( $tsr_mega_links as $tsr_label => $tsr_path ) : ?>
							<li><a href="<?php echo esc_url( home_url( $tsr_path ) ); ?>"><?php echo esc_html( $tsr_label ); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<?php $tsr_menu_races = tsr_menu_races(); ?>
					<?php if ( array() !== $tsr_menu_races ) : ?>
						<p class="tsr-mega__h">Състезания</p>
						<ul class="tsr-mega__races">
							<?php foreach ( $tsr_menu_races as $tsr_mr ) : ?>
								<li class="tsr-mega__race">
									<span class="tsr-mega__race-name"><?php echo esc_html( $tsr_mr['name'] ); ?></span>
									<span class="tsr-mega__race-month"><?php echo esc_html( $tsr_mr['month'] ); ?></span>
									<span class="tsr-mega__race-links">
										<a href="<?php echo esc_url( $tsr_mr['details'] ); ?>">Детайли<span class="screen-reader-text"> — <?php echo esc_html( $tsr_mr['name'] ); ?></span></a>
										<?php if ( '' !== $tsr_mr['results'] ) : ?>
											<a href="<?php echo esc_url( $tsr_mr['results'] ); ?>">Резултати<span class="screen-reader-text"> — <?php echo esc_html( $tsr_mr['name'] ); ?></span></a>
										<?php endif; ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</li>
			<?php
			$tsr_mega_li = (string) ob_get_clean();
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'menu_class'     => 'tsr-nav-list',
					'container'      => false,
					'fallback_cb'    => '__return_false',
					'items_wrap'     => '<ul id="%1$s" class="%2$s">' . str_replace( '%', '%%', $tsr_mega_li ) . '%3$s</ul>',
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
	// "Серия" mega menu: click toggles; on desktop hover opens it too.
	var megaItem = h.querySelector( '.tsr-mega-item' );
	if ( megaItem ) {
		var megaBtn = megaItem.querySelector( '.tsr-mega-toggle' );
		var mega    = megaItem.querySelector( '.tsr-mega' );
		var hoverOk = window.matchMedia( '(hover: hover) and (min-width: 769px)' );
		var closeT  = null;
		var setMega = function ( open ) {
			mega.hidden = ! open;
			megaBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			megaItem.classList.toggle( 'is-open', open );
		};
		megaBtn.addEventListener( 'click', function () { setMega( mega.hidden ); } );
		megaItem.addEventListener( 'mouseenter', function () { if ( hoverOk.matches ) { clearTimeout( closeT ); setMega( true ); } } );
		megaItem.addEventListener( 'mouseleave', function () { if ( hoverOk.matches ) { closeT = setTimeout( function () { setMega( false ); }, 200 ); } } );
		document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key && ! mega.hidden ) { setMega( false ); megaBtn.focus(); } } );
		document.addEventListener( 'click', function ( e ) { if ( ! megaItem.contains( e.target ) ) { setMega( false ); } } );
		megaItem.addEventListener( 'focusout', function ( e ) { if ( hoverOk.matches && ! megaItem.contains( e.relatedTarget ) ) { setMega( false ); } } );
	}

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
