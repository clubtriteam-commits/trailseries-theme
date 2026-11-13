<?php
declare( strict_types=1 );
/**
 * Serve ts_result posts at the site root: /{post_name}/ (ADR-003).
 *
 * WordPress cannot do this through register_post_type() alone: an empty
 * rewrite slug is replaced by the post type name in core
 * (WP_Post_Type::set_props()), so the CPT's own rules live under
 * /ts_result/. Two filters close the gap:
 *
 * - `request`: the root rule of the /%postname%/ structure yields a bare
 *   `name` query var. Pages are matched before it (verbose page rules), so
 *   by the time `name` arrives no page owns the slug. If no regular post
 *   owns it either and a ts_result does, the query is pointed at ts_result.
 *   Posts win over results on a clash, so existing news URLs never change.
 * - `post_type_link`: permalinks (and therefore canonical tags, the sitemap
 *   and redirect_canonical) use /{post_name}/, not /ts_result/{post_name}/.
 *   Requests for the old /ts_result/… form still resolve via the CPT rules
 *   and are 301'd to the root URL by redirect_canonical.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Routing {

	private function __construct() {}

	public static function init(): void {
		add_filter( 'request', array( self::class, 'route_root_slug' ) );
		add_filter( 'post_type_link', array( self::class, 'permalink' ), 10, 3 );
		// No prefix-match "did you mean" 301s on 404 (ADR-003 rules 5 and 6):
		// a legacy URL that does not resolve exactly must show up as a 404 in
		// the crawl diff and the logs, not land silently on a sibling page.
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
	}

	/**
	 * @param array<string,mixed> $query_vars
	 * @return array<string,mixed>
	 */
	public static function route_root_slug( array $query_vars ): array {
		if ( empty( $query_vars['name'] ) || ! is_string( $query_vars['name'] )
			|| isset( $query_vars['post_type'] ) || isset( $query_vars['pagename'] )
			|| str_contains( $query_vars['name'], '/' )
		) {
			return $query_vars;
		}

		global $wpdb;
		$slug  = sanitize_title_for_query( $query_vars['name'] );
		$types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_type FROM {$wpdb->posts}
				  WHERE post_name = %s AND post_type IN ( 'post', %s )
				    AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )",
				$slug,
				TSR_Post_Types::POST_TYPE
			)
		);

		if ( in_array( TSR_Post_Types::POST_TYPE, $types, true ) && ! in_array( 'post', $types, true ) ) {
			$query_vars['post_type']                   = TSR_Post_Types::POST_TYPE;
			$query_vars[ TSR_Post_Types::POST_TYPE ] = $query_vars['name'];
		}

		return $query_vars;
	}

	public static function permalink( string $link, WP_Post $post, bool $leavename = false ): string {
		if ( TSR_Post_Types::POST_TYPE !== $post->post_type || '' === (string) get_option( 'permalink_structure' ) ) {
			return $link;
		}
		// Editor's sample permalink: keep the placeholder so the slug stays editable.
		if ( $leavename ) {
			return home_url( user_trailingslashit( '%' . TSR_Post_Types::POST_TYPE . '%' ) );
		}
		// Unpublished posts without a final slug keep core's ?ts_result=… / ?p=… form.
		if ( '' === $post->post_name || in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future' ), true ) ) {
			return $link;
		}
		return home_url( user_trailingslashit( $post->post_name ) );
	}
}
