<?php
/**
 * Export job execution helpers.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wse_get_export_directory_info' ) ) {

    /**
     * Determines the filesystem path and URL for export files.
     *
     * @return array|WP_Error
     */
    function wse_get_export_directory_info() {
        $uploads = wp_upload_dir();

        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'wse_upload_dir_error', $uploads['error'] );
        }

        $directory = trailingslashit( $uploads['basedir'] ) . 'woo-to-shopify-exporter';
        $url       = trailingslashit( $uploads['baseurl'] ) . 'woo-to-shopify-exporter';

        if ( ! wp_mkdir_p( $directory ) ) {
            return new WP_Error( 'wse_directory_unwritable', __( 'Unable to create export directory.', 'woo-to-shopify-exporter' ) );
        }

        return array(
            'path' => $directory,
            'url'  => $url,
        );
    }
}

if ( ! function_exists( 'wse_build_product_scope_from_settings' ) ) {

    /**
     * Builds a product query scope based on saved export settings.
     *
     * @param array $settings Export settings.
     *
     * @return array
     */
    function wse_build_product_scope_from_settings( array $settings ) {
        $scope = array(
            'type'       => 'all',
            'categories' => array(),
            'tags'       => array(),
            'ids'        => array(),
            'status'     => array( 'publish' ),
            'limit'      => -1,
            'page'       => 1,
        );

        if ( ! empty( $settings['scope_status'] ) && is_array( $settings['scope_status'] ) ) {
            $scope['status'] = array_map( 'sanitize_key', array_filter( $settings['scope_status'] ) );
        }

        $scope_type = isset( $settings['export_scope'] ) ? sanitize_key( $settings['export_scope'] ) : 'all';

        switch ( $scope_type ) {
            case 'category':
                if ( ! empty( $settings['scope_categories'] ) && is_array( $settings['scope_categories'] ) ) {
                    $scope['type']       = 'category';
                    $scope['categories'] = array_map( 'absint', $settings['scope_categories'] );
                }
                break;
            case 'tag':
                if ( ! empty( $settings['scope_tags'] ) && is_array( $settings['scope_tags'] ) ) {
                    $scope['type'] = 'tag';
                    $scope['tags'] = array_map( 'absint', $settings['scope_tags'] );
                }
                break;
            case 'status':
                $scope['type'] = 'all';
                break;
            case 'ids':
                if ( ! empty( $settings['scope_ids'] ) && is_array( $settings['scope_ids'] ) ) {
                    $scope['type'] = 'ids';
                    $scope['ids']  = array_map( 'absint', $settings['scope_ids'] );
                }
                break;
            case 'all':
            default:
                $scope['type'] = 'all';
                break;
        }

        return apply_filters( 'wse_export_product_scope', $scope, $settings );
    }
}

