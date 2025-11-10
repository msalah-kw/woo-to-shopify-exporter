<?php
/**
 * WP-CLI commands for the Woo to Shopify exporter.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'WSE_Woo2Shopify_CLI_Command' ) ) {

    /**
     * Provides `wp woo2shopify` commands for exporting and validating data.
     */
    class WSE_Woo2Shopify_CLI_Command {

        /**
         * Triggers a Shopify export using the orchestrator.
         *
         * ## OPTIONS
         *
         * [--scope=<scope>]
         * : Product scope. Supports `all`, `category:<ids>`, `tag:<ids>`, or `ids:<ids>`.
         *
         * [--lang=<locale>]
         * : Locale to switch to during the export (for example, `ar`).
         *
         * [--out=<path>]
         * : Destination file path for the primary Shopify CSV.
         *
         * [--batch=<size>]
         * : Batch size to use when querying WooCommerce products.
         *
         * [--images=<mode>]
         * : Image handling strategy. Supports `urls`, `copy`, or `none`.
         *
         * [--collections]
         * : Generate the optional `collections.csv` manifest.
         *
         * [--redirects]
         * : Generate the optional `redirects.csv` manifest.
         *
         * ## EXAMPLES
         *
         *     wp woo2shopify export --scope=all --lang=ar --out=/tmp/shopify.csv --batch=800 --images=copy --collections
         *
         * @param array $args       Positional arguments.
         * @param array $assoc_args Associative arguments.
         *
         * @return void
         */
        public function export( $args, $assoc_args ) {
            if ( ! function_exists( 'wse_run_export_job' ) ) {
                WP_CLI::error( 'Exporter is not available.' );
            }

            $defaults = function_exists( 'wse_get_default_export_settings' ) ? wse_get_default_export_settings() : array();
            $settings = $defaults;

            if ( ! isset( $settings['scope_categories'] ) ) {
                $settings['scope_categories'] = array();
            }

            if ( ! isset( $settings['scope_tags'] ) ) {
                $settings['scope_tags'] = array();
            }

            if ( ! isset( $settings['scope_ids'] ) ) {
                $settings['scope_ids'] = array();
            }

            $scope = isset( $assoc_args['scope'] ) ? (string) $assoc_args['scope'] : 'all';
            $this->apply_scope_to_settings( $scope, $settings );

            $images_mode = isset( $assoc_args['images'] ) ? strtolower( (string) $assoc_args['images'] ) : 'urls';
            switch ( $images_mode ) {
                case 'copy':
                    $settings['include_images'] = true;
                    $settings['copy_images']    = true;
                    break;
                case 'none':
                    $settings['include_images'] = false;
                    $settings['copy_images']    = false;
                    break;
                case 'urls':
                case 'url':
                case 'default':
                case '':
                    $settings['include_images'] = true;
                    $settings['copy_images']    = false;
                    break;
                default:
                    WP_CLI::error( sprintf( 'Unsupported images mode "%s".', $images_mode ) );
            }

            $settings['include_collections'] = isset( $assoc_args['collections'] );
            $settings['include_redirects']   = isset( $assoc_args['redirects'] );

            $overrides = array();

            if ( isset( $assoc_args['batch'] ) ) {
                $batch_size = (int) $assoc_args['batch'];
                if ( $batch_size < 1 ) {
                    WP_CLI::error( 'Batch size must be greater than zero.' );
                }

                $overrides['batch_size'] = $batch_size;
                $settings['batch_size']  = $batch_size;
            }

            if ( isset( $assoc_args['out'] ) && '' !== trim( (string) $assoc_args['out'] ) ) {
                $normalized = $this->normalize_path( (string) $assoc_args['out'] );
                $filename   = basename( $normalized );

                if ( '' === $filename || '.' === $filename ) {
                    WP_CLI::error( 'Output path must include a file name.' );
                }

                $directory = dirname( $normalized );
                if ( '.' === $directory || '' === $directory ) {
                    $directory = $this->normalize_path( '.' );
                }

                if ( ! wp_mkdir_p( $directory ) ) {
                    WP_CLI::error( sprintf( 'Unable to create export directory at %s.', $directory ) );
                }

                $overrides['output_dir'] = $directory;
                $overrides['base_url']   = '';
                $overrides['file_name']  = $filename;
                $settings['file_name']   = $filename;
            }

            $locale   = isset( $assoc_args['lang'] ) ? sanitize_text_field( $assoc_args['lang'] ) : '';
            $switched = false;

            if ( $locale && function_exists( 'switch_to_locale' ) ) {
                $switched       = switch_to_locale( $locale );

                if ( $switched ) {
                    WP_CLI::log( sprintf( 'Switched to locale %s.', $locale ) );
                } else {
                    WP_CLI::warning( sprintf( 'Unable to switch to locale %s. Continuing with default locale.', $locale ) );
                }
            }

            $job = array(
                'id'             => uniqid( 'wse_cli_', true ),
                'settings'       => $settings,
                'overrides'      => $overrides,
                'watchdog_time'  => 0,
                'watchdog_memory'=> 0,
            );

            WP_CLI::log( 'Starting export…' );

            try {
                $result = wse_run_export_job( $job );
            } finally {
                if ( $switched && function_exists( 'restore_previous_locale' ) ) {
                    restore_previous_locale();
                }
            }

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result );
            }

            $this->output_result_summary( $result );
        }

        /**
         * Resumes a previously paused export job.
         *
         * ## OPTIONS
         *
         * --job=<id>
         * : Identifier of the export job to resume.
         *
         * @param array $args       Positional arguments.
         * @param array $assoc_args Associative arguments.
         *
         * @return void
         */
        public function resume( $args, $assoc_args ) {
            if ( ! function_exists( 'wse_run_export_job' ) ) {
                WP_CLI::error( 'Exporter is not available.' );
            }

            if ( empty( $assoc_args['job'] ) ) {
                WP_CLI::error( 'You must provide a job identifier via --job.' );
            }

            $job_id = sanitize_text_field( $assoc_args['job'] );

            $record = function_exists( 'wse_get_export_job_record' ) ? wse_get_export_job_record( $job_id ) : array();
            if ( empty( $record ) ) {
                WP_CLI::error( sprintf( 'No export job found for id %s.', $job_id ) );
            }

            $job = array(
                'id'             => $job_id,
                'watchdog_time'  => 0,
                'watchdog_memory'=> 0,
            );

            if ( ! empty( $record['settings'] ) && is_array( $record['settings'] ) ) {
                $job['settings'] = $record['settings'];
            }

            $overrides = array();
            $state     = isset( $record['writer_state'] ) && is_array( $record['writer_state'] ) ? $record['writer_state'] : array();
            $state_path = '';

            if ( ! empty( $state['current_path'] ) ) {
                $state_path = $state['current_path'];
            }

            if ( ! $state_path && ! empty( $record['files'] ) && is_array( $record['files'] ) ) {
                $first_file = reset( $record['files'] );
                if ( isset( $first_file['path'] ) && $first_file['path'] ) {
                    $state_path = $first_file['path'];
                }
            }

            if ( $state_path ) {
                $directory = wp_normalize_path( dirname( $state_path ) );

                if ( '.' !== $directory && '' !== $directory ) {
                    $overrides['output_dir'] = $directory;
                }

                if ( empty( $job['settings']['file_name'] ) ) {
                    $job['settings']['file_name'] = basename( $state_path );
                }
            }

            if ( ! empty( $record['files'] ) && is_array( $record['files'] ) ) {
                $first_file = reset( $record['files'] );

                if ( isset( $first_file['url'] ) && $first_file['url'] ) {
                    $overrides['base_url'] = untrailingslashit( dirname( $first_file['url'] ) );
                }
            }

            if ( ! empty( $overrides ) ) {
                $job['overrides'] = $overrides;
            }

            WP_CLI::log( sprintf( 'Resuming export job %s…', $job_id ) );

            $result = wse_run_export_job( $job );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result );
            }

            $this->output_result_summary( $result );
        }

        /**
         * Validates a generated Shopify CSV file.
         *
         * ## OPTIONS
         *
         * --file=<path>
         * : Path to the CSV file that should be validated.
         *
         * @param array $args       Positional arguments.
         * @param array $assoc_args Associative arguments.
         *
         * @return void
         */
        public function validate( $args, $assoc_args ) {
            if ( empty( $assoc_args['file'] ) ) {
                WP_CLI::error( 'You must provide a file path via --file.' );
            }

            $path = $this->normalize_path( $assoc_args['file'] );

            if ( ! file_exists( $path ) ) {
                WP_CLI::error( sprintf( 'File not found at %s.', $path ) );
            }

            if ( ! is_readable( $path ) ) {
                WP_CLI::error( sprintf( 'File is not readable at %s.', $path ) );
            }

            $handle = fopen( $path, 'rb' );

            if ( ! $handle ) {
                WP_CLI::error( sprintf( 'Unable to open %s for reading.', $path ) );
            }

            $first_line = fgets( $handle );
            fclose( $handle );

            if ( false === $first_line ) {
                WP_CLI::error( 'The CSV file appears to be empty.' );
            }

            $first_line = $this->strip_bom( rtrim( $first_line, "\r\n" ) );

            $delimiters = array( ',', "\t", ';' );
            $header     = array();

            foreach ( $delimiters as $delimiter ) {
                $parsed = str_getcsv( $first_line, $delimiter );
                if ( count( $parsed ) >= 5 ) {
                    $header = array_map( 'trim', $parsed );
                    break;
                }
            }

            if ( empty( $header ) ) {
                WP_CLI::error( 'Unable to parse CSV header row.' );
            }

            $required = class_exists( 'WSE_Shopify_CSV_Writer' ) ? WSE_Shopify_CSV_Writer::get_default_columns() : array();

            if ( empty( $required ) ) {
                WP_CLI::error( 'Unable to determine expected Shopify columns.' );
            }

            $missing = array();
            foreach ( $required as $column ) {
                if ( ! in_array( $column, $header, true ) ) {
                    $missing[] = $column;
                }
            }

            if ( ! empty( $missing ) ) {
                WP_CLI::error( sprintf( 'Missing required columns: %s', implode( ', ', $missing ) ) );
            }

            WP_CLI::success( sprintf( 'CSV header is valid. %d columns detected.', count( $header ) ) );
        }

        /**
         * Outputs a summary of the export result.
         *
         * @param array $result Job result payload.
         *
         * @return void
         */
        protected function output_result_summary( array $result ) {
            $status  = isset( $result['status'] ) ? $result['status'] : 'completed';
            $message = isset( $result['message'] ) ? $result['message'] : '';
            $files   = isset( $result['files'] ) && is_array( $result['files'] ) ? $result['files'] : array();

            if ( $message ) {
                WP_CLI::log( $message );
            }

            if ( ! empty( $files ) ) {
                foreach ( $files as $index => $file ) {
                    $path = isset( $file['path'] ) ? $file['path'] : '';
                    $url  = isset( $file['url'] ) ? $file['url'] : '';
                    $name = isset( $file['filename'] ) ? $file['filename'] : $path;

                    if ( $path ) {
                        WP_CLI::log( sprintf( 'File %d: %s', $index, $path ) );
                    } elseif ( $name ) {
                        WP_CLI::log( sprintf( 'File %d: %s', $index, $name ) );
                    }

                    if ( $url ) {
                        WP_CLI::log( sprintf( '  URL: %s', $url ) );
                    }
                }
            }

            $products = isset( $result['product_count'] ) ? (int) $result['product_count'] : 0;
            $rows     = isset( $result['row_count'] ) ? (int) $result['row_count'] : 0;
            $files_no = count( $files );

            if ( 'completed' === $status ) {
                WP_CLI::success( sprintf( 'Export completed with %d products, %d rows, and %d file(s).', $products, $rows, max( 1, $files_no ) ) );
            } elseif ( 'paused' === $status ) {
                WP_CLI::warning( sprintf( 'Export paused after %d products and %d rows. Re-run resume to continue.', $products, $rows ) );
            } else {
                WP_CLI::log( sprintf( 'Export finished with status %s.', $status ) );
            }
        }

        /**
         * Applies the requested scope string to the settings array.
         *
         * @param string $scope    Scope string.
         * @param array  $settings Settings to mutate.
         *
         * @return void
         */
        protected function apply_scope_to_settings( $scope, array &$settings ) {
            $scope = trim( strtolower( $scope ) );

            if ( '' === $scope || 'all' === $scope ) {
                $settings['export_scope']     = 'all';
                $settings['scope_categories'] = array();
                $settings['scope_tags']       = array();
                $settings['scope_ids']        = array();
                return;
            }

            $parts = explode( ':', $scope, 2 );
            $type  = sanitize_key( $parts[0] );
            $value = isset( $parts[1] ) ? trim( $parts[1] ) : '';

            switch ( $type ) {
                case 'category':
                case 'categories':
                    $ids = $this->parse_id_list( $value );
                    if ( empty( $ids ) ) {
                        WP_CLI::error( 'Provide one or more category IDs for the scope.' );
                    }
                    $settings['export_scope']     = 'category';
                    $settings['scope_categories'] = $ids;
                    $settings['scope_tags']       = array();
                    $settings['scope_ids']        = array();
                    break;
                case 'tag':
                case 'tags':
                    $ids = $this->parse_id_list( $value );
                    if ( empty( $ids ) ) {
                        WP_CLI::error( 'Provide one or more tag IDs for the scope.' );
                    }
                    $settings['export_scope']     = 'tag';
                    $settings['scope_tags']       = $ids;
                    $settings['scope_categories'] = array();
                    $settings['scope_ids']        = array();
                    break;
                case 'ids':
                    $ids = $this->parse_id_list( $value );
                    if ( empty( $ids ) ) {
                        WP_CLI::error( 'Provide one or more product IDs for the scope.' );
                    }
                    $settings['export_scope']     = 'ids';
                    $settings['scope_ids']        = $ids;
                    $settings['scope_categories'] = array();
                    $settings['scope_tags']       = array();
                    break;
                case 'all':
                    $settings['export_scope']     = 'all';
                    $settings['scope_categories'] = array();
                    $settings['scope_tags']       = array();
                    $settings['scope_ids']        = array();
                    break;
                default:
                    WP_CLI::error( sprintf( 'Unsupported scope "%s".', $scope ) );
            }
        }

        /**
         * Parses a comma-separated list of IDs.
         *
         * @param string $value Raw ID list.
         *
         * @return array<int,int>
         */
        protected function parse_id_list( $value ) {
            if ( '' === $value ) {
                return array();
            }

            $ids = array_filter(
                array_map(
                    'absint',
                    array_map( 'trim', explode( ',', $value ) )
                )
            );

            return array_values( array_unique( $ids ) );
        }

        /**
         * Normalizes a filesystem path relative to the current working directory when needed.
         *
         * @param string $path Raw path.
         *
         * @return string
         */
        protected function normalize_path( $path ) {
            $path = wp_normalize_path( (string) $path );

            if ( '' === $path ) {
                return $path;
            }

            if ( preg_match( '#^(?:[a-zA-Z]:)?[\\/]#', $path ) ) {
                return $path;
            }

            $cwd = function_exists( 'getcwd' ) ? getcwd() : ABSPATH;

            return wp_normalize_path( trailingslashit( $cwd ) . ltrim( $path, '/\\' ) );
        }

        /**
         * Removes a UTF-8 BOM from the beginning of a string when present.
         *
         * @param string $line Input string.
         *
         * @return string
         */
        protected function strip_bom( $line ) {
            if ( 0 === strpos( $line, "\xEF\xBB\xBF" ) ) {
                return substr( $line, 3 );
            }

            return $line;
        }
    }

    WP_CLI::add_command( 'woo2shopify', 'WSE_Woo2Shopify_CLI_Command' );
}
