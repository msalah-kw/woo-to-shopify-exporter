<?php
/**
 * Plugin Name:       Woo to Shopify Exporter
 * Plugin URI:        https://example.com/woo-to-shopify-exporter
 * Description:       Export WooCommerce products to a Shopify-compatible CSV format.
 * Version:           0.1.0
 * Author:            Woo to Shopify Exporter Contributors
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-to-shopify-exporter
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

if ( ! defined( 'WTS_EXPORTER_VERSION' ) ) {
define( 'WTS_EXPORTER_VERSION', '0.1.0' );
}

if ( ! defined( 'WTS_EXPORTER_PLUGIN_FILE' ) ) {
define( 'WTS_EXPORTER_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WTS_EXPORTER_PLUGIN_DIR' ) ) {
define( 'WTS_EXPORTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WTS_EXPORTER_PLUGIN_URL' ) ) {
define( 'WTS_EXPORTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Placeholder for plugin initialization logic.
