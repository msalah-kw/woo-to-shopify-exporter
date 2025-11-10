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
         * @return array
         */
        public function loadProducts( array $scope = array() ) {
            if ( ! class_exists( 'WC_Product_Query' ) ) {
                return array();
            }

            $args = $this->build_query_args( $scope );
            $query = new WC_Product_Query( $args );
            $results = $query->get_products();

            if ( empty( $results ) ) {
                return array();
            }

            $payload = array();

            foreach ( $results as $product ) {
                $product_object = $product instanceof WC_Product ? $product : wc_get_product( $product );

                if ( ! $product_object ) {
                    continue;
                }

                if ( $product_object->is_type( 'variation' ) ) {
                    $parent = $product_object->get_parent_id() ? wc_get_product( $product_object->get_parent_id() ) : null;
                    $payload[] = $this->format_variation( $product_object, $parent );
                } else {
                    $payload[] = $this->format_product( $product_object );
                }
            }

            return $payload;
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
         * Formats a top-level product payload.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array
         */
        protected function format_product( WC_Product $product ) {
            $data = $this->base_product_data( $product );

            if ( $product->is_type( 'variable' ) ) {
                $data['selectable_attributes'] = $this->get_selectable_attributes( $product );
                $data['variations']            = $this->get_variations( $product );
            } else {
                $data['selectable_attributes'] = array();
                $data['variations']            = array();
            }

            return $data;
        }

        /**
         * Formats a variation payload, ensuring selectable attributes only.
         *
         * @param WC_Product_Variation $variation Variation instance.
         * @param WC_Product|null      $parent    Parent product if available.
         *
         * @return array
         */
        protected function format_variation( WC_Product_Variation $variation, $parent = null ) {
            $data = $this->base_product_data( $variation, $parent );

            $data['type']       = 'variation';
            $data['parent_id']  = $variation->get_parent_id();
            $data['attributes'] = $this->get_variation_attributes( $variation, $parent );

            return $data;
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

            $brand  = $this->get_brand( $product, $parent );
            $images = $this->collect_images( $product, $parent );

            return array(
                'id'                => (int) $product->get_id(),
                'parent_id'         => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0,
                'type'              => $product->get_type(),
                'status'            => $product->get_status(),
                'name'              => $this->decode_text( $product->get_name() ),
                'slug'              => $product->get_slug(),
                'permalink'         => $product->get_permalink(),
                'description_html'  => $this->decode_text( $product->get_description() ),
                'short_description' => $this->decode_text( $product->get_short_description() ),
                'brand'             => $brand,
                'categories'        => $this->collect_terms( $source_product->get_id(), 'product_cat' ),
                'tags'              => $this->collect_terms( $source_product->get_id(), 'product_tag' ),
                'images'            => $images,
                'pricing'           => $this->collect_pricing_data( $product ),
                'taxes'             => $this->collect_tax_data( $product ),
                'inventory'         => $this->collect_inventory_data( $product, $parent ),
                'shipping'          => $this->collect_shipping_data( $product, $parent ),
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
        protected function get_variations( WC_Product_Variable $product ) {
            $variations = array();

            foreach ( $product->get_children() as $child_id ) {
                $variation = wc_get_product( $child_id );

                if ( ! $variation instanceof WC_Product_Variation ) {
                    continue;
                }

                $variations[] = $this->format_variation( $variation, $product );
            }

            return $variations;
        }

        /**
         * Returns selectable attribute definitions for a variable product.
         *
         * @param WC_Product_Variable $product Variable product.
         *
         * @return array
         */
        protected function get_selectable_attributes( WC_Product_Variable $product ) {
            $attributes = array();

            foreach ( $product->get_attributes() as $attribute ) {
                if ( ! $attribute->get_variation() ) {
                    continue;
                }

                $options = array();

                if ( $attribute->is_taxonomy() ) {
                    $terms = wc_get_product_terms(
                        $product->get_id(),
                        $attribute->get_name(),
                        array(
                            'fields' => 'all',
                        )
                    );

                    if ( ! is_wp_error( $terms ) ) {
                        foreach ( $terms as $term ) {
                            $options[] = array(
                                'id'   => (int) $term->term_id,
                                'name' => $this->decode_text( $term->name ),
                                'slug' => $term->slug,
                            );
                        }
                    }
                } else {
                    foreach ( (array) $attribute->get_options() as $option ) {
                        if ( '' === $option ) {
                            continue;
                        }

                        $label = $this->decode_text( $option );

                        $options[] = array(
                            'id'   => null,
                            'name' => $label,
                            'slug' => sanitize_title( $label ),
                        );
                    }
                }

                $attributes[] = array(
                    'name'    => $this->decode_text( wc_attribute_label( $attribute->get_name() ) ),
                    'slug'    => $attribute->get_name(),
                    'options' => $options,
                );
            }

            return $attributes;
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

            return array(
                'sku'            => $product->get_sku(),
                'barcode'        => $barcode,
                'manage_stock'   => $product->get_manage_stock(),
                'stock_status'   => $product->get_stock_status(),
                'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
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

