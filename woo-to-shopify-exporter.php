<?php
/**
 * Plugin Name: Woo to Shopify Exporter
 * Plugin URI: https://github.com/BY-SALAH/woo-to-shopify-exporter
 * Description: Export WooCommerce products into a Shopify-compatible CSV using a guided wizard.
 * Version: 1.0.0
 * Author: BY SALAH
 * License: GPLv2 or later
 * Text Domain: woo-to-shopify-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WSE_PLUGIN_VERSION' ) ) {
    define( 'WSE_PLUGIN_VERSION', '1.0.0' );
}

if ( ! defined( 'WSE_PLUGIN_PATH' ) ) {
    define( 'WSE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WSE_PLUGIN_URL' ) ) {
    define( 'WSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once WSE_PLUGIN_PATH . 'includes/data-query.php';
require_once WSE_PLUGIN_PATH . 'admin/menu.php';
