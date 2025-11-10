<?php
/**
 * Product data extraction utilities.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WSE_WooCommerce_Product_Source' ) ) {

    /**
     * Provides normalized WooCommerce product payloads for the exporter.
     */
    class WSE_WooCommerce_Product_Source {

        /**
         * Loads products using a unified query interface.
         *
         * Supported scope keys:
         * - type: all|category|tag|ids
         * - categories: array of term IDs (for category scope)
         * - tags: array of term IDs (for tag scope)
         * - ids: array of product/variation IDs (for ids scope)
         * - status: array|string of WooCommerce statuses (default publish)
         *
         * @param array $scope Scope definition.
         *
         * @return array {
         *     @type array $products Shopify product rows.
         *     @type array $variants Shopify variant rows.
         *     @type array $images   Shopify image rows.
         *     @type array $items    Combined payload with Shopify rows and normalized metadata.
         * }
         */
        public function loadProducts( array $scope = array() ) {
            if ( ! class_exists( 'WC_Product_Query' ) ) {
                return $this->empty_response();
            }

            $args = $this->build_query_args( $scope );
            $query = new WC_Product_Query( $args );
            $results = $query->get_products();

            if ( empty( $results ) ) {
                return $this->empty_response();
            }

            $organized = $this->organize_products( $results );

            $response = $this->empty_response();

            foreach ( $organized as $entry ) {
                if ( empty( $entry['product'] ) || ! $entry['product'] instanceof WC_Product ) {
                    continue;
                }

                $package = $this->format_product( $entry['product'], $entry['variations'] );

                $response['products'][] = $package['product'];
                $response['variants']   = array_merge( $response['variants'], $package['variants'] );
                $response['images']     = array_merge( $response['images'], $package['images'] );
                $response['items'][]    = $package;
            }

            return $response;
        }

        /**
         * Builds WC_Product_Query arguments for the provided scope.
         *
         * @param array $scope Scope definition.
         *
         * @return array
         */
        protected function build_query_args( array $scope ) {
            $scope = wp_parse_args(
                $scope,
                array(
                    'type'       => 'all',
                    'categories' => array(),
                    'tags'       => array(),
                    'ids'        => array(),
                    'status'     => array( 'publish' ),
                    'limit'      => -1,
                    'page'       => 1,
                )
            );

            $status = $scope['status'];

            if ( 'any' === $status ) {
                $status = array_keys( wc_get_product_statuses() );
            }

            $status = array_values( array_map( 'sanitize_key', array_filter( (array) $status ) ) );

            $args = array(
                'status'  => $status,
                'limit'   => $scope['limit'],
                'page'    => $scope['page'],
                'orderby' => 'ID',
                'order'   => 'ASC',
                'return'  => 'objects',
                'type'    => array( 'simple', 'variable', 'variation' ),
            );

            switch ( $scope['type'] ) {
                case 'category':
                    $category_slugs = $this->term_slugs_from_ids( (array) $scope['categories'], 'product_cat' );
                    if ( ! empty( $category_slugs ) ) {
                        $args['category'] = $category_slugs;
                    }
                    break;
                case 'tag':
                    $tag_slugs = $this->term_slugs_from_ids( (array) $scope['tags'], 'product_tag' );
                    if ( ! empty( $tag_slugs ) ) {
                        $args['tag'] = $tag_slugs;
                    }
                    break;
                case 'ids':
                    $ids = array_filter( array_map( 'intval', (array) $scope['ids'] ) );
                    if ( ! empty( $ids ) ) {
                        $args['include'] = $ids;
                    }
                    break;
                case 'all':
                default:
                    break;
            }

            if ( empty( $args['status'] ) || in_array( 'any', (array) $args['status'], true ) ) {
                $args['status'] = array_keys( wc_get_product_statuses() );
            }

            return apply_filters( 'wse_product_query_args', $args, $scope );
        }

        /**
         * Organizes raw query results into parent products and their variations.
         *
         * @param array $results Raw results from WC_Product_Query.
         *
         * @return array
         */
        protected function organize_products( array $results ) {
            $organized = array();

            foreach ( $results as $item ) {
                $product_object = $item instanceof WC_Product ? $item : wc_get_product( $item );

                if ( ! $product_object ) {
                    continue;
                }

                if ( $product_object->is_type( 'variation' ) ) {
                    $parent_id = $product_object->get_parent_id();

                    if ( $parent_id ) {
                        if ( ! isset( $organized[ $parent_id ] ) ) {
                            $parent = wc_get_product( $parent_id );

                            if ( ! $parent ) {
                                continue;
                            }

                            $organized[ $parent_id ] = array(
                                'product'    => $parent,
                                'variations' => array(),
                            );
                        }

                        $organized[ $parent_id ]['variations'][ $product_object->get_id() ] = $product_object;
                    } else {
                        $organized[ $product_object->get_id() ] = array(
                            'product'    => $product_object,
                            'variations' => array(),
                        );
                    }

                    continue;
                }

                $product_id = $product_object->get_id();

                if ( ! isset( $organized[ $product_id ] ) ) {
                    $organized[ $product_id ] = array(
                        'product'    => $product_object,
                        'variations' => array(),
                    );
                } else {
                    $organized[ $product_id ]['product'] = $product_object;
                }

                if ( $product_object->is_type( 'variable' ) ) {
                    foreach ( $product_object->get_children() as $child_id ) {
                        $variation = wc_get_product( $child_id );

                        if ( $variation instanceof WC_Product_Variation ) {
                            $organized[ $product_id ]['variations'][ $variation->get_id() ] = $variation;
                        }
                    }
                }
            }

            foreach ( $organized as $product_id => $entry ) {
                $organized[ $product_id ]['variations'] = array_values( $entry['variations'] );
            }

            return array_values( $organized );
        }

        /**
         * Formats a top-level product payload.
         *
         * @param WC_Product $product    Product instance.
         * @param array      $variations Optional list of WC_Product_Variation objects.
         *
         * @return array {
         *     @type array $product  Shopify product row.
         *     @type array $variants Shopify variant rows.
         *     @type array $images   Shopify image rows.
         *     @type array $meta     Normalized product and variation metadata.
         * }
         */
        protected function format_product( WC_Product $product, array $variations = array() ) {
            $data = $this->base_product_data( $product );

            $variation_meta = array();

            if ( $product->is_type( 'variable' ) ) {
                $variation_objects = ! empty( $variations ) ? $variations : $this->get_variation_objects( $product );

                foreach ( $variation_objects as $variation ) {
                    if ( ! $variation instanceof WC_Product_Variation ) {
                        continue;
                    }

                    $variation_meta[] = $this->create_variation_meta( $variation, $product );
                }

                if ( empty( $variation_meta ) ) {
                    $variation_meta[] = $this->variation_meta_from_simple_product( $product, $data );
                }
            } else {
                $variation_meta[] = $this->variation_meta_from_simple_product( $product, $data );
            }

            $extra_variant_tags = array();
            $shopify_variants   = $this->build_shopify_variant_rows( $product, $data, $variation_meta, $extra_variant_tags );
            if ( ! empty( $extra_variant_tags ) ) {
                $data['extra_tags'] = $extra_variant_tags;
            }

            $shopify_product = $this->build_shopify_product_row( $data );
            $shopify_images   = $this->build_shopify_image_rows( $data );

            return array(
                'product'  => $shopify_product,
                'variants' => $shopify_variants,
                'images'   => $shopify_images,
                'meta'     => array(
                    'product'    => $data,
                    'variations' => $variation_meta,
                ),
            );
        }

        /**
         * Provides the default empty response structure for product loads.
         *
         * @return array
         */
        protected function empty_response() {
            return array(
                'products' => array(),
                'variants' => array(),
                'images'   => array(),
                'items'    => array(),
            );
        }

        /**
         * Creates normalized metadata for a variation instance.
         *
         * @param WC_Product_Variation $variation Variation instance.
         * @param WC_Product           $parent    Parent product.
         *
         * @return array
         */
        protected function create_variation_meta( WC_Product_Variation $variation, WC_Product $parent ) {
            $data               = $this->base_product_data( $variation, $parent );
            $data['attributes'] = $this->get_variation_attributes( $variation, $parent );

            return $data;
        }

        /**
         * Provides a synthetic variation-like structure for simple products.
         *
         * @param WC_Product $product     Product instance.
         * @param array      $product_data Normalized product data.
         *
         * @return array
         */
        protected function variation_meta_from_simple_product( WC_Product $product, array $product_data ) {
            $meta               = $product_data;
            $meta['parent_id']  = 0;
            $meta['attributes'] = array();

            return $meta;
        }

        /**
         * Builds the Shopify product row according to the target schema.
         *
         * @param array $data Normalized product data.
         *
         * @return array
         */
        protected function build_shopify_product_row( array $data ) {
            $brand_name    = isset( $data['brand']['name'] ) ? $data['brand']['name'] : '';
            $category      = $this->build_product_category_path( isset( $data['categories'] ) ? $data['categories'] : array() );
            $tags          = $this->format_shopify_tags( $data );
            $status        = isset( $data['status'] ) ? $data['status'] : 'publish';
            $type_label    = $this->determine_shopify_type( $data );
            $seo_title     = $this->build_seo_title( $data );
            $seo_desc      = $this->build_seo_description( $data );
            $published     = 'publish' === $status ? 'TRUE' : 'FALSE';
            $shopify_state = $this->map_status_to_shopify( $status );

            return array(
                'Handle'           => $data['handle'],
                'Title'            => $data['name'],
                'Body (HTML)'      => $data['description_html'],
                'Vendor'           => $brand_name,
                'Product Category' => $category,
                'Type'             => $type_label,
                'Tags'             => $tags,
                'Published'        => $published,
                'Status'           => $shopify_state,
                'SEO Title'        => $seo_title,
                'SEO Description'  => $seo_desc,
            );
        }

        /**
         * Creates Shopify variant rows for a product.
         *
         * @param WC_Product $product        Product instance.
         * @param array      $product_data   Normalized product data.
         * @param array      $variation_meta Normalized variation data.
         *
         * @return array
         */
        protected function build_shopify_variant_rows( WC_Product $product, array $product_data, array $variation_meta, array &$extra_variant_tags = array() ) {
            $option_definitions = $this->collect_option_definitions( $product );
            $rows               = array();
            $extra_variant_tags = array();
            $used_skus          = array();

            if ( empty( $variation_meta ) ) {
                $variation_meta = array( $this->variation_meta_from_simple_product( $product, $product_data ) );
            }

            $flattened_meta = $this->flatten_variation_meta( $variation_meta, $option_definitions, $product_data );

            if ( empty( $flattened_meta ) ) {
                $flattened_meta = $variation_meta;
            }

            foreach ( array_values( $flattened_meta ) as $index => $meta ) {
                $rows[] = $this->build_variant_row_from_meta( $meta, $product_data, $option_definitions, $extra_variant_tags, $used_skus, $index );
            }

            $extra_variant_tags = array_values( array_unique( array_filter( $extra_variant_tags ) ) );

            return $rows;
        }

        /**
         * Renders a Shopify variant row from normalized metadata.
         *
         * @param array $meta               Variation meta data.
         * @param array $product_data       Parent product data.
         * @param array $option_definitions Option definitions.
         *
         * @return array
         */
        protected function build_variant_row_from_meta( array $meta, array $product_data, array $option_definitions, array &$extra_variant_tags, array &$used_skus, $position ) {
            $partition      = $this->partition_variant_attributes( isset( $meta['attributes'] ) ? $meta['attributes'] : array(), $option_definitions );
            $attributes_map = $partition['primary'];

            if ( ! empty( $partition['extra'] ) ) {
                foreach ( $partition['extra'] as $attribute ) {
                    $tag_label = $this->normalize_tag_label( $attribute['name'] . ': ' . $attribute['value'] );

                    if ( '' !== $tag_label ) {
                        $extra_variant_tags[] = $tag_label;
                    }
                }
            }

            $pricing        = isset( $meta['pricing'] ) ? $meta['pricing'] : array();
            $inventory      = isset( $meta['inventory'] ) ? $meta['inventory'] : array();
            $shipping       = isset( $meta['shipping'] ) ? $meta['shipping'] : array();
            $taxes          = isset( $meta['taxes'] ) ? $meta['taxes'] : array();
            $price          = $this->determine_variant_price( $pricing );
            $compare_at     = $this->determine_compare_at_price( $pricing );
            $quantity       = isset( $inventory['stock_quantity'] ) ? $inventory['stock_quantity'] : null;
            $manage_stock   = isset( $inventory['manage_stock'] ) ? $inventory['manage_stock'] : false;
            $barcode        = isset( $inventory['barcode'] ) ? $inventory['barcode'] : '';
            $sku            = isset( $inventory['sku'] ) ? $inventory['sku'] : '';
            $backorders     = isset( $inventory['backorders'] ) ? $inventory['backorders'] : 'no';
            $weight         = isset( $shipping['weight'] ) ? $shipping['weight'] : '';
            $requires_ship  = isset( $meta['requires_shipping'] ) ? $meta['requires_shipping'] : true;
            $tax_status     = isset( $taxes['status'] ) ? $taxes['status'] : 'taxable';
            $weight_unit    = get_option( 'woocommerce_weight_unit', 'kg' );
            $inventory_qty  = null !== $quantity ? (float) $quantity : 0;
            $inventory_qty  = wc_stock_amount( $inventory_qty );
            if ( ! $manage_stock && ( null === $quantity || '' === $quantity ) ) {
                $inventory_qty = 0;
            }
            $inventory_pol  = $this->map_inventory_policy( $backorders );
            $inventory_track = $manage_stock ? 'shopify' : '';
            $requires_value = $requires_ship ? 'TRUE' : 'FALSE';
            $tax_enabled    = isset( $taxes['enabled'] ) ? (bool) $taxes['enabled'] : wc_tax_enabled();
            $taxable_value  = ( $tax_enabled && 'none' !== $tax_status ) ? 'TRUE' : 'FALSE';
            $sku            = $this->resolve_variant_sku( $sku, $meta, $product_data, $used_skus, $position );

            $row = array(
                'Handle'                => $product_data['handle'],
                'Option1 Name'          => '',
                'Option1 Value'         => '',
                'Option2 Name'          => '',
                'Option2 Value'         => '',
                'Option3 Name'          => '',
                'Option3 Value'         => '',
                'Variant SKU'           => $sku,
                'Variant Price'         => $price,
                'Compare At Price'      => $compare_at,
                'Variant Inventory Qty' => $inventory_qty,
                'Inventory Policy'      => $inventory_pol,
                'Inventory Tracker'     => $inventory_track,
                'Variant Barcode'       => $barcode,
                'Variant Weight'        => $weight,
                'Variant Weight Unit'   => $weight_unit,
                'Variant Requires Shipping' => $requires_value,
                'Variant Taxable'       => $taxable_value,
            );

            for ( $i = 0; $i < 3; $i++ ) {
                $name_key  = 'Option' . ( $i + 1 ) . ' Name';
                $value_key = 'Option' . ( $i + 1 ) . ' Value';

                if ( isset( $option_definitions[ $i ] ) ) {
                    $definition        = $option_definitions[ $i ];
                    $row[ $name_key ]  = $definition['name'];
                    $option_slug       = $definition['slug'];
                    $value             = isset( $attributes_map[ $option_slug ] ) ? $attributes_map[ $option_slug ] : '';

                    if ( '' === $value && 'title' === $option_slug ) {
                        $value = __( 'Default Title', 'woo-to-shopify-exporter' );
                    }

                    $row[ $value_key ] = $value;
                } else {
                    $row[ $name_key ]  = '';
                    $row[ $value_key ] = '';
                }
            }

            return $row;
        }

        /**
         * Flattens variation metadata using a cartesian expansion of option values.
         *
         * @param array $variation_meta   Variation metadata entries.
         * @param array $option_definitions Shopify option definitions.
         * @param array $product_data     Normalized product data.
         *
         * @return array
         */
        protected function flatten_variation_meta( array $variation_meta, array $option_definitions, array $product_data ) {
            if ( empty( $variation_meta ) || empty( $option_definitions ) ) {
                return $variation_meta;
            }

            $value_sets   = $this->collect_variant_value_sets( $variation_meta, $option_definitions, $product_data );
            $combinations = $this->build_cartesian_attribute_sets( $value_sets, $option_definitions );
            $meta_index   = array();

            foreach ( $variation_meta as $meta ) {
                $signature = $this->build_variant_signature_from_meta( $meta, $option_definitions );

                if ( '' === $signature ) {
                    $signature = 'variant::' . ( isset( $meta['id'] ) ? (int) $meta['id'] : uniqid( 'variant', true ) );
                }

                if ( ! isset( $meta_index[ $signature ] ) ) {
                    $meta_index[ $signature ] = $meta;
                }
            }

            $flattened       = array();
            $consumed_signatures = array();

            foreach ( $combinations as $attributes_map ) {
                $signature = $this->build_variant_signature_from_attributes_map( $attributes_map, $option_definitions );

                if ( isset( $meta_index[ $signature ] ) ) {
                    $meta                       = $meta_index[ $signature ];
                    $meta['attributes']         = array_values( array_filter( $attributes_map ) );
                    $flattened[]                = $meta;
                    $consumed_signatures[ $signature ] = true;
                }
            }

            foreach ( $meta_index as $signature => $meta ) {
                if ( isset( $consumed_signatures[ $signature ] ) ) {
                    continue;
                }

                $flattened[] = $meta;
            }

            return $flattened;
        }

        /**
         * Collects unique attribute values for option definitions.
         *
         * @param array $variation_meta   Variation metadata entries.
         * @param array $option_definitions Shopify option definitions.
         * @param array $product_data     Normalized product data.
         *
         * @return array
         */
        protected function collect_variant_value_sets( array $variation_meta, array $option_definitions, array $product_data ) {
            $sets = array();

            foreach ( $option_definitions as $definition ) {
                $slug         = isset( $definition['slug'] ) ? $definition['slug'] : '';
                $sets[ $slug ] = array();
            }

            foreach ( $variation_meta as $meta ) {
                $attributes = isset( $meta['attributes'] ) ? $meta['attributes'] : array();

                foreach ( $attributes as $attribute ) {
                    $slug = isset( $attribute['slug'] ) ? $attribute['slug'] : '';

                    if ( '' === $slug || ! isset( $sets[ $slug ] ) ) {
                        continue;
                    }

                    $key = $this->variant_value_key( $slug, $attribute );

                    if ( '' === $key ) {
                        continue;
                    }

                    $sets[ $slug ][ $key ] = $attribute;
                }
            }

            if ( ! empty( $product_data['attributes'] ) ) {
                foreach ( (array) $product_data['attributes'] as $attribute ) {
                    if ( empty( $attribute['variation'] ) ) {
                        continue;
                    }

                    $slug = wc_variation_attribute_name( isset( $attribute['slug'] ) ? $attribute['slug'] : '' );

                    if ( '' === $slug || ! isset( $sets[ $slug ] ) ) {
                        continue;
                    }

                    foreach ( (array) $attribute['options'] as $option ) {
                        $entry = array(
                            'slug'       => $slug,
                            'value'      => $option,
                            'value_slug' => sanitize_title( $option ),
                        );

                        $key = $this->variant_value_key( $slug, $entry );

                        if ( '' === $key ) {
                            continue;
                        }

                        if ( ! isset( $sets[ $slug ][ $key ] ) ) {
                            $sets[ $slug ][ $key ] = $entry;
                        }
                    }
                }
            }

            foreach ( $sets as $slug => $values ) {
                if ( ! empty( $values ) ) {
                    continue;
                }

                if ( 'title' === $slug ) {
                    $sets[ $slug ]['title::default'] = array(
                        'slug'       => 'title',
                        'value'      => __( 'Default Title', 'woo-to-shopify-exporter' ),
                        'value_slug' => 'default',
                    );
                } else {
                    $sets[ $slug ][ $slug . '::' ] = array(
                        'slug'       => $slug,
                        'value'      => '',
                        'value_slug' => '',
                    );
                }
            }

            return $sets;
        }

        /**
         * Produces a cartesian product of attribute sets keyed by option slug.
         *
         * @param array $value_sets        Attribute value sets keyed by slug.
         * @param array $option_definitions Shopify option definitions.
         *
         * @return array
         */
        protected function build_cartesian_attribute_sets( array $value_sets, array $option_definitions ) {
            $combinations = array( array() );

            foreach ( $option_definitions as $definition ) {
                $slug   = isset( $definition['slug'] ) ? $definition['slug'] : '';
                $values = isset( $value_sets[ $slug ] ) ? array_values( $value_sets[ $slug ] ) : array();

                if ( empty( $values ) ) {
                    $values = array(
                        array(
                            'slug'       => $slug,
                            'value'      => '',
                            'value_slug' => '',
                        ),
                    );
                }

                $next = array();

                foreach ( $combinations as $combination ) {
                    foreach ( $values as $value ) {
                        $candidate          = $combination;
                        $candidate[ $slug ] = $value;
                        $next[]             = $candidate;
                    }
                }

                $combinations = $next;
            }

            return $combinations;
        }

        /**
         * Builds a signature for variation metadata.
         *
         * @param array $meta               Variation metadata entry.
         * @param array $option_definitions Shopify option definitions.
         *
         * @return string
         */
        protected function build_variant_signature_from_meta( array $meta, array $option_definitions ) {
            $attributes   = isset( $meta['attributes'] ) ? $meta['attributes'] : array();
            $attributes_map = array();

            foreach ( $attributes as $attribute ) {
                if ( empty( $attribute['slug'] ) ) {
                    continue;
                }

                $attributes_map[ $attribute['slug'] ] = $attribute;
            }

            return $this->build_variant_signature_from_attributes_map( $attributes_map, $option_definitions );
        }

        /**
         * Builds a signature from an attribute map keyed by option slug.
         *
         * @param array $attributes_map     Attribute map keyed by slug.
         * @param array $option_definitions Shopify option definitions.
         *
         * @return string
         */
        protected function build_variant_signature_from_attributes_map( array $attributes_map, array $option_definitions ) {
            $parts = array();

            foreach ( $option_definitions as $definition ) {
                $slug = isset( $definition['slug'] ) ? $definition['slug'] : '';

                if ( '' === $slug ) {
                    continue;
                }

                if ( isset( $attributes_map[ $slug ] ) && is_array( $attributes_map[ $slug ] ) ) {
                    $parts[] = $this->variant_value_key( $slug, $attributes_map[ $slug ] );
                } else {
                    $parts[] = strtolower( $slug . '::' );
                }
            }

            return implode( '|', $parts );
        }

        /**
         * Generates a normalized key for a variant attribute value.
         *
         * @param string $slug      Attribute slug.
         * @param array  $attribute Attribute data.
         *
         * @return string
         */
        protected function variant_value_key( $slug, array $attribute ) {
            if ( '' === $slug ) {
                return '';
            }

            $value_slug = isset( $attribute['value_slug'] ) ? $attribute['value_slug'] : '';
            $value      = isset( $attribute['value'] ) ? $attribute['value'] : '';
            $normalized = '' !== $value_slug ? $value_slug : $value;
            $normalized = sanitize_title( (string) $normalized );

            return strtolower( $slug . '::' . $normalized );
        }

        /**
         * Resolves a SKU ensuring uniqueness and fallbacks when absent.
         *
         * @param string $sku         Original SKU value.
         * @param array  $meta        Variation metadata entry.
         * @param array  $product_data Normalized product data.
         * @param array  $used_skus   Registry of used SKUs.
         * @param int    $position    Variant index.
         *
         * @return string
         */
        protected function resolve_variant_sku( $sku, array $meta, array $product_data, array &$used_skus, $position ) {
            $sku = trim( (string) $sku );

            if ( '' !== $sku ) {
                return $this->ensure_unique_sku( $sku, $used_skus );
            }

            $fallback = $this->generate_fallback_sku( $meta, $product_data, $position, $used_skus );

            return $this->ensure_unique_sku( $fallback, $used_skus );
        }

        /**
         * Ensures a SKU is unique by appending counters when necessary.
         *
         * @param string $candidate SKU candidate.
         * @param array  $used_skus Registry of used SKUs.
         *
         * @return string
         */
        protected function ensure_unique_sku( $candidate, array &$used_skus ) {
            $candidate  = '' !== $candidate ? $candidate : 'SKU';
            $normalized = strtolower( $candidate );

            if ( ! isset( $used_skus[ $normalized ] ) ) {
                $used_skus[ $normalized ] = 1;

                return $candidate;
            }

            $count       = $used_skus[ $normalized ];
            $base        = $candidate;
            $final       = $candidate;
            $final_lower = $normalized;

            do {
                $count++;
                $final       = $base . '-' . $count;
                $final_lower = strtolower( $final );
            } while ( isset( $used_skus[ $final_lower ] ) );

            $used_skus[ $normalized ]  = $count;
            $used_skus[ $final_lower ] = 1;

            return $final;
        }

        /**
         * Generates a fallback SKU from product and attribute context.
         *
         * @param array $meta         Variation metadata entry.
         * @param array $product_data Normalized product data.
         * @param int   $position     Variant index (zero based).
         * @param array $used_skus    Registry of used SKUs.
         *
         * @return string
         */
        protected function generate_fallback_sku( array $meta, array $product_data, $position, array $used_skus ) {
            $handle = isset( $product_data['handle'] ) ? $product_data['handle'] : '';
            $base   = $this->normalize_sku_segment( $handle );

            if ( '' === $base ) {
                $base = 'PRODUCT';
            }

            $attribute_parts = array();

            if ( ! empty( $meta['attributes'] ) ) {
                foreach ( (array) $meta['attributes'] as $attribute ) {
                    $value = '';

                    if ( isset( $attribute['value_slug'] ) && '' !== $attribute['value_slug'] ) {
                        $value = $attribute['value_slug'];
                    } elseif ( isset( $attribute['value'] ) ) {
                        $value = $attribute['value'];
                    }

                    $segment = $this->normalize_sku_segment( $value );

                    if ( '' !== $segment ) {
                        $attribute_parts[] = $segment;
                    }
                }
            }

            if ( empty( $attribute_parts ) ) {
                $attribute_parts[] = str_pad( (string) ( $position + 1 ), 3, '0', STR_PAD_LEFT );
            }

            $candidate = $base . '-' . implode( '-', $attribute_parts );
            $candidate = trim( $candidate, '-' );

            if ( '' === $candidate ) {
                $candidate = 'SKU-' . str_pad( (string) ( $position + 1 ), 3, '0', STR_PAD_LEFT );
            }

            return $candidate;
        }

        /**
         * Normalizes a string segment for SKU composition.
         *
         * @param string $value Raw segment value.
         *
         * @return string
         */
        protected function normalize_sku_segment( $value ) {
            $value = (string) $value;
            $value = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $value );
            $value = trim( $value, '-' );

            return strtoupper( $value );
        }

        /**
         * Splits variation attributes into Shopify option columns and overflow entries.
         *
         * @param array $attributes         Attribute list from the variation meta.
         * @param array $option_definitions Option definitions selected for Shopify.
         *
         * @return array
         */
        protected function partition_variant_attributes( array $attributes, array $option_definitions ) {
            $primary = array();
            $extra   = array();

            $allowed = array();

            foreach ( $option_definitions as $definition ) {
                if ( empty( $definition['slug'] ) ) {
                    continue;
                }

                $allowed[] = $definition['slug'];
            }

            foreach ( $attributes as $attribute ) {
                if ( empty( $attribute['slug'] ) ) {
                    continue;
                }

                $slug  = $attribute['slug'];
                $value = isset( $attribute['value'] ) ? $attribute['value'] : '';

                if ( in_array( $slug, $allowed, true ) ) {
                    $primary[ $slug ] = $value;
                } else {
                    $extra[] = $attribute;
                }
            }

            return array(
                'primary' => $primary,
                'extra'   => $extra,
            );
        }

        /**
         * Builds Shopify image rows for a product.
         *
         * @param array $data Normalized product data.
         *
         * @return array
         */
        protected function build_shopify_image_rows( array $data ) {
            $rows    = array();
            $images  = isset( $data['images'] ) ? $data['images'] : array();
            $alt_fallback = $data['name'];
            $position = 1;

            if ( ! empty( $images['featured'] ) ) {
                $featured_alt = isset( $images['featured']['alt'] ) ? $images['featured']['alt'] : '';
                $rows[] = array(
                    'Handle'         => $data['handle'],
                    'Image Src'      => $images['featured']['url'],
                    'Image Position' => $position++,
                    'Image Alt Text' => $featured_alt ? $featured_alt : $alt_fallback,
                );
            }

            if ( ! empty( $images['gallery'] ) && is_array( $images['gallery'] ) ) {
                foreach ( $images['gallery'] as $image ) {
                    if ( empty( $image['url'] ) ) {
                        continue;
                    }

                    $gallery_alt = isset( $image['alt'] ) ? $image['alt'] : '';
                    $rows[] = array(
                        'Handle'         => $data['handle'],
                        'Image Src'      => $image['url'],
                        'Image Position' => $position++,
                        'Image Alt Text' => $gallery_alt ? $gallery_alt : $alt_fallback,
                    );
                }
            }

            return $rows;
        }

        /**
         * Normalizes a tag label for multilingual catalogs.
         *
         * @param string $label Raw label.
         *
         * @return string
         */
        protected function normalize_tag_label( $label ) {
            $label = $this->decode_text( $label );
            $label = str_replace( array( '،', '؛', ';' ), ',', $label );
            $label = preg_replace( '/\s+/u', ' ', $label );
            $label = trim( $label );
            $label = trim( $label, ',' );

            return $label;
        }

        /**
         * Formats product tags for Shopify CSV output.
         *
         * @param array $data Product data payload.
         *
         * @return string
         */
        protected function format_shopify_tags( array $data ) {
            $tag_values = array();

            if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
                foreach ( $data['tags'] as $tag ) {
                    if ( empty( $tag['name'] ) ) {
                        continue;
                    }

                    $tag_values[] = $this->normalize_tag_label( $tag['name'] );
                }
            }

            if ( ! empty( $data['extra_tags'] ) && is_array( $data['extra_tags'] ) ) {
                foreach ( $data['extra_tags'] as $tag ) {
                    $tag_values[] = $this->normalize_tag_label( $tag );
                }
            }

            $tag_values = array_values( array_unique( array_filter( $tag_values ) ) );

            return implode( ', ', $tag_values );
        }

        /**
         * Builds a hierarchical product category string for Shopify.
         *
         * @param array $categories Category list.
         *
         * @return string
         */
        protected function build_product_category_path( array $categories ) {
            $primary = $this->select_primary_category( $categories );

            if ( ! $primary ) {
                return '';
            }

            $chain_ids = array();

            if ( ! empty( $primary['ancestors'] ) && is_array( $primary['ancestors'] ) ) {
                $chain_ids = array_merge( array_reverse( $primary['ancestors'] ), array( $primary['id'] ) );
            } else {
                $chain_ids = array( $primary['id'] );
            }

            $names = array();

            foreach ( $chain_ids as $term_id ) {
                $term = get_term( $term_id, 'product_cat' );

                if ( $term && ! is_wp_error( $term ) ) {
                    $names[] = $this->decode_text( $term->name );
                }
            }

            if ( empty( $names ) ) {
                $names[] = $primary['name'];
            }

            return implode( ' > ', array_filter( array_unique( $names ) ) );
        }

        /**
         * Selects the primary category for a product.
         *
         * @param array $categories Category list.
         *
         * @return array|null
         */
        protected function select_primary_category( array $categories ) {
            if ( empty( $categories ) ) {
                return null;
            }

            usort(
                $categories,
                function ( $a, $b ) {
                    $a_parent = isset( $a['parent'] ) ? (int) $a['parent'] : 0;
                    $b_parent = isset( $b['parent'] ) ? (int) $b['parent'] : 0;

                    if ( $a_parent && ! $b_parent ) {
                        return 1;
                    }

                    if ( $b_parent && ! $a_parent ) {
                        return -1;
                    }

                    $a_depth = isset( $a['ancestors'] ) ? count( (array) $a['ancestors'] ) : 0;
                    $b_depth = isset( $b['ancestors'] ) ? count( (array) $b['ancestors'] ) : 0;

                    if ( $a_depth !== $b_depth ) {
                        return $a_depth - $b_depth;
                    }

                    return strcmp( $a['name'], $b['name'] );
                }
            );

            return $categories[0];
        }

        /**
         * Determines the Shopify product type label.
         *
         * @param array $data Product data payload.
         *
         * @return string
         */
        protected function determine_shopify_type( array $data ) {
            $attributes = isset( $data['attributes'] ) ? $data['attributes'] : array();
            $type_value = $this->locate_product_type_attribute_value( $attributes );

            if ( '' !== $type_value ) {
                return $type_value;
            }

            $primary_category = $this->select_primary_category( isset( $data['categories'] ) ? $data['categories'] : array() );

            if ( $primary_category ) {
                return $primary_category['name'];
            }

            return $this->get_product_type_label( isset( $data['type'] ) ? $data['type'] : 'simple' );
        }

        /**
         * Attempts to read a custom product type attribute.
         *
         * @param array $attributes Product attributes.
         *
         * @return string
         */
        protected function locate_product_type_attribute_value( array $attributes ) {
            if ( empty( $attributes ) ) {
                return '';
            }

            $target_slugs = array( 'product-type', 'product_type', 'product type', 'نوع-المنتج', 'نوع المنتج', 'producttype' );

            foreach ( $attributes as $attribute ) {
                $slug = isset( $attribute['slug'] ) ? sanitize_title( $attribute['slug'] ) : '';
                $name = isset( $attribute['name'] ) ? sanitize_title( $attribute['name'] ) : '';

                if ( in_array( $slug, $target_slugs, true ) || in_array( $name, $target_slugs, true ) ) {
                    if ( ! empty( $attribute['options'] ) ) {
                        return $this->decode_text( $attribute['options'][0] );
                    }
                }
            }

            return '';
        }

        /**
         * Collects option definitions for Shopify variant columns.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array
         */
        protected function collect_option_definitions( WC_Product $product ) {
            if ( ! $product->is_type( 'variable' ) ) {
                return $this->default_option_definitions();
            }

            $definitions = array();

            foreach ( $product->get_attributes() as $attribute ) {
                if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
                    continue;
                }

                $definitions[] = array(
                    'slug' => wc_variation_attribute_name( $attribute->get_name() ),
                    'name' => $this->decode_text( wc_attribute_label( $attribute->get_name() ) ),
                );
            }

            if ( empty( $definitions ) ) {
                return $this->default_option_definitions();
            }

            return array_slice( $definitions, 0, 3 );
        }

        /**
         * Returns the default Shopify option definition.
         *
         * @return array
         */
        protected function default_option_definitions() {
            return array(
                array(
                    'slug' => 'title',
                    'name' => __( 'Title', 'woo-to-shopify-exporter' ),
                ),
            );
        }

        /**
         * Retrieves a human readable product type label.
         *
         * @param string $type Product type key.
         *
         * @return string
         */
        protected function get_product_type_label( $type ) {
            if ( function_exists( 'wc_get_product_type_label' ) ) {
                $label = wc_get_product_type_label( $type );

                if ( $label ) {
                    return $this->decode_text( $label );
                }
            }

            return $this->decode_text( ucfirst( $type ) );
        }

        /**
         * Builds an SEO title suggestion.
         *
         * @param array $data Normalized product data.
         *
         * @return string
         */
        protected function build_seo_title( array $data ) {
            $title = '';

            if ( ! empty( $data['seo']['title'] ) ) {
                $title = $data['seo']['title'];
            } elseif ( isset( $data['name'] ) ) {
                $title = $data['name'];
            }

            return $this->truncate_text( $title, 70 );
        }

        /**
         * Builds an SEO description suggestion.
         *
         * @param array $data Normalized product data.
         *
         * @return string
         */
        protected function build_seo_description( array $data ) {
            if ( ! empty( $data['seo']['description'] ) ) {
                $description = $data['seo']['description'];
            } else {
                $description = isset( $data['short_description'] ) && '' !== $data['short_description']
                    ? $data['short_description']
                    : ( isset( $data['description_html'] ) ? wp_strip_all_tags( $data['description_html'] ) : '' );
            }

            return $this->truncate_text( $description, 320 );
        }

        /**
         * Truncates text to a given length preserving multibyte characters.
         *
         * @param string $text       Input text.
         * @param int    $max_length Maximum length.
         *
         * @return string
         */
        protected function truncate_text( $text, $max_length ) {
            $text = trim( wp_strip_all_tags( (string) $text ) );

            if ( $max_length <= 0 ) {
                return $text;
            }

            if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
                    return mb_substr( $text, 0, $max_length, 'UTF-8' );
                }

                return $text;
            }

            if ( strlen( $text ) > $max_length ) {
                return substr( $text, 0, $max_length );
            }

            return $text;
        }

        /**
         * Joins term names into a delimited list.
         *
         * @param array  $terms     Term list.
         * @param string $separator Separator string.
         *
         * @return string
         */
        protected function join_term_names( array $terms, $separator = ', ' ) {
            if ( empty( $terms ) ) {
                return '';
            }

            $names = array();

            foreach ( $terms as $term ) {
                if ( empty( $term['name'] ) ) {
                    continue;
                }

                $names[] = $term['name'];
            }

            return implode( $separator, $names );
        }

        /**
         * Maps WordPress product statuses to Shopify equivalents.
         *
         * @param string $status WooCommerce status key.
         *
         * @return string
         */
        protected function map_status_to_shopify( $status ) {
            $status = sanitize_key( $status );

            switch ( $status ) {
                case 'draft':
                case 'pending':
                case 'private':
                case 'future':
                    return 'draft';
                case 'trash':
                case 'archived':
                    return 'archived';
                case 'publish':
                default:
                    return 'active';
            }
        }

        /**
         * Maps WooCommerce backorder settings to Shopify inventory policies.
         *
         * @param string $backorders Backorder mode.
         *
         * @return string
         */
        protected function map_inventory_policy( $backorders ) {
            $backorders = sanitize_key( (string) $backorders );

            if ( 'no' === $backorders || '' === $backorders ) {
                return 'deny';
            }

            return 'continue';
        }

        /**
         * Determines the compare-at price for Shopify variants.
         *
         * @param array $pricing Pricing data.
         *
         * @return string
         */
        protected function determine_compare_at_price( array $pricing ) {
            $regular = isset( $pricing['regular_price'] ) ? $pricing['regular_price'] : '';
            $sale    = isset( $pricing['sale_price'] ) ? $pricing['sale_price'] : '';

            if ( '' === $regular || '' === $sale ) {
                return '';
            }

            if ( $this->is_sale_price_active( $pricing ) && (float) $regular > (float) $sale ) {
                return $regular;
            }

            return '';
        }

        /**
         * Resolves the effective variant price based on sale windows.
         *
         * @param array $pricing Pricing data.
         *
         * @return string
         */
        protected function determine_variant_price( array $pricing ) {
            $price   = isset( $pricing['price'] ) ? $pricing['price'] : '';
            $regular = isset( $pricing['regular_price'] ) ? $pricing['regular_price'] : '';
            $sale    = isset( $pricing['sale_price'] ) ? $pricing['sale_price'] : '';

            if ( $this->is_sale_price_active( $pricing ) && '' !== $sale ) {
                return $sale;
            }

            if ( '' !== $price ) {
                return $price;
            }

            return $regular;
        }

        /**
         * Determines whether a sale price is currently active.
         *
         * @param array $pricing Pricing data.
         *
         * @return bool
         */
        protected function is_sale_price_active( array $pricing ) {
            $sale = isset( $pricing['sale_price'] ) ? $pricing['sale_price'] : '';

            if ( '' === $sale ) {
                return false;
            }

            $start = isset( $pricing['sale_start'] ) ? (int) $pricing['sale_start'] : null;
            $end   = isset( $pricing['sale_end'] ) ? (int) $pricing['sale_end'] : null;
            $now   = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();

            if ( null !== $start && $start > 0 && $now < $start ) {
                return false;
            }

            if ( null !== $end && $end > 0 && $now > $end ) {
                return false;
            }

            return true;
        }

        /**
         * Provides normalized product fields shared between product types.
         *
         * @param WC_Product      $product Product instance.
         * @param WC_Product|null $parent  Optional parent product (used for inherited data).
         *
         * @return array
         */
        protected function base_product_data( WC_Product $product, $parent = null ) {
            $source_product = $parent instanceof WC_Product ? $parent : $product;

            $brand        = $this->get_brand( $product, $parent );
            $images       = $this->collect_images( $product, $parent );
            $handle       = $this->normalize_handle( $product->get_slug(), $product->get_name() );
            $description  = $this->clean_description_html( $product->get_description() );
            $short_desc   = $this->clean_short_description( $product->get_short_description() );
            $attributes   = $this->collect_product_attributes( $source_product );
            $seo          = $this->collect_seo_data( $source_product->get_id() );

            return array(
                'id'                => (int) $product->get_id(),
                'parent_id'         => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0,
                'type'              => $product->get_type(),
                'status'            => $product->get_status(),
                'name'              => $this->decode_text( $product->get_name() ),
                'handle'            => $handle,
                'slug'              => $product->get_slug(),
                'permalink'         => $product->get_permalink(),
                'description_html'  => $description,
                'short_description' => $short_desc,
                'brand'             => $brand,
                'categories'        => $this->collect_terms( $source_product->get_id(), 'product_cat' ),
                'tags'              => $this->collect_terms( $source_product->get_id(), 'product_tag' ),
                'images'            => $images,
                'pricing'           => $this->collect_pricing_data( $product ),
                'taxes'             => $this->collect_tax_data( $product ),
                'inventory'         => $this->collect_inventory_data( $product, $parent ),
                'shipping'          => $this->collect_shipping_data( $product, $parent ),
                'requires_shipping' => $product->needs_shipping(),
                'attributes'        => $attributes,
                'seo'               => $seo,
                'locale'            => $this->current_locale(),
                'created_at'        => $product->get_date_created() ? $product->get_date_created()->getTimestamp() : null,
                'updated_at'        => $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : null,
            );
        }

        /**
         * Gathers variable product variations.
         *
         * @param WC_Product_Variable $product Variable product.
         *
         * @return array
         */
        protected function get_variation_objects( WC_Product_Variable $product ) {
            $variations = array();

            foreach ( $product->get_children() as $child_id ) {
                $variation = wc_get_product( $child_id );

                if ( ! $variation instanceof WC_Product_Variation ) {
                    continue;
                }

                $variations[] = $variation;
            }

            return $variations;
        }

        /**
         * Returns variation attributes limited to selectable ones.
         *
         * @param WC_Product_Variation $variation Variation instance.
         * @param WC_Product|null      $parent    Parent product if available.
         *
         * @return array
         */
        protected function get_variation_attributes( WC_Product_Variation $variation, $parent = null ) {
            $result  = array();
            $allowed = array();

            if ( $parent instanceof WC_Product_Variable ) {
                foreach ( $parent->get_attributes() as $attribute ) {
                    if ( ! $attribute->get_variation() ) {
                        continue;
                    }

                    $allowed[ wc_variation_attribute_name( $attribute->get_name() ) ] = $attribute;
                }
            }

            $attributes = $variation->get_attributes();

            foreach ( $attributes as $key => $value ) {
                if ( '' === $value ) {
                    continue;
                }

                $normalized = wc_variation_attribute_name( $key );

                if ( ! empty( $allowed ) && ! isset( $allowed[ $normalized ] ) ) {
                    continue;
                }

                $attribute_object = isset( $allowed[ $normalized ] ) ? $allowed[ $normalized ] : null;
                $result[]         = $this->format_variation_attribute_value( $normalized, $value, $attribute_object );
            }

            return array_values( array_filter( $result ) );
        }

        /**
         * Normalizes a variation attribute entry.
         *
         * @param string                   $attribute_key Attribute slug.
         * @param string                   $value         Stored option value.
         * @param WC_Product_Attribute|nul $attribute     Attribute definition.
         *
         * @return array|null
         */
        protected function format_variation_attribute_value( $attribute_key, $value, $attribute = null ) {
            $value = (string) $value;

            $label = $attribute ? wc_attribute_label( $attribute->get_name() ) : $attribute_key;
            $label = $this->decode_text( $label );

            $entry = array(
                'name'       => $label,
                'slug'       => $attribute_key,
                'value'      => $this->decode_text( $value ),
                'value_slug' => $value,
            );

            if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
                $term = get_term_by( 'slug', $value, $attribute->get_name() );

                if ( $term && ! is_wp_error( $term ) ) {
                    $entry['value']    = $this->decode_text( $term->name );
                    $entry['term_id']  = (int) $term->term_id;
                    $entry['taxonomy'] = $attribute->get_name();
                }
            } elseif ( $attribute instanceof WC_Product_Attribute ) {
                $options = (array) $attribute->get_options();
                foreach ( $options as $option ) {
                    $candidate = sanitize_title( $option );
                    if ( $candidate === $value || $option === $value ) {
                        $entry['value'] = $this->decode_text( $option );
                        break;
                    }
                }
            }

            return $entry;
        }

        /**
         * Collects a product's attribute definitions.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array
         */
        protected function collect_product_attributes( WC_Product $product ) {
            $attributes = array();

            foreach ( $product->get_attributes() as $attribute ) {
                if ( ! $attribute instanceof WC_Product_Attribute ) {
                    continue;
                }

                $slug  = sanitize_title( $attribute->get_name() );
                $label = $this->decode_text( wc_attribute_label( $attribute->get_name() ) );
                $options = array();

                if ( $attribute->is_taxonomy() ) {
                    $option_terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
                    if ( ! is_wp_error( $option_terms ) ) {
                        foreach ( $option_terms as $option ) {
                            $options[] = $this->decode_text( $option );
                        }
                    }
                } else {
                    foreach ( (array) $attribute->get_options() as $option ) {
                        $options[] = $this->decode_text( $option );
                    }
                }

                $attributes[] = array(
                    'slug'      => $slug,
                    'name'      => $label,
                    'options'   => array_values( array_unique( array_filter( $options ) ) ),
                    'variation' => (bool) $attribute->get_variation(),
                );
            }

            return $attributes;
        }

        /**
         * Collects taxonomy terms for a given product.
         *
         * @param int    $product_id Product ID.
         * @param string $taxonomy   Taxonomy slug.
         *
         * @return array
         */
        protected function collect_terms( $product_id, $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                return array();
            }

            $terms = get_the_terms( $product_id, $taxonomy );

            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                return array();
            }

            return array_map(
                function ( $term ) {
                    return array(
                        'id'   => (int) $term->term_id,
                        'name' => $this->decode_text( $term->name ),
                        'slug' => $term->slug,
                        'parent'    => (int) $term->parent,
                        'ancestors' => array_map( 'intval', get_ancestors( $term->term_id, $term->taxonomy ) ),
                    );
                },
                $terms
            );
        }

        /**
         * Collects product images (featured + gallery) with parent fallbacks.
         *
         * @param WC_Product      $product Product instance.
         * @param WC_Product|null $parent  Optional parent product for fallback.
         *
         * @return array
         */
        protected function collect_images( WC_Product $product, $parent = null ) {
            $featured_id = $product->get_image_id();

            if ( ! $featured_id && $parent instanceof WC_Product ) {
                $featured_id = $parent->get_image_id();
            }

            $gallery_ids = $product->get_gallery_image_ids();

            if ( empty( $gallery_ids ) && $parent instanceof WC_Product ) {
                $gallery_ids = $parent->get_gallery_image_ids();
            }

            $featured = $this->image_from_attachment( $featured_id );
            $gallery  = array();

            foreach ( array_unique( array_filter( $gallery_ids ) ) as $attachment_id ) {
                $image = $this->image_from_attachment( $attachment_id );
                if ( $image ) {
                    $gallery[] = $image;
                }
            }

            if ( $featured ) {
                $gallery = array_values(
                    array_filter(
                        $gallery,
                        function ( $image ) use ( $featured ) {
                            return $image['id'] !== $featured['id'];
                        }
                    )
                );
            }

            return array(
                'featured' => $featured,
                'gallery'  => $gallery,
            );
        }

        /**
         * Produces image metadata for an attachment.
         *
         * @param int $attachment_id Attachment ID.
         *
         * @return array|null
         */
        protected function image_from_attachment( $attachment_id ) {
            $attachment_id = (int) $attachment_id;

            if ( ! $attachment_id ) {
                return null;
            }

            $src = wp_get_attachment_image_src( $attachment_id, 'full' );

            if ( ! $src ) {
                return null;
            }

            return array(
                'id'     => $attachment_id,
                'url'    => $src[0],
                'width'  => isset( $src[1] ) ? (int) $src[1] : null,
                'height' => isset( $src[2] ) ? (int) $src[2] : null,
                'alt'    => $this->decode_text( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
            );
        }

        /**
         * Collects pricing information for a product.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array
         */
        protected function collect_pricing_data( WC_Product $product ) {
            return array(
                'currency'      => get_woocommerce_currency(),
                'regular_price' => $product->get_regular_price(),
                'sale_price'    => $product->get_sale_price(),
                'price'         => $product->get_price(),
                'sale_start'    => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->getTimestamp() : null,
                'sale_end'      => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->getTimestamp() : null,
            );
        }

        /**
         * Collects tax configuration details for a product.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array
         */
        protected function collect_tax_data( WC_Product $product ) {
            return array(
                'enabled'    => wc_tax_enabled(),
                'status'     => $product->get_tax_status(),
                'tax_class'  => $product->get_tax_class(),
                'prices_inc' => wc_prices_include_tax(),
            );
        }

        /**
         * Collects inventory related data.
         *
         * @param WC_Product      $product Product instance.
         * @param WC_Product|null $parent  Optional parent for fallback meta.
         *
         * @return array
         */
        protected function collect_inventory_data( WC_Product $product, $parent = null ) {
            $barcode = $this->resolve_barcode( $product );

            if ( ! $barcode && $parent instanceof WC_Product ) {
                $barcode = $this->resolve_barcode( $parent );
            }

            $sku = $product->get_sku();

            if ( ! $sku && $parent instanceof WC_Product ) {
                $sku = $parent->get_sku();
            }

            $manage_stock = wc_string_to_bool( $product->get_manage_stock() );

            return array(
                'sku'            => $sku,
                'barcode'        => $barcode,
                'manage_stock'   => $manage_stock,
                'stock_status'   => $product->get_stock_status(),
                'stock_quantity' => $manage_stock ? $product->get_stock_quantity() : null,
                'backorders'     => $product->get_backorders(),
            );
        }

        /**
         * Collects shipping related data.
         *
         * @param WC_Product      $product Product instance.
         * @param WC_Product|null $parent  Optional parent product for fallback values.
         *
         * @return array
         */
        protected function collect_shipping_data( WC_Product $product, $parent = null ) {
            $weight = $product->get_weight();

            if ( '' === $weight && $parent instanceof WC_Product ) {
                $weight = $parent->get_weight();
            }

            $dimensions = array(
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            );

            if ( $parent instanceof WC_Product ) {
                foreach ( $dimensions as $key => $value ) {
                    if ( '' === $value ) {
                        $getter             = "get_{$key}";
                        $dimensions[ $key ] = $parent->$getter();
                    }
                }
            }

            return array(
                'weight'     => $weight,
                'dimensions' => $dimensions,
            );
        }

        /**
         * Collects SEO metadata from popular plugins.
         *
         * @param int $product_id Product ID.
         *
         * @return array
         */
        protected function collect_seo_data( $product_id ) {
            $product_id  = (int) $product_id;
            $rank_title  = $product_id ? get_post_meta( $product_id, 'rank_math_title', true ) : '';
            $rank_desc   = $product_id ? get_post_meta( $product_id, 'rank_math_description', true ) : '';
            $yoast_title = $product_id ? get_post_meta( $product_id, '_yoast_wpseo_title', true ) : '';
            $yoast_desc  = $product_id ? get_post_meta( $product_id, '_yoast_wpseo_metadesc', true ) : '';

            $title = '' !== $rank_title ? $rank_title : $yoast_title;
            $desc  = '' !== $rank_desc ? $rank_desc : $yoast_desc;

            return array(
                'title'       => $this->decode_text( wp_strip_all_tags( $title ) ),
                'description' => $this->decode_text( wp_strip_all_tags( $desc ) ),
            );
        }

        /**
         * Determines brand information using taxonomies or custom attributes.
         *
         * @param WC_Product      $product Product instance.
         * @param WC_Product|null $parent  Optional parent product.
         *
         * @return array
         */
        protected function get_brand( WC_Product $product, $parent = null ) {
            $taxonomies = apply_filters( 'wse_brand_taxonomies', array( 'pa_brand', 'product_brand', 'brand' ) );

            foreach ( array_filter( array( $product, $parent ) ) as $candidate ) {
                foreach ( $taxonomies as $taxonomy ) {
                    if ( ! taxonomy_exists( $taxonomy ) ) {
                        continue;
                    }

                    $terms = get_the_terms( $candidate->get_id(), $taxonomy );

                    if ( empty( $terms ) || is_wp_error( $terms ) ) {
                        continue;
                    }

                    $term = $terms[0];

                    return array(
                        'id'       => (int) $term->term_id,
                        'name'     => $this->decode_text( $term->name ),
                        'slug'     => $term->slug,
                        'taxonomy' => $taxonomy,
                    );
                }
            }

            $attribute_brand = $this->brand_from_attributes( $product );

            if ( ! $attribute_brand && $parent instanceof WC_Product ) {
                $attribute_brand = $this->brand_from_attributes( $parent );
            }

            return $attribute_brand ? $attribute_brand : array();
        }

        /**
         * Attempts to read brand value from custom attributes.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array|null
         */
        protected function brand_from_attributes( WC_Product $product ) {
            $attributes = $product->get_attributes();

            foreach ( $attributes as $attribute ) {
                if ( ! $attribute instanceof WC_Product_Attribute ) {
                    continue;
                }

                if ( $attribute->is_taxonomy() ) {
                    continue;
                }

                $name = sanitize_title( $attribute->get_name() );

                if ( 'brand' !== $name ) {
                    continue;
                }

                $options = (array) $attribute->get_options();

                if ( empty( $options ) ) {
                    continue;
                }

                $value = $this->decode_text( reset( $options ) );

                return array(
                    'id'       => null,
                    'name'     => $value,
                    'slug'     => sanitize_title( $value ),
                    'taxonomy' => null,
                );
            }

            return null;
        }

        /**
         * Retrieves possible barcode meta values.
         *
         * @param WC_Product $product Product instance.
         *
         * @return string
         */
        protected function resolve_barcode( WC_Product $product ) {
            $meta_keys = apply_filters(
                'wse_barcode_meta_keys',
                array(
                    '_barcode',
                    '_bar_code',
                    '_product_barcode',
                    '_ean',
                    '_ean13',
                    '_ean_13',
                    '_upc',
                    '_gtin',
                    '_gln',
                    '_sku_barcode',
                    'barcode',
                    'ean',
                    'upc',
                    'gtin',
                )
            );

            foreach ( $meta_keys as $key ) {
                $value = $product->get_meta( $key, true );

                if ( ! empty( $value ) ) {
                    return (string) $value;
                }
            }

            return '';
        }

        /**
         * Decodes text ensuring UTF-8 (Arabic) support.
         *
         * @param string $text Raw text.
         *
         * @return string
         */
        protected function decode_text( $text ) {
            return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        /**
         * Provides cleaned HTML without inline styles or scripts.
         *
         * @param string $html Raw HTML content.
         *
         * @return string
         */
        protected function clean_description_html( $html ) {
            $html = $this->decode_text( $html );

            if ( '' === trim( $html ) ) {
                return '';
            }

            if ( class_exists( 'DOMDocument' ) ) {
                $document = new DOMDocument();
                $previous = libxml_use_internal_errors( true );
                $document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );

                $this->remove_dom_nodes( $document, array( 'script', 'style' ) );
                $this->strip_dom_attributes( $document );

                $wrapper = $document->getElementsByTagName( 'div' )->item( 0 );
                $output  = '';

                if ( $wrapper ) {
                    foreach ( $wrapper->childNodes as $child ) {
                        $output .= $document->saveHTML( $child );
                    }
                }

                return trim( $output );
            }

            $html = preg_replace( '#<script(.*?)>(.*?)</script>#is', '', $html );
            $html = preg_replace( '#<style(.*?)>(.*?)</style>#is', '', $html );
            $html = preg_replace( '/\sstyle=(\"|\')[^\"\']*\1/i', '', $html );
            $html = preg_replace( '/\son[a-z]+=(\"|\')[^\"\']*\1/i', '', $html );

            return trim( $html );
        }

        /**
         * Cleans a short description into plain text.
         *
         * @param string $text Raw text.
         *
         * @return string
         */
        protected function clean_short_description( $text ) {
            $text = wp_strip_all_tags( $this->decode_text( $text ) );
            $text = preg_replace( '/\s+/u', ' ', $text );

            return trim( $text );
        }

        /**
         * Normalizes product handles preserving UTF-8 characters.
         *
         * @param string $slug    Base slug.
         * @param string $fallback_name Product name fallback.
         *
         * @return string
         */
        protected function normalize_handle( $slug, $fallback_name ) {
            $handle = $this->decode_text( $slug );

            if ( '' === $handle ) {
                $handle = sanitize_title( $fallback_name );
            }

            $handle = strtolower( $handle );
            $handle = preg_replace( '/[\s_]+/u', '-', $handle );
            $handle = preg_replace( '/[^\p{L}\p{N}\-]+/u', '-', $handle );
            $handle = preg_replace( '/-+/u', '-', $handle );
            $handle = trim( $handle, '-' );

            if ( '' === $handle ) {
                $handle = sanitize_title( $fallback_name );
            }

            return $handle;
        }

        /**
         * Removes disallowed DOM nodes from a document.
         *
         * @param DOMDocument $document DOMDocument instance.
         * @param array       $tags     Tags to remove.
         */
        protected function remove_dom_nodes( DOMDocument $document, array $tags ) {
            $xpath = new DOMXPath( $document );

            foreach ( $tags as $tag ) {
                foreach ( $xpath->query( '//' . $tag ) as $node ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        /**
         * Strips inline style and event attributes from a DOMDocument.
         *
         * @param DOMDocument $document DOMDocument instance.
         */
        protected function strip_dom_attributes( DOMDocument $document ) {
            $xpath = new DOMXPath( $document );

            foreach ( $xpath->query( '//*' ) as $node ) {
                if ( ! $node->hasAttributes() ) {
                    continue;
                }

                $remove = array();

                foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
                    $name = strtolower( $attribute->name );

                    if ( 'style' === $name || 0 === strpos( $name, 'on' ) ) {
                        $remove[] = $name;
                    }
                }

                foreach ( $remove as $name ) {
                    $node->removeAttribute( $name );
                }
            }
        }

        /**
         * Retrieves the active locale with backwards compatibility.
         *
         * @return string
         */
        protected function current_locale() {
            if ( function_exists( 'determine_locale' ) ) {
                return determine_locale();
            }

            return get_locale();
        }

        /**
         * Converts term IDs into slugs for WC queries.
         *
         * @param array  $ids      Term IDs.
         * @param string $taxonomy Taxonomy slug.
         *
         * @return array
         */
        protected function term_slugs_from_ids( array $ids, $taxonomy ) {
            if ( empty( $ids ) || ! taxonomy_exists( $taxonomy ) ) {
                return array();
            }

            $terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'include'    => array_filter( array_map( 'intval', $ids ) ),
                    'fields'     => 'all',
                )
            );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                return array();
            }

            return array_values(
                array_map(
                    function ( $term ) {
                        return $term->slug;
                    },
                    $terms
                )
            );
        }
    }

    /**
     * Provides a reusable instance of the product source.
     *
     * @return WSE_WooCommerce_Product_Source
     */
    function wse_get_product_source() {
        static $instance = null;

        if ( null === $instance ) {
            $instance = new WSE_WooCommerce_Product_Source();
        }

        return $instance;
    }

    /**
     * Convenience wrapper for loading products.
     *
     * @param array $scope Scope definition.
     *
     * @return array
     */
    function wse_load_products( array $scope = array() ) {
        return wse_get_product_source()->loadProducts( $scope );
    }
}

