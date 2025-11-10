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

add_action( 'admin_menu', 'wts_exporter_register_admin_page' );

/**
 * Registers the Woo to Shopify Exporter admin submenu page under WooCommerce.
 */
function wts_exporter_register_admin_page() {
    add_submenu_page(
        'woocommerce',
        esc_html__( 'Woo to Shopify Exporter', 'woo-to-shopify-exporter' ),
        esc_html__( 'Shopify Exporter', 'woo-to-shopify-exporter' ),
        'manage_woocommerce',
        'wts-exporter',
        'wts_exporter_render_admin_page'
    );
}

/**
 * Renders the Woo to Shopify Exporter admin page.
 */
function wts_exporter_render_admin_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-to-shopify-exporter' ) );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Woo to Shopify Exporter', 'woo-to-shopify-exporter' ) . '</h1>';
    echo '<p>' . esc_html__( 'Export your WooCommerce products to a Shopify-compatible CSV file.', 'woo-to-shopify-exporter' ) . '</p>';
    echo '<button type="button" class="button button-primary">' . esc_html__( 'Start Export', 'woo-to-shopify-exporter' ) . '</button>';
    echo '</div>';
}