if ( ! function_exists( 'wse_generate_shopify_csv' ) ) {

    /**
     * Generates Shopify compatible CSV files for the provided settings.
     *
     * @param array $settings Export settings.
     * @param array $overrides Optional overrides for batch size and file size.
     *
     * @return array|WP_Error
     */
    function wse_generate_shopify_csv( array $settings = array(), array $overrides = array() ) {
        $defaults = function_exists( 'wse_get_default_export_settings' ) ? wse_get_default_export_settings() : array();
        $settings = wp_parse_args( $settings, $defaults );

        $format = isset( $settings['file_format'] ) ? sanitize_key( $settings['file_format'] ) : 'csv';

        if ( ! in_array( $format, array( 'csv', 'tsv' ), true ) ) {
            return new WP_Error( 'wse_unsupported_format', __( 'Only CSV and TSV exports are supported at this time.', 'woo-to-shopify-exporter' ) );
        }

        $directory = wse_get_export_directory_info();
        if ( is_wp_error( $directory ) ) {
            return $directory;
        }

        $delimiter = ',';
        if ( 'tsv' === $format ) {
            $delimiter = "\t";
        } elseif ( ! empty( $settings['delimiter'] ) ) {
            $delimiter = (string) $settings['delimiter'];
        }

        if ( '' === $delimiter ) {
            $delimiter = ',';
        }

        $writer_args = array(
            'delimiter'         => $delimiter,
            'output_dir'        => $directory['path'],
            'base_url'          => $directory['url'],
            'file_name'         => isset( $settings['file_name'] ) ? $settings['file_name'] : 'shopify-products.csv',
            'include_variants'  => ! empty( $settings['include_variations'] ),
            'include_images'    => ! empty( $settings['include_images'] ),
            'include_inventory' => ! empty( $settings['include_inventory'] ),
        );

        if ( isset( $overrides['batch_size'] ) ) {
            $writer_args['batch_size'] = (int) $overrides['batch_size'];
        }

        if ( isset( $overrides['max_file_size'] ) ) {
            $writer_args['max_file_size'] = (int) $overrides['max_file_size'];
        }

        $writer = new WSE_Shopify_CSV_Writer( $writer_args );

        $error = $writer->get_last_error();
        if ( $error instanceof WP_Error ) {
            return $error;
        }

        $scope      = wse_build_product_scope_from_settings( $settings );
        $batch_size = $writer->get_batch_size();
        $page       = 1;
        $processed  = 0;

        do {
            $query_scope = $scope;
            $query_scope['limit'] = $batch_size;
            $query_scope['page']  = $page;

            $result = wse_load_products( $query_scope );

            if ( empty( $result['items'] ) ) {
                break;
            }

            foreach ( $result['items'] as $package ) {
                $processed++;

                if ( ! $writer->write_product_package( $package ) ) {
                    $writer->finish();
                    $error = $writer->get_last_error();
                    if ( ! $error instanceof WP_Error ) {
                        $error = new WP_Error( 'wse_csv_write_failed', __( 'Failed to write the Shopify export file.', 'woo-to-shopify-exporter' ) );
                    }
                    return $error;
                }
            }

            $page++;
        } while ( count( $result['items'] ) >= $batch_size );

        if ( ! $writer->finish() ) {
            $error = $writer->get_last_error();
            if ( ! $error instanceof WP_Error ) {
                $error = new WP_Error( 'wse_csv_finalize_failed', __( 'Failed to finalize the Shopify export file.', 'woo-to-shopify-exporter' ) );
            }

            return $error;
        }

        $files = $writer->get_files();

        return array(
            'files'    => $files,
            'rows'     => $writer->get_total_rows_written(),
            'products' => $processed,
        );
    }
}

