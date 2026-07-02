<?php
/**
 * Uninstall handler.
 *
 * Deliberately does NOT delete results data. Thirteen seasons of results are
 * the site's core asset; removing the plugin must never destroy them. If a
 * full purge is ever genuinely wanted, do it explicitly via WP-CLI with a
 * fresh backup in hand.
 *
 * @package trailseries-results
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally empty.
