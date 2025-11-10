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
add_action( 'admin_post_wts_export_products', 'wts_exporter_handle_export_request' );

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
 * Handles the export form submission dispatched via admin-post.
 */
function wts_exporter_handle_export_request() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to export products.', 'woo-to-shopify-exporter' ) );
    }

    check_admin_referer( 'wts_export_products', 'wts_export_nonce' );

    $category_id     = isset( $_POST['wts_export_category'] ) ? absint( $_POST['wts_export_category'] ) : 0;
    $published_only  = ! empty( $_POST['wts_export_published_only'] );
    $default_vendor  = isset( $_POST['wts_export_vendor'] ) ? sanitize_text_field( wp_unslash( $_POST['wts_export_vendor'] ) ) : '';

    $export_args = array(
        'category_id'    => $category_id > 0 ? $category_id : null,
        'published_only' => $published_only,
        'default_vendor' => $default_vendor,
    );

    /**
     * Fires when the Woo to Shopify export process should run.
     *
     * @param array $export_args Arguments collected from the admin export form.
     */
    do_action( 'wts_exporter_process_export', $export_args );

    wp_die( esc_html__( 'Export processing is not yet implemented.', 'woo-to-shopify-exporter' ) );
}

/**
 * Renders the Woo to Shopify Exporter admin page.
 */
function wts_exporter_render_admin_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-to-shopify-exporter' ) );
    }

    $categories = get_terms(
        array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        )
    );

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Woo to Shopify Exporter', 'woo-to-shopify-exporter' ) . '</h1>';
    echo '<p>' . esc_html__( 'Export your WooCommerce products to a Shopify-compatible CSV file.', 'woo-to-shopify-exporter' ) . '</p>';

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wts-exporter__form">';
    wp_nonce_field( 'wts_export_products', 'wts_export_nonce' );
    echo '<input type="hidden" name="action" value="wts_export_products" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';

    echo '<tr>';
    echo '<th scope="row"><label for="wts_export_category">' . esc_html__( 'Product category', 'woo-to-shopify-exporter' ) . '</label></th>';
    echo '<td>';
    echo '<select name="wts_export_category" id="wts_export_category">';
    echo '<option value="">' . esc_html__( 'All product categories', 'woo-to-shopify-exporter' ) . '</option>';

    if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
        foreach ( $categories as $category ) {
            printf(
                '<option value="%1$s">%2$s</option>',
                esc_attr( $category->term_id ),
                esc_html( $category->name )
            );
        }
    }

    echo '</select>';
    echo '<p class="description">' . esc_html__( 'Optionally limit the export to a single WooCommerce category.', 'woo-to-shopify-exporter' ) . '</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row">' . esc_html__( 'Published products only', 'woo-to-shopify-exporter' ) . '</th>';
    echo '<td>';
    echo '<label for="wts_export_published_only">';
    echo '<input type="checkbox" name="wts_export_published_only" id="wts_export_published_only" value="1" checked="checked" /> ';
    echo esc_html__( 'Include only products that are currently published.', 'woo-to-shopify-exporter' );
    echo '</label>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="wts_export_vendor">' . esc_html__( 'Default Shopify vendor', 'woo-to-shopify-exporter' ) . '</label></th>';
    echo '<td>';
    echo '<input type="text" name="wts_export_vendor" id="wts_export_vendor" class="regular-text" placeholder="' . esc_attr__( 'e.g. Your Brand Name', 'woo-to-shopify-exporter' ) . '" />';
    echo '<p class="description">' . esc_html__( 'Specify the vendor name to populate the Shopify Vendor column.', 'woo-to-shopify-exporter' ) . '</p>';
    echo '</td>';
    echo '</tr>';

    echo '</tbody>';
    echo '</table>';

    submit_button( esc_html__( 'Export products to Shopify', 'woo-to-shopify-exporter' ) );

    echo '</form>';
    echo '</div>';
}
