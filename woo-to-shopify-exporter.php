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
add_action( 'wts_exporter_process_export', 'wts_exporter_run_export', 10, 1 );

if ( ! defined( 'WTS_EXPORTER_MAX_SHOPIFY_OPTIONS' ) ) {
    define( 'WTS_EXPORTER_MAX_SHOPIFY_OPTIONS', 3 );
}

if ( ! defined( 'WTS_EXPORTER_SHOPIFY_COLUMNS' ) ) {
    define(
        'WTS_EXPORTER_SHOPIFY_COLUMNS',
        array(
            'Handle',
            'Title',
            'Body (HTML)',
            'Vendor',
            'Type',
            'Tags',
            'Published',
            'Option1 Name',
            'Option1 Value',
            'Option2 Name',
            'Option2 Value',
            'Option3 Name',
            'Option3 Value',
            'Variant SKU',
            'Variant Price',
            'Variant Compare At Price',
            'Variant Inventory Tracker',
            'Variant Inventory Qty',
            'Variant Inventory Policy',
            'Variant Fulfillment Service',
            'Variant Requires Shipping',
            'Variant Taxable',
            'Variant Barcode',
            'Variant Grams',
            'Variant Weight Unit',
            'Variant Image',
            'Image Src',
            'Image Position',
            'Image Alt Text',
            'Gift Card',
        )
    );
}

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

    wp_die( esc_html__( 'The export handler did not complete as expected.', 'woo-to-shopify-exporter' ) );
}

/**
 * Default export runner hooked to the wts_exporter_process_export action.
 *
 * @param array $export_args Arguments collected from the admin export form.
 */
function wts_exporter_run_export( $export_args ) {
    if ( ! function_exists( 'wc_get_products' ) ) {
        wp_die( esc_html__( 'WooCommerce must be active to export products.', 'woo-to-shopify-exporter' ) );
    }

    $products = wts_exporter_query_products( $export_args );

    if ( empty( $products ) ) {
        wp_die( esc_html__( 'No products matched the selected filters.', 'woo-to-shopify-exporter' ) );
    }

    $rows = wts_exporter_map_products_to_shopify_rows( $products, $export_args );

    if ( empty( $rows ) ) {
        wp_die( esc_html__( 'No exportable rows were generated from the selected products.', 'woo-to-shopify-exporter' ) );
    }

    $dataset = wts_exporter_build_export_dataset( $rows );

    if ( empty( $dataset['rows'] ) ) {
        wp_die( esc_html__( 'The export dataset could not be prepared.', 'woo-to-shopify-exporter' ) );
    }

    $preview_rows = array_slice( $dataset['rows'], 0, 5 );

    $message  = '<h1>' . esc_html__( 'Shopify export preview', 'woo-to-shopify-exporter' ) . '</h1>';
    $message .= '<p>';
    $message .= sprintf(
        /* translators: 1: product count, 2: row count. */
        esc_html__( 'Generated %1$d products across %2$d Shopify-formatted rows.', 'woo-to-shopify-exporter' ),
        count( $products ),
        count( $rows )
    );
    $message .= '</p>';
    $message .= '<p>' . esc_html__( 'CSV file generation will be implemented in the next phase.', 'woo-to-shopify-exporter' ) . '</p>';
    $message .= '<pre>' . esc_html( print_r( array(
        'header' => $dataset['header'],
        'rows'   => $preview_rows,
    ), true ) ) . '</pre>';

    wp_die( wp_kses_post( $message ) );
}

/**
 * Query WooCommerce products using the admin form filters.
 *
 * @param array $export_args Export arguments.
 * @return array<int,\WC_Product> Array of WooCommerce product objects.
 */
function wts_exporter_query_products( $export_args ) {
    $query_args = array(
        'type'   => array( 'simple', 'variable' ),
        'limit'  => -1,
        'status' => $export_args['published_only'] ? 'publish' : array( 'publish', 'pending', 'private', 'draft' ),
        'return' => 'objects',
        'orderby'=> 'date',
        'order'  => 'DESC',
    );

    if ( ! empty( $export_args['category_id'] ) ) {
        $category = get_term( (int) $export_args['category_id'], 'product_cat' );

        if ( $category && ! is_wp_error( $category ) ) {
            $query_args['category'] = array( $category->slug );
        }
    }

    /**
     * Filter the WooCommerce product query arguments before execution.
     *
     * @param array $query_args  WooCommerce product query arguments.
     * @param array $export_args Export arguments from the admin form.
     */
    $query_args = apply_filters( 'wts_exporter_product_query_args', $query_args, $export_args );

    return wc_get_products( $query_args );
}

