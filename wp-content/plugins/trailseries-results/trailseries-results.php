<?php
/**
 * Plugin Name:       TrailSeries Results
 * Plugin URI:        https://trailseries.bg
 * Description:       Canonical race-results storage and rendering for trailseries.bg. Owns the results schema — Place | First name | Last name | Team | Age | Bib# | Finish Time | Status — with optional splits as trailing columns only.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            TrailSeries.bg
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trailseries-results
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSR_VERSION', '0.1.0' );
define( 'TSR_PLUGIN_FILE', __FILE__ );
define( 'TSR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TSR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require TSR_PLUGIN_DIR . 'includes/class-status.php';
require TSR_PLUGIN_DIR . 'includes/class-schema.php';
require TSR_PLUGIN_DIR . 'includes/class-result-row.php';
require TSR_PLUGIN_DIR . 'includes/class-result-set.php';
require TSR_PLUGIN_DIR . 'includes/class-repository.php';
require TSR_PLUGIN_DIR . 'includes/class-renderer.php';
require TSR_PLUGIN_DIR . 'includes/class-post-types.php';
require TSR_PLUGIN_DIR . 'includes/template-functions.php';
require TSR_PLUGIN_DIR . 'includes/redirects.php';

add_action( 'init', array( TSR_Post_Types::class, 'register' ) );

if ( is_admin() ) {
	require TSR_PLUGIN_DIR . 'includes/class-admin-upload.php';
	TSR_Admin_Upload::register();
}

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'trailseries-results', false, dirname( plugin_basename( TSR_PLUGIN_FILE ) ) . '/languages' );
} );

register_activation_hook( __FILE__, static function (): void {
	TSR_Post_Types::register();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require TSR_PLUGIN_DIR . 'includes/class-cli.php';
	TSR_CLI::register();
}
