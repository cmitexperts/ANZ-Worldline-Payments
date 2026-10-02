<?php
/**
 * Plugin Name: ANZ Worldline Payments
 * Plugin URI: https://bias.net
 * Description: ANZ Worldline Payments hosted checkout integration. Guest payments via shortcode (no login required).
 * Version: 1.0
 * Author: Yusuf
 * Author URI: mailto:info@bias.net
 * Text Domain: anz-worldline-payments
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANZ_WORLDLINE_PAYMENTS_VERSION', '1.0' );
define( 'ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANZ_WORLDLINE_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ANZ_WORLDLINE_PAYMENTS_OPTION', 'anz_worldline_payments_settings' );

// PHP SDK for Worldline Hosted Checkout API.
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'lib/vendor/autoload.php';
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'includes/class-anz-worldline-api.php';
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'includes/class-anz-worldline-admin.php';
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'includes/class-anz-worldline-payment-post-type.php';
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'includes/class-anz-worldline-payments-list-table.php';
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'includes/class-anz-worldline-shortcode.php';
require_once ANZ_WORLDLINE_PAYMENTS_PLUGIN_DIR . 'includes/class-anz-worldline-return-handler.php';

/**
 * Register pap_payment post type if not already registered (e.g. by theme).
 */
function anz_worldline_payments_register_post_type() {
	ANZ_Worldline_Payment_Post_Type::register_if_needed();
}
add_action( 'init', 'anz_worldline_payments_register_post_type', 20 );

/**
 * Initialize plugin.
 */
function anz_worldline_payments_init() {
	load_plugin_textdomain( 'anz-worldline-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	ANZ_Worldline_Admin::instance();
	ANZ_Worldline_Shortcode::instance();
	ANZ_Worldline_Return_Handler::instance();
}
add_action( 'plugins_loaded', 'anz_worldline_payments_init' );