/**
 * Map WooCommerce products to Shopify-compatible row arrays.
 *
 * @param array<int,\WC_Product> $products    Array of WooCommerce products.
 * @param array                   $export_args Export arguments.
 * @return array<int,array<string,string>> Shopify formatted rows.
 */
function wts_exporter_map_products_to_shopify_rows( $products, $export_args ) {
    $rows = array();

    foreach ( $products as $product ) {
        if ( ! $product instanceof WC_Product ) {
            continue;
        }

        if ( $product->is_type( 'variable' ) ) {
            $rows = array_merge( $rows, wts_exporter_build_variable_product_rows( $product, $export_args ) );
        } else {
            $rows[] = wts_exporter_build_simple_product_row( $product, $export_args );
        }
    }

    return $rows;
}

/**
 * Build the Shopify export dataset with ordered rows and header list.
 *
 * @param array<int,array<string,string>> $rows Shopify-formatted associative rows.
 * @return array{header:array<int,string>,rows:array<int,array<int,string>>}
 */
function wts_exporter_build_export_dataset( $rows ) {
    $header = WTS_EXPORTER_SHOPIFY_COLUMNS;
    $ordered_rows = array();

    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $ordered_row = array();

        foreach ( $header as $column ) {
            $ordered_row[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
        }

        $ordered_rows[] = $ordered_row;
    }

    return array(
        'header' => $header,
        'rows'   => $ordered_rows,
    );
}

/**
 * Build a Shopify-formatted row for a simple WooCommerce product.
 *
 * @param WC_Product $product     WooCommerce product instance.
 * @param array      $export_args Export arguments.
 * @return array<string,string> Shopify compatible data row.
 */
function wts_exporter_build_simple_product_row( $product, $export_args ) {
    $handle          = wts_exporter_resolve_handle( $product );
    $vendor          = wts_exporter_resolve_vendor( $product, $export_args );
    $product_type    = wts_exporter_resolve_product_type( $product );
    $tags            = wts_exporter_resolve_tags( $product );
    $body_html       = wts_exporter_resolve_body_html( $product );
    $published       = wts_exporter_format_boolean( 'publish' === $product->get_status() );
    $sku             = $product->get_sku();
    $price           = wts_exporter_format_decimal( $product->get_price() );
    $compare_price   = wts_exporter_format_decimal( $product->get_regular_price() );
    $inventory_qty   = wts_exporter_resolve_inventory_quantity( $product );
    $inventory_track = $product->managing_stock() ? 'shopify' : '';
    $inventory_policy = $product->backorders_allowed() ? 'continue' : 'deny';
    $requires_shipping = wts_exporter_format_boolean( ! $product->is_virtual() );
    $taxable            = wts_exporter_format_boolean( $product->is_taxable() );
    $barcode            = wts_exporter_resolve_barcode( $product );
    $grams              = wts_exporter_calculate_weight_in_grams( $product );
    $weight_unit        = wts_exporter_resolve_weight_unit();
    $image_src          = wts_exporter_resolve_primary_image_src( $product );
    $image_alt          = wts_exporter_resolve_image_alt_text( $product );

    $row = wts_exporter_initialize_row( $handle );

    $row['Title']                     = $product->get_name();
    $row['Body (HTML)']               = $body_html;
    $row['Vendor']                    = $vendor;
    $row['Type']                      = $product_type;
    $row['Tags']                      = $tags;
    $row['Published']                 = $published;
    $row['Option1 Name']              = 'Title';
    $row['Option1 Value']             = 'Default Title';
    $row['Variant SKU']               = $sku;
    $row['Variant Price']             = $price;
    $row['Variant Compare At Price']  = $compare_price;
    $row['Variant Inventory Tracker'] = $inventory_track;
    $row['Variant Inventory Qty']     = $inventory_qty;
    $row['Variant Inventory Policy']  = $inventory_policy;
    $row['Variant Fulfillment Service'] = 'manual';
    $row['Variant Requires Shipping']   = $requires_shipping;
    $row['Variant Taxable']             = $taxable;
    $row['Variant Barcode']             = $barcode;
    $row['Variant Grams']               = $grams;
    $row['Variant Weight Unit']         = $weight_unit;
    $row['Variant Image']               = $image_src;
    $row['Image Src']                   = $image_src;
    $row['Image Position']              = $image_src ? '1' : '';
    $row['Image Alt Text']              = $image_alt;
    $row['Gift Card']                   = 'FALSE';

    return $row;
}

