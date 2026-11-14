<?php
/**
 * Footer template — overrides the Exhibz parent theme.
 *
 * Site footer (redesign 2026-11-14, owner-approved): brand, site map
 * columns, contact, copyright and photo credit. Replaces the parent
 * (exhibz) footer, which hardcodes "© 2022 Exhibz".
 *
 * wp_footer() MUST be called here — plugins and the theme hook into it.
 *
 * @package exhibz-child
 */
?>
<footer class="site-footer tsr-footer">
	<div class="tsr-container">
		<div class="tsr-footer__grid">
			<div class="tsr-footer__brand">
				<?php
				$tsr_logo_id = (int) get_theme_mod( 'custom_logo' );
				if ( $tsr_logo_id ) {
					echo wp_get_attachment_image( $tsr_logo_id, 'thumbnail', false, array( 'class' => 'tsr-footer__logo', 'alt' => '', 'loading' => 'lazy' ) );
				}
				?>
				<p class="tsr-footer__word">Trail<span>Series</span>.bg</p>
				<p class="tsr-footer__tag">Серия планинско бягане около София · от 2012 г.</p>
			</div>

			<nav class="tsr-footer__col" aria-labelledby="tsr-f-map">
				<h2 class="tsr-footer__h" id="tsr-f-map">Карта на сайта</h2>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Начало</a></li>
					<li><a href="<?php echo esc_url( home_url( '/istoriya/' ) ); ?>">История</a></li>
					<li><a href="<?php echo esc_url( home_url( '/pravila/' ) ); ?>">Правила</a></li>
					<li><a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>">Календар</a></li>
					<li><a href="<?php echo esc_url( home_url( '/novini/' ) ); ?>">Новини</a></li>
					<li><a href="<?php echo esc_url( home_url( '/partniori/' ) ); ?>">Партньори</a></li>
				</ul>
			</nav>

			<nav class="tsr-footer__col" aria-labelledby="tsr-f-races">
				<h2 class="tsr-footer__h" id="tsr-f-races">Състезания</h2>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/rezultati/' ) ); ?>">Резултати</a></li>
					<li><a href="<?php echo esc_url( home_url( '/klasiraniya/' ) ); ?>">Ранкинг</a></li>
					<li><a href="<?php echo esc_url( home_url( '/traseta/' ) ); ?>">Трасета</a></li>
					<li><a href="<?php echo esc_url( home_url( '/rekordi/' ) ); ?>">Рекорди</a></li>
				</ul>
			</nav>

			<div class="tsr-footer__col">
				<h2 class="tsr-footer__h">Контакт</h2>
				<ul>
					<li><a href="tel:+359877411246">0877 411 246</a></li>
					<li><a href="mailto:info@trailseries.bg">info@trailseries.bg</a></li>
				</ul>
			</div>
		</div>

		<div class="tsr-footer__bottom">
			<p class="tsr-footer__copy">&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> TrailSeries.bg · Всички права запазени</p>
			<p class="tsr-footer__copy">Снимки: KrisoK Photography</p>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
