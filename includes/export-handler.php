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

        $job['status']       = 'running';
        $job['message']      = __( 'Export in progress…', 'woo-to-shopify-exporter' );
        $job['last_updated'] = time();
        $job['progress']     = 10;

        wse_store_active_job( $job );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $result = wse_generate_shopify_csv( $job['settings'] );

        if ( is_wp_error( $result ) ) {
            $job['status']       = 'failed';
            $job['progress']     = 100;
            $job['message']      = $result->get_error_message();
            $job['last_updated'] = time();

            wse_store_active_job( $job );

            return $result;
        }

        $file_count = ! empty( $result['files'] ) ? count( $result['files'] ) : 0;
        $products   = isset( $result['products'] ) ? (int) $result['products'] : 0;

        $job['status']        = 'completed';
        $job['progress']      = 100;
        $job['last_updated']  = time();
        $job['download_url']  = ! empty( $result['files'][0]['url'] ) ? $result['files'][0]['url'] : '';
        $job['files']         = $result['files'];
        $job['row_count']     = isset( $result['rows'] ) ? (int) $result['rows'] : 0;
        $job['product_count'] = $products;
        $job['message']       = sprintf(
            _n(
                'Export completed with %1$d product across %2$d file.',
                'Export completed with %1$d products across %2$d files.',
                $products,
                'woo-to-shopify-exporter'
            ),
            $products,
            max( 1, $file_count )
        );

        wse_store_active_job( $job );

        return $job;
    }
}