/**
 * Build Shopify-formatted rows for a variable WooCommerce product.
 *
 * @param WC_Product_Variable $product     Variable product.
 * @param array               $export_args Export arguments.
 * @return array<int,array<string,string>> Shopify rows.
 */
function wts_exporter_build_variable_product_rows( $product, $export_args ) {
    $rows = array();

    $handle       = wts_exporter_resolve_handle( $product );
    $vendor       = wts_exporter_resolve_vendor( $product, $export_args );
    $product_type = wts_exporter_resolve_product_type( $product );
    $tags         = wts_exporter_resolve_tags( $product );
    $body_html    = wts_exporter_resolve_body_html( $product );
    $published    = wts_exporter_format_boolean( 'publish' === $product->get_status() );
    $image_src    = wts_exporter_resolve_primary_image_src( $product );
    $image_alt    = wts_exporter_resolve_image_alt_text( $product );

    $option_keys  = wts_exporter_resolve_option_keys( $product );
    $option_names = wts_exporter_resolve_option_labels( $option_keys );

    if ( empty( $option_names ) ) {
        $option_names = array( 'Title' );
        $option_keys  = array( 'title' );
    }

    $variations = $product->get_children();

    if ( empty( $variations ) ) {
        return array();
    }

    $is_first_row = true;

    foreach ( $variations as $variation_id ) {
        $variation = wc_get_product( $variation_id );

        if ( ! $variation instanceof WC_Product_Variation ) {
            continue;
        }

        $row = wts_exporter_initialize_row( $handle );

        if ( $is_first_row ) {
            $row['Title']       = $product->get_name();
            $row['Body (HTML)'] = $body_html;
            $row['Vendor']      = $vendor;
            $row['Type']        = $product_type;
            $row['Tags']        = $tags;
            $row['Published']   = $published;
            $row['Image Src']   = $image_src;
            $row['Image Position'] = $image_src ? '1' : '';
            $row['Image Alt Text'] = $image_alt;
        }

        $option_values = wts_exporter_resolve_option_values( $variation, $option_keys );

        foreach ( $option_names as $index => $name ) {
            $option_number = $index + 1;
            $value         = isset( $option_values[ $index ] ) ? $option_values[ $index ] : '';

            if ( '' === $value && 0 === $index ) {
                $value = 'Default Title';
            }

            $row[ 'Option' . $option_number . ' Name' ]  = $name;
            $row[ 'Option' . $option_number . ' Value' ] = $value;
        }

        $row['Variant SKU']               = $variation->get_sku();
        $row['Variant Price']             = wts_exporter_format_decimal( $variation->get_price() );
        $row['Variant Compare At Price']  = wts_exporter_format_decimal( $variation->get_regular_price() );
        $row['Variant Inventory Tracker'] = $variation->managing_stock() ? 'shopify' : '';
        $row['Variant Inventory Qty']     = wts_exporter_resolve_inventory_quantity( $variation );
        $row['Variant Inventory Policy']  = $variation->backorders_allowed() ? 'continue' : 'deny';
        $row['Variant Fulfillment Service'] = 'manual';
        $row['Variant Requires Shipping']   = wts_exporter_format_boolean( ! $variation->is_virtual() );
        $row['Variant Taxable']             = wts_exporter_format_boolean( $variation->is_taxable() );
        $row['Variant Barcode']             = wts_exporter_resolve_barcode( $variation );
        $row['Variant Grams']               = wts_exporter_calculate_weight_in_grams( $variation );
        $row['Variant Weight Unit']         = wts_exporter_resolve_weight_unit();
        $row['Gift Card']                   = 'FALSE';

        if ( $variation->get_image_id() ) {
            $row['Variant Image'] = wp_get_attachment_url( $variation->get_image_id() );
        }

        $rows[] = $row;
        $is_first_row = false;
    }

    return $rows;
}

