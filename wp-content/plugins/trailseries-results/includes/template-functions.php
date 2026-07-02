<?php
/**
 * Public template API. This is the entire surface the theme is allowed to use:
 * it accepts a post ID and returns the canonical table — no column options.
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the canonical results table for a ts_result post.
 *
 * @param int $post_id A ts_result post ID.
 * @return string Table HTML, or '' when the post has no results data.
 */
function tsr_render_results( int $post_id ): string {
	try {
		$set = TSR_Repository::load( $post_id );
	} catch ( Exception $e ) {
		// Out-of-schema data must never render half-broken. Surface loudly
		// to admins, render nothing to visitors.
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="tsr-error">' . esc_html(
				sprintf(
					/* translators: 1: post ID, 2: error message */
					__( 'Results data for post %1$d failed validation: %2$s', 'trailseries-results' ),
					$post_id,
					$e->getMessage()
				)
			) . '</p>';
		}
		return '';
	}

	if ( null === $set ) {
		return '';
	}

	return TSR_Renderer::table( $set );
}

add_shortcode(
	'trailseries_results',
	static function ( $atts ): string {
		$atts = shortcode_atts( array( 'id' => get_the_ID() ), $atts, 'trailseries_results' );
		$id   = (int) $atts['id'];
		return $id > 0 ? tsr_render_results( $id ) : '';
	}
);