if ( ! function_exists( 'wse_normalize_shopify_handle' ) ) {

    /**
     * Normalizes handles to match Shopify expectations (UTF-8 aware).
     *
     * @param string $slug          Base slug value.
     * @param string $fallback_name Name fallback if slug is empty.
     *
     * @return string
     */
    function wse_normalize_shopify_handle( $slug, $fallback_name ) {
        $handle = html_entity_decode( (string) $slug, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

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
}

if ( ! function_exists( 'wse_normalize_manifest_value' ) ) {

    /**
     * Normalizes scalar values before writing to auxiliary CSV manifests.
     *
     * @param mixed $value Value to normalize.
     *
     * @return string
     */
    function wse_normalize_manifest_value( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if ( is_array( $value ) ) {
            $value = implode( ', ', array_map( 'strval', $value ) );
        }

        if ( null === $value ) {
            $value = '';
        }

        $value = (string) $value;
        $value = wp_check_invalid_utf8( $value, true );
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );
        $value = str_replace( array( '“', '”', '„', '‟', '«', '»' ), chr( 34 ), $value );

        return $value;
    }
}

if ( ! function_exists( 'wse_write_manifest_file' ) ) {

    /**
     * Writes an auxiliary CSV manifest to disk.
     *
     * @param string $path      Destination path.
     * @param array  $columns   Header columns.
     * @param array  $rows      Data rows.
     * @param string $delimiter CSV delimiter.
     *
     * @return array|WP_Error { rows => int, size => int }
     */
    function wse_write_manifest_file( $path, array $columns, array $rows, $delimiter = ',' ) {
        $directory = dirname( $path );

        if ( ! wp_mkdir_p( $directory ) ) {
            return new WP_Error( 'wse_manifest_directory_unwritable', __( 'Unable to create the export manifest directory.', 'woo-to-shopify-exporter' ) );
        }

        $handle = fopen( $path, 'wb' );

        if ( ! $handle ) {
            return new WP_Error( 'wse_manifest_open_failed', __( 'Unable to open the export manifest file for writing.', 'woo-to-shopify-exporter' ) );
        }

        $delimiter = '' !== $delimiter ? $delimiter : ',';

        if ( false === fputcsv( $handle, $columns, $delimiter ) ) {
            fclose( $handle );
            return new WP_Error( 'wse_manifest_header_failed', __( 'Unable to write the export manifest header.', 'woo-to-shopify-exporter' ) );
        }

        $row_count = 0;

        foreach ( $rows as $row ) {
            $line = array();

            foreach ( $columns as $column ) {
                $line[] = wse_normalize_manifest_value( isset( $row[ $column ] ) ? $row[ $column ] : '' );
            }

            if ( false === fputcsv( $handle, $line, $delimiter ) ) {
                fclose( $handle );
                return new WP_Error( 'wse_manifest_row_failed', __( 'Unable to write a row to the export manifest.', 'woo-to-shopify-exporter' ) );
            }

            $row_count++;
        }

        fclose( $handle );

        $size = file_exists( $path ) ? filesize( $path ) : 0;

        return array(
            'rows' => $row_count,
            'size' => (int) $size,
        );
    }
}

if ( ! function_exists( 'wse_collect_export_product_ids' ) ) {

    /**
     * Collects WooCommerce product IDs for a given export scope.
     *
     * @param array $scope Scope definition used during the export.
     * @param int   $limit Query batch size.
     *
     * @return array
     */
    function wse_collect_export_product_ids( array $scope, $limit = 200 ) {
        if ( ! class_exists( 'WSE_WooCommerce_Product_Source' ) || ! class_exists( 'WC_Product_Query' ) ) {
            return array();
        }

        $source = new WSE_WooCommerce_Product_Source();
        $ids    = array();
        $page   = max( 1, isset( $scope['page'] ) ? (int) $scope['page'] : 1 );
        $limit  = max( 1, (int) $limit );

        do {
            $query_scope          = $scope;
            $query_scope['limit'] = $limit;
            $query_scope['page']  = $page;

            $args = $source->get_query_args( $query_scope, 'ids' );
            $query = new WC_Product_Query( $args );
            $batch = $query->get_products();

            if ( empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $product_id ) {
                $product_id = (int) $product_id;

                if ( $product_id > 0 ) {
                    $ids[ $product_id ] = $product_id;
                }
            }

            $page++;
        } while ( count( $batch ) >= $limit );

        return array_values( $ids );
    }
}

if ( ! function_exists( 'wse_generate_collections_manifest' ) ) {

    /**
     * Generates collections.csv from WooCommerce product categories.
     *
     * @param array $scope      Export scope definition.
     * @param array $directory  Export directory information (path, url).
     * @param string $delimiter CSV delimiter requested for the job.
     *
     * @return array|WP_Error
     */
    function wse_generate_collections_manifest( array $scope, array $directory, $delimiter = ',' ) {
        if ( empty( $directory['path'] ) || ! taxonomy_exists( 'product_cat' ) ) {
            return array();
        }

        $product_ids = wse_collect_export_product_ids( $scope );

        if ( empty( $product_ids ) ) {
            return array();
        }

        $term_ids = array();

        foreach ( $product_ids as $product_id ) {
            if ( 'product' !== get_post_type( $product_id ) ) {
                continue;
            }

            $terms = get_the_terms( $product_id, 'product_cat' );

            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                $term_ids[ $term->term_id ] = $term->term_id;

                foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $ancestor_id ) {
                    $term_ids[ $ancestor_id ] = $ancestor_id;
                }
            }
        }

        if ( empty( $term_ids ) ) {
            return array();
        }

        $terms = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'include'    => array_values( $term_ids ),
                'hide_empty' => false,
            )
        );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        $handles = array();

        foreach ( $terms as $term ) {
            $handles[ $term->term_id ] = wse_normalize_shopify_handle( $term->slug, $term->name );
        }

        $rows = array();

        foreach ( $terms as $term ) {
            $handle       = $handles[ $term->term_id ];
            $parent       = '';
            $description  = html_entity_decode( (string) $term->description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $description  = wp_kses_post( $description );
            $title        = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            if ( $term->parent && isset( $handles[ $term->parent ] ) ) {
                $parent = $handles[ $term->parent ];
            }

            $rows[] = array(
                'Handle'          => $handle,
                'Title'           => $title,
                'Body (HTML)'     => $description,
                'Collection Type' => 'manual',
                'Published'       => 'TRUE',
                'Parent Handle'   => $parent,
            );
        }

        if ( empty( $rows ) ) {
            return array();
        }

        usort(
            $rows,
            function ( $a, $b ) {
                return strcasecmp( $a['Title'], $b['Title'] );
            }
        );

        $columns   = array( 'Handle', 'Title', 'Body (HTML)', 'Collection Type', 'Published', 'Parent Handle' );
        $filename  = 'collections.csv';
        $path      = trailingslashit( $directory['path'] ) . $filename;
        $url       = ! empty( $directory['url'] ) ? trailingslashit( $directory['url'] ) . $filename : '';
        $delimiter = ( '\t' === $delimiter || '' === $delimiter ) ? ',' : $delimiter;

        $written = wse_write_manifest_file( $path, $columns, $rows, $delimiter );

        if ( is_wp_error( $written ) ) {
            return $written;
        }

        return array(
            'path'     => $path,
            'url'      => $url,
            'filename' => $filename,
            'rows'     => isset( $written['rows'] ) ? (int) $written['rows'] : count( $rows ),
            'size'     => isset( $written['size'] ) ? (int) $written['size'] : ( file_exists( $path ) ? filesize( $path ) : 0 ),
        );
    }
}