/**
 * Initialize a Shopify row array with default empty values.
 *
 * @param string $handle Shopify product handle.
 * @return array<string,string> Initialized row.
 */
function wts_exporter_initialize_row( $handle ) {
    $row = array();

    foreach ( WTS_EXPORTER_SHOPIFY_COLUMNS as $column ) {
        $row[ $column ] = '';
    }

    $row['Handle'] = $handle;

    return $row;
}

/**
 * Resolve the Shopify handle for a WooCommerce product.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string Handle value.
 */
function wts_exporter_resolve_handle( $product ) {
    $handle = $product->get_slug();

    if ( empty( $handle ) ) {
        $handle = sanitize_title( $product->get_name() );
    }

    return sanitize_title( $handle );
}

/**
 * Resolve the vendor column for Shopify export.
 *
 * @param WC_Product $product     WooCommerce product.
 * @param array      $export_args Export arguments.
 * @return string Vendor value.
 */
function wts_exporter_resolve_vendor( $product, $export_args ) {
    $attribute_keys = array( 'pa_brand', 'pa_vendor', 'brand', 'vendor' );

    foreach ( $attribute_keys as $attribute_key ) {
        $attribute_value = $product->get_attribute( $attribute_key );

        if ( ! empty( $attribute_value ) ) {
            return wts_exporter_normalize_list_value( $attribute_value );
        }
    }

    if ( ! empty( $export_args['default_vendor'] ) ) {
        return $export_args['default_vendor'];
    }

    /**
     * Filter the fallback vendor value when no vendor could be derived.
     *
     * @param string $fallback_vendor Fallback vendor string.
     * @param WC_Product $product     WooCommerce product.
     */
    return apply_filters( 'wts_exporter_fallback_vendor', get_bloginfo( 'name' ), $product );
}

/**
 * Resolve the Shopify Type column from WooCommerce product categories.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string Product type value.
 */
function wts_exporter_resolve_product_type( $product ) {
    $category_ids = $product->get_category_ids();

    if ( empty( $category_ids ) ) {
        return '';
    }

    $primary_category = get_term( (int) $category_ids[0], 'product_cat' );

    if ( $primary_category && ! is_wp_error( $primary_category ) ) {
        return $primary_category->name;
    }

    return '';
}

/**
 * Resolve tags for the Shopify Tags column.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string Comma separated tags string.
 */
function wts_exporter_resolve_tags( $product ) {
    $tag_names = array();
    $tags      = get_the_terms( $product->get_id(), 'product_tag' );

    if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
        foreach ( $tags as $tag ) {
            $tag_names[] = $tag->name;
        }
    }

    return implode( ', ', $tag_names );
}

/**
 * Resolve the product body HTML for Shopify.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string Product description HTML.
 */
function wts_exporter_resolve_body_html( $product ) {
    $description = $product->get_description();

    if ( empty( $description ) ) {
        $description = $product->get_short_description();
    }

    if ( empty( $description ) ) {
        return '';
    }

    return apply_filters( 'the_content', $description );
}

/**
 * Format a boolean value to Shopify's expected TRUE/FALSE string.
 *
 * @param bool $value Boolean value.
 * @return string Formatted boolean string.
 */
function wts_exporter_format_boolean( $value ) {
    return $value ? 'TRUE' : 'FALSE';
}

/**
 * Format decimal values for Shopify.
 *
 * @param string|float|null $value Raw price/decimal value.
 * @return string
 */
function wts_exporter_format_decimal( $value ) {
    if ( '' === $value || null === $value ) {
        return '';
    }

    return wc_format_decimal( $value );
}

/**
 * Resolve the stock quantity for a product or variation.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string
 */
function wts_exporter_resolve_inventory_quantity( $product ) {
    if ( ! $product->managing_stock() ) {
        return '';
    }

    $stock_quantity = $product->get_stock_quantity();

    if ( null === $stock_quantity ) {
        return '';
    }

    return (string) max( 0, (int) $stock_quantity );
}

/**
 * Resolve product or variation barcode meta if available.
 *
 * @param WC_Product $product WooCommerce product instance.
 * @return string
 */
