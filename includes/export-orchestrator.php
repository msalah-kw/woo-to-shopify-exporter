<?php
/**
 * Export job orchestrator with resume support.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wse_get_export_jobs_table_name' ) ) {

    /**
     * Returns the name of the export jobs table.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @return string
     */
    function wse_get_export_jobs_table_name() {
        global $wpdb;

        return isset( $wpdb ) ? $wpdb->prefix . 'wse_export_jobs' : 'wp_wse_export_jobs';
    }
}

if ( ! function_exists( 'wse_ensure_export_jobs_table' ) ) {

    /**
     * Ensures the export jobs table exists.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @return true|WP_Error
     */
    function wse_ensure_export_jobs_table() {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return new WP_Error( 'wse_database_unavailable', __( 'Database connection is not available.', 'woo-to-shopify-exporter' ) );
        }

        $table_name      = wse_get_export_jobs_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $schema = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key varchar(191) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            progress float NOT NULL DEFAULT 0,
            message text NULL,
            settings longtext NULL,
            scope longtext NULL,
            last_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            last_variant_index int NOT NULL DEFAULT -1,
            last_page int NOT NULL DEFAULT 1,
            row_count bigint(20) unsigned NOT NULL DEFAULT 0,
            product_count bigint(20) unsigned NOT NULL DEFAULT 0,
            writer_state longtext NULL,
            files longtext NULL,
            download_url text NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY job_key (job_key)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $schema );

        if ( ! empty( $wpdb->last_error ) ) {
            return new WP_Error( 'wse_jobs_table_error', $wpdb->last_error );
        }

        return true;
    }
}

if ( ! function_exists( 'wse_decode_job_field' ) ) {

    /**
     * Decodes a JSON encoded job field into an array.
     *
     * @param string|null $value Encoded value.
     *
     * @return array
     */
    function wse_decode_job_field( $value ) {
        if ( empty( $value ) ) {
            return array();
        }

        $decoded = json_decode( $value, true );

        return is_array( $decoded ) ? $decoded : array();
    }
}

if ( ! function_exists( 'wse_get_export_job_record' ) ) {

    /**
     * Retrieves a persisted export job record.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @param string $job_key Job identifier.
     *
     * @return array
     */
    function wse_get_export_job_record( $job_key ) {
        global $wpdb;

        if ( empty( $job_key ) || ! isset( $wpdb ) ) {
            return array();
        }

        $table = wse_get_export_jobs_table_name();

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_key = %s", $job_key ), ARRAY_A );

        if ( empty( $row ) ) {
            return array();
        }

        $row['id']                = $job_key;
        $row['settings']          = wse_decode_job_field( isset( $row['settings'] ) ? $row['settings'] : '' );
        $row['scope']             = wse_decode_job_field( isset( $row['scope'] ) ? $row['scope'] : '' );
        $row['writer_state']      = wse_decode_job_field( isset( $row['writer_state'] ) ? $row['writer_state'] : '' );
        $row['files']             = wse_decode_job_field( isset( $row['files'] ) ? $row['files'] : '' );
        $row['progress']          = isset( $row['progress'] ) ? (float) $row['progress'] : 0;
        $row['last_product_id']   = isset( $row['last_product_id'] ) ? (int) $row['last_product_id'] : 0;
        $row['last_variant_index'] = isset( $row['last_variant_index'] ) ? (int) $row['last_variant_index'] : -1;
        $row['last_page']         = isset( $row['last_page'] ) ? (int) $row['last_page'] : 1;
        $row['row_count']         = isset( $row['row_count'] ) ? (int) $row['row_count'] : 0;
        $row['product_count']     = isset( $row['product_count'] ) ? (int) $row['product_count'] : 0;
        $row['status']            = isset( $row['status'] ) ? $row['status'] : 'queued';
        $row['message']           = isset( $row['message'] ) ? $row['message'] : '';
        $row['download_url']      = isset( $row['download_url'] ) ? $row['download_url'] : '';

        return $row;
    }
}

