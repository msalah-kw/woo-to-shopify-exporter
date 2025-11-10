<?php
/**
 * Admin menu registration and asset management.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WSE_EXPORT_PAGE_SLUG' ) ) {
    define( 'WSE_EXPORT_PAGE_SLUG', 'woo-to-shopify-exporter' );
}

require_once __DIR__ . '/export-page.php';

/**
 * Registers the admin menu entry under WooCommerce or Tools.
 *
 * @return void
 */
function wse_register_admin_menu() {
    $capability = wse_get_admin_capability();
    $page_title = __( 'Woo to Shopify Exporter', 'woo-to-shopify-exporter' );
    $menu_title = __( 'Export to Shopify', 'woo-to-shopify-exporter' );

    $hook_suffix = '';

    if ( wse_should_attach_to_woocommerce_menu() ) {
        $hook_suffix = add_submenu_page(
            'woocommerce',
            $page_title,
            $menu_title,
            $capability,
            WSE_EXPORT_PAGE_SLUG,
            'wse_render_export_page'
        );
    } else {
        $hook_suffix = add_management_page(
            $page_title,
            $menu_title,
            $capability,
            WSE_EXPORT_PAGE_SLUG,
            'wse_render_export_page'
        );
    }

    if ( $hook_suffix ) {
        add_action( 'load-' . $hook_suffix, 'wse_register_screen_hooks' );
        add_action( 'admin_enqueue_scripts', 'wse_admin_enqueue_assets' );
        wse_set_admin_page_hook( $hook_suffix );
    }
}
add_action( 'admin_menu', 'wse_register_admin_menu' );

/**
 * Determines the capability required to access the export page.
 *
 * @return string
 */
function wse_get_admin_capability() {
    return apply_filters( 'wse_admin_capability', wse_should_attach_to_woocommerce_menu() ? 'manage_woocommerce' : 'manage_options' );
}

/**
 * Persists the page hook for later comparisons.
 *
 * @param string $hook Hook suffix returned by add_*_page.
 *
 * @return void
 */
function wse_set_admin_page_hook( $hook ) {
    global $wse_admin_page_hook;
    $wse_admin_page_hook = $hook;
}

/**
 * Retrieves the stored admin page hook.
 *
 * @return string
 */
function wse_get_admin_page_hook() {
    global $wse_admin_page_hook;

    return isset( $wse_admin_page_hook ) ? $wse_admin_page_hook : '';
}

/**
 * Determines whether the page should appear under WooCommerce.
 *
 * @return bool
 */
function wse_should_attach_to_woocommerce_menu() {
    return class_exists( 'WooCommerce' );
}

/**
 * Registers screen related hooks.
 *
 * @return void
 */
function wse_register_screen_hooks() {
    add_action( 'admin_notices', 'wse_render_admin_notices' );
}

/**
 * Enqueues admin assets only for the exporter page.
 *
 * @param string $hook Current admin page hook.
 *
 * @return void
 */