if ( ! function_exists( 'wse_build_redirect_from_path' ) ) {

    /**
     * Builds a legacy WooCommerce path for use as a Shopify redirect source.
     *
     * @param string $permalink Current WooCommerce permalink.
     * @param string $old_slug  Historical slug value.
     *
     * @return string
     */
    function wse_build_redirect_from_path( $permalink, $old_slug ) {
        if ( '' === $old_slug ) {
            return '';
        }

        $parsed = wp_parse_url( $permalink );

        if ( empty( $parsed ) ) {
            return '';
        }

        $path = isset( $parsed['path'] ) ? $parsed['path'] : '';
        $query = isset( $parsed['query'] ) ? $parsed['query'] : '';

        if ( '' !== $path ) {
            $has_trailing = '/' === substr( $path, -1 );
            $segments     = array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' );

            if ( empty( $segments ) ) {
                return '';
            }

            $segments               = array_map( 'rawurldecode', $segments );
            $segments[ count( $segments ) - 1 ] = $old_slug;
            $new_path               = '/' . implode( '/', array_map( 'rawurlencode', $segments ) );

            if ( $has_trailing ) {
                $new_path = trailingslashit( $new_path );
            }

            return $new_path;
        }

        if ( '' !== $query ) {
            parse_str( $query, $params );

            if ( isset( $params['product'] ) ) {
                $params['product'] = $old_slug;

                return '/?' . http_build_query( $params );
            }
        }

        return '';
    }
}

if ( ! function_exists( 'wse_generate_redirects_manifest' ) ) {

    /**
     * Generates redirects.csv mapping legacy slugs to Shopify handles.
     *
     * @param array $scope      Export scope definition.
     * @param array $directory  Export directory information (path, url).
     * @param string $delimiter CSV delimiter requested for the job.
     *
     * @return array|WP_Error
     */
    function wse_generate_redirects_manifest( array $scope, array $directory, $delimiter = ',' ) {
        if ( empty( $directory['path'] ) ) {
            return array();
        }

        $product_ids = wse_collect_export_product_ids( $scope );

        if ( empty( $product_ids ) ) {
            return array();
        }

        $rows = array();

        foreach ( $product_ids as $product_id ) {
            if ( 'product' !== get_post_type( $product_id ) ) {
                continue;
            }

            $old_slugs = get_post_meta( $product_id, '_wp_old_slug' );

            if ( empty( $old_slugs ) ) {
                continue;
            }

            $handle = wse_normalize_shopify_handle( get_post_field( 'post_name', $product_id ), get_the_title( $product_id ) );

            if ( '' === $handle ) {
                continue;
            }

            $permalink = get_permalink( $product_id );

            $old_slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $old_slugs ) ) ) );

            foreach ( $old_slugs as $old_slug ) {
                if ( '' === $old_slug || $old_slug === $handle ) {
                    continue;
                }

                $from = wse_build_redirect_from_path( $permalink, $old_slug );

                if ( '' === $from ) {
                    continue;
                }

                $rows[ $from ] = array(
                    'Redirect from' => $from,
                    'Redirect to'   => '/products/' . $handle,
                );
            }
        }

        if ( empty( $rows ) ) {
            return array();
        }

        ksort( $rows );
        $rows = array_values( $rows );

        $columns   = array( 'Redirect from', 'Redirect to' );
        $filename  = 'redirects.csv';
        $path      = trailingslashit( $directory['path'] ) . $filename;
        $url       = ! empty( $directory['url'] ) ? trailingslashit( $directory['url'] ) . $filename : '';
        $delimiter = ( '\t' === $delimiter || '' === $delimiter ) ? ',' : $delimiter;

        $written = wse_write_manifest_file( $path, $columns, $rows, $delimiter );

        if ( is_wp_error( $written ) ) {
            return $written;
        }

        return array(
            'path'     => $path,
            'url'      => $url,
            'filename' => $filename,
            'rows'     => isset( $written['rows'] ) ? (int) $written['rows'] : count( $rows ),
            'size'     => isset( $written['size'] ) ? (int) $written['size'] : ( file_exists( $path ) ? filesize( $path ) : 0 ),
        );
    }
}