if ( ! function_exists( 'wse_upsert_export_job_record' ) ) {

    /**
     * Persists an export job record.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @param array $job Job payload.
     *
     * @return true|WP_Error
     */
    function wse_upsert_export_job_record( array $job ) {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return new WP_Error( 'wse_database_unavailable', __( 'Database connection is not available.', 'woo-to-shopify-exporter' ) );
        }

        if ( empty( $job['id'] ) ) {
            return new WP_Error( 'wse_missing_job_id', __( 'A job identifier is required.', 'woo-to-shopify-exporter' ) );
        }

        $table = wse_get_export_jobs_table_name();
        $now   = current_time( 'mysql', true );

        $data = array(
            'status'            => isset( $job['status'] ) ? $job['status'] : 'queued',
            'progress'          => isset( $job['progress'] ) ? (float) $job['progress'] : 0,
            'message'           => isset( $job['message'] ) ? $job['message'] : '',
            'settings'          => wp_json_encode( isset( $job['settings'] ) ? $job['settings'] : array() ),
            'scope'             => wp_json_encode( isset( $job['scope'] ) ? $job['scope'] : array() ),
            'last_product_id'   => isset( $job['last_product_id'] ) ? (int) $job['last_product_id'] : 0,
            'last_variant_index' => isset( $job['last_variant_index'] ) ? (int) $job['last_variant_index'] : -1,
            'last_page'         => isset( $job['last_page'] ) ? (int) $job['last_page'] : 1,
            'row_count'         => isset( $job['row_count'] ) ? (int) $job['row_count'] : 0,
            'product_count'     => isset( $job['product_count'] ) ? (int) $job['product_count'] : 0,
            'writer_state'      => wp_json_encode( isset( $job['writer_state'] ) ? $job['writer_state'] : array() ),
            'files'             => wp_json_encode( isset( $job['files'] ) ? $job['files'] : array() ),
            'download_url'      => isset( $job['download_url'] ) ? $job['download_url'] : '',
            'updated_at'        => $now,
        );

        $update_formats = array( '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_key = %s", $job['id'] ) );

        if ( $exists ) {
            $updated = $wpdb->update( $table, $data, array( 'job_key' => $job['id'] ), $update_formats, array( '%s' ) );
        } else {
            $data['job_key']    = $job['id'];
            $data['created_at'] = $now;
            $insert_formats     = array_merge( $update_formats, array( '%s', '%s' ) );
            $updated            = $wpdb->insert( $table, $data, $insert_formats );
        }

        if ( false === $updated ) {
            return new WP_Error( 'wse_job_persist_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Unable to persist export job.', 'woo-to-shopify-exporter' ) );
        }

        return true;
    }
}

if ( ! function_exists( 'wse_acquire_export_mutex' ) ) {

    /**
     * Attempts to acquire a mutex lock for an export job.
     *
     * @param string $job_key Job identifier.
     * @param int    $ttl     Lock lifespan in seconds.
     *
     * @return bool
     */
    function wse_acquire_export_mutex( $job_key, $ttl = 120 ) {
        $lock_key = 'wse_export_lock_' . md5( $job_key );

        if ( get_transient( $lock_key ) ) {
            return false;
        }

        return (bool) set_transient( $lock_key, 1, $ttl );
    }
}

if ( ! function_exists( 'wse_release_export_mutex' ) ) {

    /**
     * Releases a previously acquired export job mutex.
     *
     * @param string $job_key Job identifier.
     *
     * @return void
     */
    function wse_release_export_mutex( $job_key ) {
        $lock_key = 'wse_export_lock_' . md5( $job_key );
        delete_transient( $lock_key );
    }
}