function wse_admin_enqueue_assets( $hook ) {
    $page_hook = wse_get_admin_page_hook();

    if ( $hook !== $page_hook && ( empty( $_GET['page'] ) || WSE_EXPORT_PAGE_SLUG !== $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    wp_register_style(
        'wse-admin-styles',
        WSE_PLUGIN_URL . 'admin/assets/css/wse-admin-styles.css',
        array(),
        WSE_PLUGIN_VERSION
    );

    wp_register_script(
        'wse-admin-scripts',
        WSE_PLUGIN_URL . 'admin/assets/js/wse-admin-scripts.js',
        array( 'jquery', 'wp-util' ),
        WSE_PLUGIN_VERSION,
        true
    );

    wp_enqueue_style( 'wse-admin-styles' );
    wp_enqueue_script( 'wse-admin-scripts' );

    $localized = array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'pageSlug'     => WSE_EXPORT_PAGE_SLUG,
        'nonces'       => array(
            'start'  => wp_create_nonce( 'wse_start_export' ),
            'poll'   => wp_create_nonce( 'wse_poll_export' ),
            'resume' => wp_create_nonce( 'wse_resume_export' ),
        ),
        'activeJob'    => wse_get_active_job(),
        'settings'     => wse_get_saved_export_settings(),
        'options'      => array(
            'inventoryPolicy' => wse_get_inventory_policy_options(),
            'inventoryTracker'=> wse_get_inventory_tracker_options(),
            'taxBehavior'     => wse_get_tax_behavior_options(),
        ),
        'strings'      => array(
            'step'               => __( 'Step', 'woo-to-shopify-exporter' ),
            'of'                 => __( 'of', 'woo-to-shopify-exporter' ),
            'resume'             => __( 'Resume export', 'woo-to-shopify-exporter' ),
            'start'              => __( 'Start export', 'woo-to-shopify-exporter' ),
            'starting'           => __( 'Preparing export…', 'woo-to-shopify-exporter' ),
            'queued'             => __( 'Queued', 'woo-to-shopify-exporter' ),
            'running'            => __( 'Processing products…', 'woo-to-shopify-exporter' ),
            'paused'             => __( 'Export paused', 'woo-to-shopify-exporter' ),
            'completed'          => __( 'Export complete', 'woo-to-shopify-exporter' ),
            'failed'             => __( 'Export failed', 'woo-to-shopify-exporter' ),
            'idle'               => __( 'Awaiting export request', 'woo-to-shopify-exporter' ),
            'ajaxError'          => __( 'The request could not be completed. Please try again.', 'woo-to-shopify-exporter' ),
            'resumeConfirmation' => __( 'An export is already in progress. Would you like to resume it?', 'woo-to-shopify-exporter' ),
            'lastUpdated'        => __( 'Last updated', 'woo-to-shopify-exporter' ),
            'summaryScope'       => __( 'Scope', 'woo-to-shopify-exporter' ),
            'summaryPreset'      => __( 'Mapping preset', 'woo-to-shopify-exporter' ),
            'summaryVendorType'  => __( 'Vendor & product type', 'woo-to-shopify-exporter' ),
            'summaryInventory'   => __( 'Inventory & tax policies', 'woo-to-shopify-exporter' ),
            'summaryOutput'      => __( 'Included extras', 'woo-to-shopify-exporter' ),
            'summaryFile'        => __( 'File options', 'woo-to-shopify-exporter' ),
            'summaryBatching'    => __( 'Batching', 'woo-to-shopify-exporter' ),
            'scopeAll'           => __( 'All products', 'woo-to-shopify-exporter' ),
            'scopeCategories'    => __( 'Selected categories', 'woo-to-shopify-exporter' ),
            'scopeTags'          => __( 'Selected tags', 'woo-to-shopify-exporter' ),
            'scopeStatuses'      => __( 'Product statuses', 'woo-to-shopify-exporter' ),
            'scopeEmpty'         => __( 'No filters selected', 'woo-to-shopify-exporter' ),
            'presetDefault'      => __( 'Shopify default layout', 'woo-to-shopify-exporter' ),
            'presetMinimal'      => __( 'Minimal Shopify layout', 'woo-to-shopify-exporter' ),
            'presetExtended'     => __( 'Extended Shopify layout', 'woo-to-shopify-exporter' ),
            'presetCustom'       => __( 'Custom mapping', 'woo-to-shopify-exporter' ),
            'outputDelimiter'    => __( 'Delimiter', 'woo-to-shopify-exporter' ),
            'outputImages'       => __( 'Images', 'woo-to-shopify-exporter' ),
            'outputInventory'    => __( 'Inventory', 'woo-to-shopify-exporter' ),
            'outputVariations'   => __( 'Variations', 'woo-to-shopify-exporter' ),
            'outputCollections'  => __( 'Collections CSV', 'woo-to-shopify-exporter' ),
            'outputRedirects'    => __( 'Redirects CSV', 'woo-to-shopify-exporter' ),
            'outputCopyImages'   => __( 'Copy images', 'woo-to-shopify-exporter' ),
            'outputNone'         => __( 'No optional files selected', 'woo-to-shopify-exporter' ),
            'vendorAttribute'    => __( 'Vendor attribute', 'woo-to-shopify-exporter' ),
            'vendorAuto'         => __( 'Auto-detect brand or leave blank', 'woo-to-shopify-exporter' ),
            'typeTaxonomy'       => __( 'Type taxonomy', 'woo-to-shopify-exporter' ),
            'typeAuto'           => __( 'Auto-detect primary category', 'woo-to-shopify-exporter' ),
            'batchSize'          => __( 'Batch size', 'woo-to-shopify-exporter' ),
            'splitThreshold'     => __( 'Split at', 'woo-to-shopify-exporter' ),
        ),
    );

    wp_localize_script( 'wse-admin-scripts', 'wseAdmin', $localized );
}

/**
 * Outputs settings related admin notices.
 *
 * @return void
 */
function wse_render_admin_notices() {
    settings_errors( 'wse_export' );
}
