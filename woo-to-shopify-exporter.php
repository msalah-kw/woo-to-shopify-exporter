<?php
/**
 * Plugin Name:       Woo to Shopify Exporter
 * Plugin URI:        https://github.com/nsb/woo-to-shopify-exporter
 * Description:       Export WooCommerce catalogs into Shopify-compatible formats.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            NSB
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-to-shopify-exporter
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const WSE_PLUGIN_FILE = __FILE__;
const WSE_MINIMUM_PHP = '8.1';

if (version_compare(PHP_VERSION, WSE_MINIMUM_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Woo to Shopify Exporter requires PHP 8.1 or higher.', 'woo-to-shopify-exporter')
        );
    });

    return;
}

if (!class_exists('WooCommerce', false)) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__('Woo to Shopify Exporter requires WooCommerce to be active.', 'woo-to-shopify-exporter')
        );
    });

    return;
}

require_once __DIR__ . '/bootstrap.php';