if ( ! function_exists( 'wse_get_memory_limit_bytes' ) ) {

    /**
     * Converts the PHP memory limit to bytes.
     *
     * @return int
     */
    function wse_get_memory_limit_bytes() {
        $limit = ini_get( 'memory_limit' );

        if ( ! $limit || '-1' === $limit ) {
            return -1;
        }

        $unit  = strtolower( substr( $limit, -1 ) );
        $value = (float) $limit;

        switch ( $unit ) {
            case 'g':
                $value *= 1024;
                // no break intentionally.
            case 'm':
                $value *= 1024;
                // no break intentionally.
            case 'k':
                $value *= 1024;
                break;
        }

        return (int) $value;
    }
}

if ( ! class_exists( 'WSE_Export_Orchestrator' ) ) {

    /**
     * Coordinates export job execution with watchdog, resume, and persistence support.
     */
    class WSE_Export_Orchestrator {

        /**
         * Export job payload.
         *
         * @var array
         */
        protected $job = array();

        /**
         * Export settings used to build scope and writer configuration.
         *
         * @var array
         */
        protected $settings = array();

        /**
         * Product query scope definition.
         *
         * @var array
         */
        protected $scope = array();

        /**
         * Streaming writer instance.
         *
         * @var WSE_Shopify_CSV_Writer
         */
        protected $writer;

        /**
         * Number of packages processed per batch.
         *
         * @var int
         */
        protected $batch_size = 100;

        /**
         * Timestamp when the current run started.
         *
         * @var float
         */
        protected $start_time = 0.0;

        /**
         * Maximum runtime in seconds before pausing.
         *
         * @var int
         */
        protected $time_limit = 18;

        /**
         * Memory usage threshold (0-1) compared to PHP memory limit.
         *
         * @var float
         */
        protected $memory_threshold = 0.85;

        /**
         * Mutex lifetime in seconds.
         *
         * @var int
         */
        protected $lock_ttl = 180;

        /**
         * Optional writer overrides supplied to the orchestrator.
         *
         * @var array
         */
        protected $writer_overrides = array();

        /**
         * Indicates if a watchdog condition triggered a pause.
         *
         * @var bool
         */
        protected $watchdog_triggered = false;

        /**
         * Tracks whether a mutex lock has been acquired for this run.
         *
         * @var bool
         */
        protected $lock_acquired = false;

        /**
         * Constructor.
         *
         * @param array $job  Job payload containing at minimum an id and settings.
         * @param array $args Optional overrides (watchdog_time, watchdog_memory, lock_ttl, writer).
         */
        public function __construct( array $job, array $args = array() ) {
            $this->job = $job;

            if ( isset( $args['watchdog_time'] ) ) {
                $this->time_limit = max( 5, (int) $args['watchdog_time'] );
            }

            if ( isset( $args['watchdog_memory'] ) ) {
                $this->memory_threshold = max( 0.1, min( 0.95, (float) $args['watchdog_memory'] ) );
            }

            if ( isset( $args['lock_ttl'] ) ) {
                $this->lock_ttl = max( 60, (int) $args['lock_ttl'] );
            }

            if ( isset( $args['writer'] ) && is_array( $args['writer'] ) ) {
                $this->writer_overrides = $args['writer'];
            }
        }

        /**
         * Executes the orchestrator lifecycle.
         *
         * @return array|WP_Error
         */
        public function run() {
            $prepared = $this->prepare();

            if ( is_wp_error( $prepared ) ) {
                return $prepared;
            }

            $validated = $this->validate();

            if ( is_wp_error( $validated ) ) {
                $this->release_lock();
                return $validated;
            }

            $result = $this->iterate();

            $this->release_lock();

            return $result;
        }

        /**
         * Prepares job state, persistence, writer, and mutex locking.
         *
         * @return true|WP_Error
         */
        protected function prepare() {
            $this->start_time = microtime( true );

            $ensured = wse_ensure_export_jobs_table();

            if ( is_wp_error( $ensured ) ) {
                return $ensured;
            }

            $job_id = isset( $this->job['id'] ) ? $this->job['id'] : uniqid( 'wse_job_', true );
            $this->job['id'] = $job_id;

            if ( ! wse_acquire_export_mutex( $job_id, $this->lock_ttl ) ) {
                return new WP_Error( 'wse_job_locked', __( 'Another export is already running. Please wait until it finishes.', 'woo-to-shopify-exporter' ) );
            }

            $this->lock_acquired = true;

            $record = wse_get_export_job_record( $job_id );

            $settings = array();

            if ( ! empty( $record['settings'] ) ) {
                $settings = $record['settings'];
            } elseif ( ! empty( $this->job['settings'] ) && is_array( $this->job['settings'] ) ) {
                $settings = $this->job['settings'];
            }

            if ( empty( $settings ) ) {
                $this->release_lock();
                return new WP_Error( 'wse_missing_settings', __( 'Export settings are required to start the job.', 'woo-to-shopify-exporter' ) );
            }

            $this->settings = $settings;
            $this->scope    = wse_build_product_scope_from_settings( $this->settings );

            $defaults = array(
                'id'                => $job_id,
                'status'            => 'queued',
                'progress'          => 0,
                'message'           => '',
                'settings'          => $this->settings,
                'scope'             => $this->scope,
                'last_product_id'   => 0,
                'last_variant_index' => -1,
                'last_page'         => 1,
                'row_count'         => 0,
                'product_count'     => 0,
                'writer_state'      => array(),
                'files'             => array(),
                'download_url'      => '',
                'created_at'        => isset( $record['created_at'] ) ? $record['created_at'] : current_time( 'timestamp', true ),
                'last_updated'      => current_time( 'timestamp', true ),
            );

            $this->job = array_merge( $defaults, $record, $this->job );
            $this->job['settings'] = $this->settings;
            $this->job['scope']    = $this->scope;

            $writer = $this->build_writer();

            if ( is_wp_error( $writer ) ) {
                $this->release_lock();
                return $writer;
            }

            if ( ! empty( $this->job['writer_state'] ) && $this->writer instanceof WSE_Shopify_CSV_Writer ) {
                $this->writer->restore_state( $this->job['writer_state'] );
            }

            $this->batch_size = $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->get_batch_size() : $this->batch_size;

            $this->job['status']       = 'running';
            $this->job['message']      = __( 'Export in progress…', 'woo-to-shopify-exporter' );
            $this->job['progress']     = max( 10, isset( $this->job['progress'] ) ? (int) $this->job['progress'] : 10 );
            $this->job['last_updated'] = time();

            $this->persist_job_state();

            return true;
        }

        /**
         * Validates prerequisites before iterating.
         *
         * @return true|WP_Error
         */
        protected function validate() {
            if ( ! $this->writer instanceof WSE_Shopify_CSV_Writer ) {
                return new WP_Error( 'wse_writer_unavailable', __( 'Unable to initialize the export writer.', 'woo-to-shopify-exporter' ) );
            }

            $error = $this->writer->get_last_error();

            if ( $error instanceof WP_Error ) {
                return $error;
            }

            return true;
        }

        /**
         * Iterates through product batches until completion or watchdog pause.
         *
         * @return array|WP_Error
         */
        protected function iterate() {
            $page         = isset( $this->job['last_page'] ) ? max( 1, (int) $this->job['last_page'] ) : 1;
            $limit        = max( 1, (int) $this->batch_size );
            $items_count  = 0;
            $paused       = false;

            do {
                $query_scope          = $this->scope;
                $query_scope['limit'] = $limit;
                $query_scope['page']  = $page;

                $result = wse_load_products( $query_scope );

                $items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
                $items_count = count( $items );

                if ( 0 === $items_count ) {
                    break;
                }

                foreach ( $items as $package ) {
                    $product_id = isset( $package['meta']['product']['id'] ) ? (int) $package['meta']['product']['id'] : 0;

                    if ( $product_id && $product_id <= (int) $this->job['last_product_id'] ) {
                        continue;
                    }

                    $written = $this->write_package( $package );

                    if ( is_wp_error( $written ) ) {
                        return $this->fail_job( $written );
                    }

                    if ( $this->should_pause() ) {
                        $paused = true;
                        break 2;
                    }
                }

                $page++;
            } while ( $items_count >= $limit );

            $this->job['last_page'] = $page;

            if ( $paused ) {
                return $this->pause_job( __( 'Export paused automatically to avoid time or memory limits. Click resume to continue.', 'woo-to-shopify-exporter' ) );
            }

            if ( 0 === $items_count || $items_count < $limit ) {
                return $this->complete_job();
            }

            return $this->pause_job( __( 'Export paused. Resume to continue processing remaining products.', 'woo-to-shopify-exporter' ) );
        }

        /**
         * Writes a single product package to the streaming writer.
         *
         * @param array $package Product package.
         *
         * @return true|WP_Error
         */
        protected function write_package( array $package ) {
            if ( ! $this->writer->write_product_package( $package ) ) {
                $error = $this->writer->get_last_error();

                if ( ! $error instanceof WP_Error ) {
                    $error = new WP_Error( 'wse_writer_failure', __( 'Failed to write the Shopify export.', 'woo-to-shopify-exporter' ) );
                }

                return $error;
            }

            $this->job['product_count'] = isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] + 1 : 1;
            $this->job['row_count']     = $this->writer->get_total_rows_written();
            $this->job['files']         = $this->writer->get_files();
            $this->job['progress']      = $this->calculate_progress();
            $this->job['status']        = 'running';
            $this->job['last_updated']  = time();

            if ( isset( $package['meta']['product']['id'] ) ) {
                $this->job['last_product_id'] = (int) $package['meta']['product']['id'];
            }

            $variants = isset( $package['variants'] ) && is_array( $package['variants'] ) ? $package['variants'] : array();
            $this->job['last_variant_index'] = empty( $variants ) ? -1 : ( count( $variants ) - 1 );

            $this->job['message'] = sprintf(
                /* translators: %d: processed products count */
                __( 'Exported %d products…', 'woo-to-shopify-exporter' ),
                (int) $this->job['product_count']
            );

            $this->persist_job_state();

            return true;
        }

        /**
         * Determines whether the job should pause due to watchdog limits.
         *
         * @return bool
         */
        protected function should_pause() {
            if ( $this->time_limit > 0 && ( microtime( true ) - $this->start_time ) >= $this->time_limit ) {
                $this->watchdog_triggered = true;
                return true;
            }

            if ( $this->memory_threshold > 0 ) {
                $limit = wse_get_memory_limit_bytes();

                if ( $limit > 0 ) {
                    $usage = memory_get_usage( true );

                    if ( $usage >= $limit * $this->memory_threshold ) {
                        $this->watchdog_triggered = true;
                        return true;
                    }
                }
            }

            return false;
        }

        /**
         * Handles job completion logic.
         *
         * @return array|WP_Error
         */
        protected function complete_job() {
            if ( ! $this->writer->finish() ) {
                $error = $this->writer->get_last_error();

                if ( ! $error instanceof WP_Error ) {
                    $error = new WP_Error( 'wse_finalize_failed', __( 'Unable to finalize the Shopify export files.', 'woo-to-shopify-exporter' ) );
                }

                return $this->fail_job( $error );
            }

            $files = $this->writer->get_files();
            $count = count( $files );

            $this->job['files']        = $files;
            $this->job['download_url'] = $count > 0 && ! empty( $files[0]['url'] ) ? $files[0]['url'] : '';
            $this->job['row_count']    = $this->writer->get_total_rows_written();
            $this->job['status']       = 'completed';
            $this->job['progress']     = 100;
            $this->job['last_updated'] = time();
            $this->job['writer_state'] = $this->writer->get_state();

            $products = isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] : 0;

            $this->job['message'] = sprintf(
                _n(
                    'Export completed with %1$d product across %2$d file.',
                    'Export completed with %1$d products across %2$d files.',
                    $products,
                    'woo-to-shopify-exporter'
                ),
                $products,
                max( 1, $count )
            );

            $this->persist_job_state();

            return $this->job;
        }

        /**
         * Stores job progress and marks it as paused.
         *
         * @param string $message Pause description.
         *
         * @return array
         */
        protected function pause_job( $message ) {
            if ( $this->writer instanceof WSE_Shopify_CSV_Writer ) {
                $this->writer->pause();
                $this->job['writer_state'] = $this->writer->get_state();
            }

            $this->job['status']       = 'paused';
            $this->job['message']      = $message;
            $this->job['last_updated'] = time();
            $this->job['files']        = $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->get_files() : array();

            if ( $this->watchdog_triggered ) {
                $this->job['progress'] = max( 50, min( 95, (int) $this->job['progress'] ) );
            }

            $this->persist_job_state();

            return $this->job;
        }

        /**
         * Handles job failure persistence.
         *
         * @param WP_Error $error Failure reason.
         *
         * @return WP_Error
         */
        protected function fail_job( WP_Error $error ) {
            $this->job['status']       = 'failed';
            $this->job['message']      = $error->get_error_message();
            $this->job['progress']     = 100;
            $this->job['last_updated'] = time();

            if ( $this->writer instanceof WSE_Shopify_CSV_Writer ) {
                $this->job['writer_state'] = $this->writer->get_state();
                $this->job['files']        = $this->writer->get_files();
            }

            $this->persist_job_state();

            return $error;
        }

        /**
         * Persists job state to the database and updates UI cache if available.
         *
         * @return void
         */
        protected function persist_job_state() {
            $this->job['writer_state'] = $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->get_state() : array();

            wse_upsert_export_job_record( $this->job );

            if ( function_exists( 'wse_store_active_job' ) ) {
                wse_store_active_job( $this->job );
            }
        }

        /**
         * Builds the streaming writer instance based on settings.
         *
         * @return true|WP_Error
         */
        protected function build_writer() {
            $directory = wse_get_export_directory_info();

            if ( is_wp_error( $directory ) ) {
                return $directory;
            }

            $format = isset( $this->settings['file_format'] ) ? sanitize_key( $this->settings['file_format'] ) : 'csv';

            if ( ! in_array( $format, array( 'csv', 'tsv' ), true ) ) {
                return new WP_Error( 'wse_unsupported_format', __( 'Only CSV and TSV exports are supported.', 'woo-to-shopify-exporter' ) );
            }

            $delimiter = ',';

            if ( 'tsv' === $format ) {
                $delimiter = "\t";
            } elseif ( ! empty( $this->settings['delimiter'] ) ) {
                $delimiter = (string) $this->settings['delimiter'];
            }

            if ( '' === $delimiter ) {
                $delimiter = ',';
            }

            $writer_args = array(
                'delimiter'         => $delimiter,
                'output_dir'        => $directory['path'],
                'base_url'          => $directory['url'],
                'file_name'         => isset( $this->settings['file_name'] ) ? $this->settings['file_name'] : 'shopify-products.csv',
                'include_variants'  => ! empty( $this->settings['include_variations'] ),
                'include_images'    => ! empty( $this->settings['include_images'] ),
                'include_inventory' => ! empty( $this->settings['include_inventory'] ),
            );

            if ( ! empty( $this->writer_overrides ) ) {
                $writer_args = array_merge( $writer_args, $this->writer_overrides );
            }

            $this->writer = new WSE_Shopify_CSV_Writer( $writer_args );

            return true;
        }

        /**
         * Releases the mutex lock for the job.
         *
         * @return void
         */
        protected function release_lock() {
            if ( $this->lock_acquired && ! empty( $this->job['id'] ) ) {
                wse_release_export_mutex( $this->job['id'] );
                $this->lock_acquired = false;
            }
        }

        /**
         * Estimates a progress percentage based on processed products.
         *
         * @return int
         */
        protected function calculate_progress() {
            $processed = isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] : 0;
            $estimate  = min( 95, 10 + ( $processed * 5 ) );

            return max( (int) $this->job['progress'], $estimate );
        }
    }
}