function wts_exporter_resolve_barcode( $product ) {
    $meta_keys = array( '_barcode', '_wc_barcode', '_product_barcode', '_variant_barcode' );

    foreach ( $meta_keys as $meta_key ) {
        $value = $product->get_meta( $meta_key );

        if ( ! empty( $value ) ) {
            return (string) $value;
        }
    }

    return '';
}

/**
 * Calculate product or variation weight in grams.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string
 */
function wts_exporter_calculate_weight_in_grams( $product ) {
    $weight = $product->get_weight();

    if ( '' === $weight ) {
        return '';
    }

    $grams = wc_get_weight( $weight, 'g' );

    if ( '' === $grams || null === $grams ) {
        return '';
    }

    return wc_format_decimal( $grams, 2 );
}

/**
 * Resolve the WooCommerce store weight unit.
 *
 * @return string
 */
function wts_exporter_resolve_weight_unit() {
    $unit = get_option( 'woocommerce_weight_unit', 'kg' );

    return strtolower( $unit );
}

/**
 * Resolve the primary image URL for the product.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string
 */
function wts_exporter_resolve_primary_image_src( $product ) {
    $attachment_id = $product->get_image_id();

    if ( ! $attachment_id ) {
        return '';
    }

    $url = wp_get_attachment_url( $attachment_id );

    return $url ? $url : '';
}

/**
 * Resolve the alt text for the product's primary image.
 *
 * @param WC_Product $product WooCommerce product.
 * @return string
 */
function wts_exporter_resolve_image_alt_text( $product ) {
    $attachment_id = $product->get_image_id();

    if ( ! $attachment_id ) {
        return '';
    }

    $alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

    return $alt_text ? $alt_text : '';
}

/**
 * Normalize a WooCommerce attribute string into a readable list.
 *
 * @param string $value Raw attribute string (pipe or comma separated).
 * @return string
 */
function wts_exporter_normalize_list_value( $value ) {
    if ( false !== strpos( $value, '|' ) ) {
        $parts = array_map( 'trim', explode( '|', $value ) );
        $parts = array_filter( $parts );

        return implode( ', ', $parts );
    }

    return $value;
}

/**
 * Resolve option keys (attribute slugs) for a variable product.
 *
 * @param WC_Product_Variable $product Variable product.
 * @return array<int,string>
 */
function wts_exporter_resolve_option_keys( $product ) {
    $option_keys = array();

    foreach ( $product->get_attributes() as $attribute ) {
        if ( count( $option_keys ) >= WTS_EXPORTER_MAX_SHOPIFY_OPTIONS ) {
            break;
        }

        $option_keys[] = $attribute->get_name();
    }

    return $option_keys;
}

/**
 * Convert option keys into Shopify-friendly labels.
 *
 * @param array<int,string> $option_keys Attribute keys.
 * @return array<int,string>
 */
function wts_exporter_resolve_option_labels( $option_keys ) {
    $labels = array();

    foreach ( $option_keys as $key ) {
        $labels[] = wc_attribute_label( $key );
    }

    return $labels;
}

/**
 * Resolve option values for a specific variation.
 *
 * @param WC_Product_Variation $variation   Variation instance.
 * @param array<int,string>    $option_keys Option attribute keys.
 * @return array<int,string>
 */
function wts_exporter_resolve_option_values( $variation, $option_keys ) {
    $values              = array();
    $variation_attributes = $variation->get_attributes();

    foreach ( $option_keys as $index => $attribute_key ) {
        $lookup_key = 'attribute_' . $attribute_key;
        $value      = '';

        if ( isset( $variation_attributes[ $lookup_key ] ) ) {
            $value = $variation_attributes[ $lookup_key ];
        } elseif ( isset( $variation_attributes[ sanitize_title( $lookup_key ) ] ) ) {
            $value = $variation_attributes[ sanitize_title( $lookup_key ) ];
        }

        if ( taxonomy_exists( $attribute_key ) && ! empty( $value ) ) {
            $term = get_term_by( 'slug', $value, $attribute_key );

            if ( $term && ! is_wp_error( $term ) ) {
                $value = $term->name;
            }
        }

        $values[ $index ] = $value;
    }

    return $values;
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