if ( ! function_exists( 'wse_run_export_job' ) ) {

    /**
     * Executes an export job synchronously.
     *
     * @param array $job Job payload.
     *
     * @return array|WP_Error
     */
    function wse_run_export_job( array $job ) {
        if ( empty( $job['settings'] ) || ! is_array( $job['settings'] ) ) {
            return new WP_Error( 'wse_missing_settings', __( 'Export settings are required to run this job.', 'woo-to-shopify-exporter' ) );
        }

        if ( ! isset( $job['id'] ) ) {
            $job['id'] = uniqid( 'wse_job_', true );
        }

        $orchestrator_args = array();

        if ( isset( $job['watchdog_time'] ) ) {
            $orchestrator_args['watchdog_time'] = (int) $job['watchdog_time'];
        }

        if ( isset( $job['watchdog_memory'] ) ) {
            $orchestrator_args['watchdog_memory'] = (float) $job['watchdog_memory'];
        }

        if ( ! empty( $job['overrides'] ) && is_array( $job['overrides'] ) ) {
            $writer_overrides = array();

            if ( isset( $job['overrides']['batch_size'] ) ) {
                $writer_overrides['batch_size'] = (int) $job['overrides']['batch_size'];
            }

            if ( isset( $job['overrides']['max_file_size'] ) ) {
                $writer_overrides['max_file_size'] = (int) $job['overrides']['max_file_size'];
            }

            $string_keys = array( 'output_dir', 'base_url', 'file_name', 'delimiter', 'enclosure', 'escape_char' );

            foreach ( $string_keys as $key ) {
                if ( isset( $job['overrides'][ $key ] ) ) {
                    $writer_overrides[ $key ] = (string) $job['overrides'][ $key ];
                }
            }

            $boolean_keys = array( 'include_variants', 'include_images', 'include_inventory' );

            foreach ( $boolean_keys as $key ) {
                if ( isset( $job['overrides'][ $key ] ) ) {
                    $writer_overrides[ $key ] = (bool) $job['overrides'][ $key ];
                }
            }

            if ( isset( $writer_overrides['output_dir'] ) ) {
                $writer_overrides['output_dir'] = wp_normalize_path( $writer_overrides['output_dir'] );
            }

            if ( isset( $job['overrides']['columns'] ) && is_array( $job['overrides']['columns'] ) ) {
                $writer_overrides['columns'] = array_values( $job['overrides']['columns'] );
            }

            if ( ! empty( $writer_overrides ) ) {
                $orchestrator_args['writer'] = $writer_overrides;
            }
        }

        $orchestrator = new WSE_Export_Orchestrator( $job, $orchestrator_args );
        $result       = $orchestrator->run();

        if ( is_wp_error( $result ) ) {
            $job['status']       = 'failed';
            $job['progress']     = 100;
            $job['message']      = $result->get_error_message();
            $job['last_updated'] = time();

            if ( function_exists( 'wse_store_active_job' ) ) {
                wse_store_active_job( $job );
            }

            return $result;
        }

        if ( function_exists( 'wse_store_active_job' ) ) {
            wse_store_active_job( $result );
        }

        return $result;
    }
}
