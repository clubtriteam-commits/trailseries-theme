<?php
/**
 * Footer template — overrides the Exhibz parent theme.
 *
 * The parent (exhibz) footer.php hardcodes "© 2022 Exhibz". This child
 * override replaces it entirely with a dynamic copyright line that uses
 * the WP blog name and the current year.
 *
 * wp_footer() MUST be called here — plugins and the theme hook into it.
 *
 * @package exhibz-child
 */
?>
<footer class="site-footer tsr-footer">
	<div class="tsr-container">
		<p class="tsr-footer__copy">
			&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php bloginfo( 'name' ); ?>
			</a>
		</p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
