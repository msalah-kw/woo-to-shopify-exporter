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

if ( ! function_exists( 'wse_ensure_postmeta_indexes' ) ) {

    /**
     * Ensures supporting indexes exist on the postmeta table for heavy reads.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @return true|WP_Error
     */
    function wse_ensure_postmeta_indexes() {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return new WP_Error( 'wse_database_unavailable', __( 'Database connection is not available.', 'woo-to-shopify-exporter' ) );
        }

        $table = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';

        if ( empty( $table ) ) {
            return true;
        }

        $indexes = array();
        $results = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                if ( isset( $row['Key_name'] ) ) {
                    $indexes[ $row['Key_name'] ] = true;
                }
            }
        } elseif ( ! empty( $wpdb->last_error ) ) {
            return new WP_Error( 'wse_postmeta_index_error', $wpdb->last_error );
        }

        $targets = array(
            'wse_pm_meta_key_post_id' => 'meta_key(191), post_id',
            'wse_pm_post_id_meta_key' => 'post_id, meta_key(191)',
        );

        foreach ( $targets as $name => $definition ) {
            if ( isset( $indexes[ $name ] ) ) {
                continue;
            }

            $query = "CREATE INDEX `{$name}` ON `{$table}` ({$definition})";
            $result = $wpdb->query( $query );

            if ( false === $result && ! empty( $wpdb->last_error ) ) {
                return new WP_Error( 'wse_postmeta_index_error', $wpdb->last_error );
            }
        }

        return true;
    }
}

if ( ! class_exists( 'WSE_Export_Logger' ) ) {

    /**
     * Persists structured export logs and failure details alongside generated files.
     */
    class WSE_Export_Logger {

        /**
         * Path to the job log file.
         *
         * @var string
         */
        protected $log_path = '';

        /**
         * Path to the failure manifest file.
         *
         * @var string
         */
        protected $failures_path = '';

        /**
         * Collected failures for JSON output.
         *
         * @var array
         */
        protected $failures = array();

        /**
         * Identifier for the active job.
         *
         * @var string
         */
        protected $job_id = '';

        /**
         * Last error encountered while writing log output.
         *
         * @var WP_Error|null
         */
        protected $last_error = null;

        /**
         * Constructor.
         *
         * @param string $directory Export directory path.
         * @param string $job_id    Export job identifier.
         */
        public function __construct( $directory, $job_id ) {
            $directory     = wp_normalize_path( (string) $directory );
            $this->job_id  = (string) $job_id;
            $this->log_path      = trailingslashit( $directory ) . 'job.log';
            $this->failures_path = trailingslashit( $directory ) . 'failures.json';

            if ( '' === $directory || ! wp_mkdir_p( $directory ) ) {
                $this->last_error = new WP_Error( 'wse_log_dir_unwritable', __( 'Unable to create the export log directory.', 'woo-to-shopify-exporter' ) );
                return;
            }

            if ( ! file_exists( $this->log_path ) ) {
                if ( false === @file_put_contents( $this->log_path, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    $this->last_error = new WP_Error( 'wse_log_write_failed', __( 'Unable to initialize job.log.', 'woo-to-shopify-exporter' ) );
                    return;
                }
            }

            if ( file_exists( $this->failures_path ) ) {
                $contents = @file_get_contents( $this->failures_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $decoded  = json_decode( $contents, true );

                if ( is_array( $decoded ) ) {
                    $this->failures = $decoded;
                }
            } else {
                $this->save_failures();
            }
        }

        /**
         * Writes a formatted message to job.log.
         *
         * @param string $level   Log level (info, warning, error).
         * @param string $message Message to log.
         * @param array  $context Optional contextual data.
         *
         * @return void
         */
        public function log( $level, $message, array $context = array() ) {
            if ( empty( $this->log_path ) ) {
                return;
            }

            $context = $this->augment_context( $context );
            $level   = strtoupper( (string) $level );
            $line    = sprintf( '[%s] %s: %s', gmdate( 'c' ), $level, $message );

            if ( ! empty( $context ) ) {
                $line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }

            $line .= PHP_EOL;

            if ( false === @file_put_contents( $this->log_path, $line, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $this->last_error = new WP_Error( 'wse_log_write_failed', __( 'Unable to write to job.log.', 'woo-to-shopify-exporter' ) );
            }
        }

        /**
         * Records a failure entry and persists failures.json.
         *
         * @param string $code    Failure code identifier.
         * @param string $message Description of the failure.
         * @param array  $data    Additional context for the failure.
         * @param string $level   Severity level (error|warning).
         *
         * @return void
         */
        public function record_failure( $code, $message, array $data = array(), $level = 'error' ) {
            $data = $this->augment_context( $data );

            if ( isset( $data['job_id'] ) ) {
                unset( $data['job_id'] );
            }

            $entry = array(
                'timestamp' => time(),
                'level'     => strtolower( (string) $level ),
                'code'      => (string) $code,
                'message'   => (string) $message,
                'job_id'    => $this->job_id,
                'data'      => $data,
            );

            $this->failures[] = $entry;
            $this->save_failures();
        }

        /**
         * Returns the last logger error, if any.
         *
         * @return WP_Error|null
         */
        public function get_last_error() {
            return $this->last_error;
        }

        /**
         * Augments context arrays with the job identifier.
         *
         * @param array $context Existing context data.
         *
         * @return array
         */
        protected function augment_context( array $context ) {
            if ( $this->job_id && ! isset( $context['job_id'] ) ) {
                $context['job_id'] = $this->job_id;
            }

            return $context;
        }

        /**
         * Persists the in-memory failures collection to disk.
         *
         * @return void
         */
        protected function save_failures() {
            if ( empty( $this->failures_path ) ) {
                return;
            }

            $encoded = wp_json_encode( $this->failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

            if ( false === @file_put_contents( $this->failures_path, $encoded ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $this->last_error = new WP_Error( 'wse_failure_log_unwritable', __( 'Unable to write failures.json.', 'woo-to-shopify-exporter' ) );
            }
        }
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
         * Export output directory information (path + URL).
         *
         * @var array
         */
        protected $output_directory = array();

        /**
         * Delimiter used for generated CSV files.
         *
         * @var string
         */
        protected $file_delimiter = ',';

        /**
         * Logger instance capturing job output and failures.
         *
         * @var WSE_Export_Logger|null
         */
        protected $logger = null;

        /**
         * Captures errors encountered while creating postmeta indexes.
         *
         * @var WP_Error|null
         */
        protected $postmeta_index_error = null;

        /**
         * Constructor.
         *
         * @param array $job  Job payload containing at minimum an id and settings.
         * @param array $args Optional overrides (watchdog_time, watchdog_memory, lock_ttl, writer).
         */
        public function __construct( array $job, array $args = array() ) {
            $this->job = $job;

            if ( isset( $args['watchdog_time'] ) ) {
                $time_limit = (int) $args['watchdog_time'];

                if ( $time_limit <= 0 ) {
                    $this->time_limit = 0;
                } else {
                    $this->time_limit = max( 5, $time_limit );
                }
            }

            if ( isset( $args['watchdog_memory'] ) ) {
                $memory_threshold = (float) $args['watchdog_memory'];

                if ( $memory_threshold <= 0 ) {
                    $this->memory_threshold = 0;
                } else {
                    $this->memory_threshold = max( 0.1, min( 0.95, $memory_threshold ) );
                }
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

            $indexed = wse_ensure_postmeta_indexes();

            if ( is_wp_error( $indexed ) ) {
                $this->postmeta_index_error = $indexed;

                if ( function_exists( 'error_log' ) ) {
                    error_log( 'Woo to Shopify Exporter: ' . $indexed->get_error_message() );
                }

                /**
                 * Fires when the exporter cannot create the recommended postmeta indexes.
                 *
                 * @since 1.0.0
                 *
                 * @param WP_Error              $indexed_error Index creation error.
                 * @param WSE_Export_Orchestrator $orchestrator Current orchestrator instance.
                 */
                do_action( 'wse_postmeta_index_error', $indexed, $this );
            }

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

            $this->initialize_logger();

            $this->log_postmeta_index_warning();

            $this->batch_size = $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->get_batch_size() : $this->batch_size;

            $this->job['status']       = 'running';
            $this->job['message']      = __( 'Export in progress…', 'woo-to-shopify-exporter' );
            $this->job['progress']     = max( 10, isset( $this->job['progress'] ) ? (int) $this->job['progress'] : 10 );
            $this->job['last_updated'] = time();

            $this->persist_job_state();

            return true;
        }

        /**
         * Initializes the export logger and attaches writer hooks.
         *
         * @return void
         */
        protected function initialize_logger() {
            if ( $this->logger instanceof WSE_Export_Logger ) {
                return;
            }

            if ( empty( $this->output_directory['path'] ) ) {
                return;
            }

            $logger = new WSE_Export_Logger( $this->output_directory['path'], isset( $this->job['id'] ) ? $this->job['id'] : '' );

            if ( $logger->get_last_error() instanceof WP_Error ) {
                if ( function_exists( 'error_log' ) ) {
                    error_log( 'Woo to Shopify Exporter: ' . $logger->get_last_error()->get_error_message() );
                }

                return;
            }

            $this->logger = $logger;

            add_action( 'wse_csv_writer_warning', array( $this, 'handle_writer_warning' ), 10, 2 );

            $resume = isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] > 0 : false;
            $scope_summary = array(
                'type'           => isset( $this->scope['type'] ) ? $this->scope['type'] : 'all',
                'status'         => isset( $this->scope['status'] ) ? array_values( (array) $this->scope['status'] ) : array(),
                'category_count' => isset( $this->scope['categories'] ) ? count( (array) $this->scope['categories'] ) : 0,
                'tag_count'      => isset( $this->scope['tags'] ) ? count( (array) $this->scope['tags'] ) : 0,
                'id_count'       => isset( $this->scope['ids'] ) ? count( (array) $this->scope['ids'] ) : 0,
            );

            $writer_summary = array(
                'batch_size'        => $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->get_batch_size() : $this->batch_size,
                'include_images'    => $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->includes_images() : true,
                'include_variants'  => $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->includes_variants() : true,
                'include_inventory' => $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->includes_inventory() : true,
            );

            $message = $resume ? __( 'Resuming export job.', 'woo-to-shopify-exporter' ) : __( 'Starting export job.', 'woo-to-shopify-exporter' );

            $this->logger->log(
                'info',
                $message,
                array(
                    'scope'           => $scope_summary,
                    'writer'          => $writer_summary,
                    'resume'          => $resume,
                    'last_product_id' => isset( $this->job['last_product_id'] ) ? (int) $this->job['last_product_id'] : 0,
                )
            );
        }

        /**
         * Emits a warning entry when postmeta indexes cannot be created.
         *
         * @return void
         */
        protected function log_postmeta_index_warning() {
            if ( ! ( $this->postmeta_index_error instanceof WP_Error ) ) {
                return;
            }

            $error   = $this->postmeta_index_error;
            $message = sprintf(
                /* translators: %s: database error message. */
                __( 'Unable to create optimized postmeta indexes. Continuing without them. (%s)', 'woo-to-shopify-exporter' ),
                $error->get_error_message()
            );

            $context = array(
                'code' => $error->get_error_code(),
            );

            if ( $this->logger instanceof WSE_Export_Logger ) {
                $this->logger->log( 'warning', $message, $context );
                $this->logger->record_failure( $error->get_error_code(), $message, $context, 'warning' );
            }

            $this->postmeta_index_error = null;
        }

        /**
         * Handles warnings emitted by the CSV writer.
         *
         * @param array                 $warning Warning payload.
         * @param WSE_Shopify_CSV_Writer $writer  Writer instance.
         *
         * @return void
         */
        public function handle_writer_warning( $warning, $writer ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass
            if ( ! $this->logger instanceof WSE_Export_Logger || empty( $warning ) || ! is_array( $warning ) ) {
                return;
            }

            $message = isset( $warning['message'] ) ? $warning['message'] : '';
            $code    = isset( $warning['code'] ) ? $warning['code'] : 'warning';
            $data    = isset( $warning['data'] ) && is_array( $warning['data'] ) ? $warning['data'] : array();

            if ( isset( $warning['code'] ) ) {
                $data['code'] = $warning['code'];
            }

            $this->logger->log( 'warning', $message, $data );
            $this->logger->record_failure( $code, $message, $data, 'warning' );
        }

        /**
         * Removes logger hooks once the run is complete.
         *
         * @return void
         */
        protected function teardown_logger() {
            remove_action( 'wse_csv_writer_warning', array( $this, 'handle_writer_warning' ), 10 );
            $this->logger = null;
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
            $page            = isset( $this->job['last_page'] ) ? max( 1, (int) $this->job['last_page'] ) : 1;
            $limit           = max( 1, (int) $this->batch_size );
            $items_count     = 0;
            $paused          = false;
            $resume_from_id  = isset( $this->job['last_product_id'] ) ? (int) $this->job['last_product_id'] : 0;
            $include_images  = $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->includes_images() : true;

            do {
                $query_scope                    = $this->scope;
                $query_scope['limit']           = $limit;
                $query_scope['page']            = $page;
                $query_scope['include_images']  = $include_images;

                if ( $resume_from_id > 0 ) {
                    $query_scope['resume_from_id'] = $resume_from_id;
                }

                $stream_error = null;

                $result = wse_stream_products(
                    $query_scope,
                    function ( $package ) use ( &$stream_error, &$paused, &$resume_from_id ) {
                        $product_id = isset( $package['meta']['product']['id'] ) ? (int) $package['meta']['product']['id'] : 0;

                        if ( $product_id && $product_id <= (int) $this->job['last_product_id'] ) {
                            return true;
                        }

                        $written = $this->write_package( $package );

                        if ( is_wp_error( $written ) ) {
                            $stream_error = $written;
                            return false;
                        }

                        if ( $product_id ) {
                            $resume_from_id = $product_id;
                        }

                        if ( $this->should_pause() ) {
                            $paused = true;
                            return false;
                        }

                        return true;
                    }
                );

                if ( $stream_error instanceof WP_Error ) {
                    return $this->fail_job( $stream_error );
                }

                $items_count = isset( $result['query_count'] ) ? (int) $result['query_count'] : 0;

                if ( $paused || ( isset( $result['stopped'] ) && $result['stopped'] ) ) {
                    break;
                }

                if ( 0 === $items_count ) {
                    break;
                }

                $page++;
                $resume_from_id = 0;
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
         * Generates optional manifest files requested in the export settings.
         *
         * @return array|WP_Error
         */
        protected function generate_additional_manifests() {
            $files = array();

            if ( empty( $this->output_directory['path'] ) || ! is_array( $this->output_directory ) ) {
                return $files;
            }

            $directory = array(
                'path' => $this->output_directory['path'],
                'url'  => isset( $this->output_directory['url'] ) ? $this->output_directory['url'] : '',
            );

            $delimiter = $this->file_delimiter;

            if ( '' === $delimiter ) {
                $delimiter = ',';
            }

            if ( ! empty( $this->settings['include_collections'] ) && function_exists( 'wse_generate_collections_manifest' ) ) {
                $collections = wse_generate_collections_manifest( $this->scope, $directory, $delimiter );

                if ( is_wp_error( $collections ) ) {
                    return $collections;
                }

                if ( ! empty( $collections ) ) {
                    $files[] = $collections;
                }
            }

            if ( ! empty( $this->settings['include_redirects'] ) && function_exists( 'wse_generate_redirects_manifest' ) ) {
                $redirects = wse_generate_redirects_manifest( $this->scope, $directory, $delimiter );

                if ( is_wp_error( $redirects ) ) {
                    return $redirects;
                }

                if ( ! empty( $redirects ) ) {
                    $files[] = $redirects;
                }
            }

            return $files;
        }

        /**
         * Writes a single product package to the streaming writer.
         *
         * @param array $package Product package.
         *
         * @return true|WP_Error
         */
        protected function write_package( array $package ) {
            $product_row  = isset( $package['product'] ) && is_array( $package['product'] ) ? $package['product'] : array();
            $product_meta = isset( $package['meta']['product'] ) && is_array( $package['meta']['product'] ) ? $package['meta']['product'] : array();
            $handle       = isset( $product_row['Handle'] ) ? (string) $product_row['Handle'] : '';
            $product_id   = isset( $product_meta['id'] ) ? (int) $product_meta['id'] : 0;

            if ( ! $this->writer->write_product_package( $package ) ) {
                $error = $this->writer->get_last_error();

                if ( ! $error instanceof WP_Error ) {
                    $error = new WP_Error( 'wse_writer_failure', __( 'Failed to write the Shopify export.', 'woo-to-shopify-exporter' ) );
                }

                if ( $this->logger instanceof WSE_Export_Logger ) {
                    $failure = $this->writer instanceof WSE_Shopify_CSV_Writer ? $this->writer->get_last_failure() : array();
                    $message = isset( $failure['message'] ) ? $failure['message'] : $error->get_error_message();
                    $code    = isset( $failure['code'] ) ? $failure['code'] : $error->get_error_code();
                    $data    = isset( $failure['data'] ) && is_array( $failure['data'] ) ? $failure['data'] : array();

                    $data['handle']     = $handle;
                    $data['product_id'] = $product_id;

                    $this->logger->log( 'error', $message, $data );
                    $this->logger->record_failure( $code, $message, $data );
                }

                return $error;
            }

            $this->job['product_count'] = isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] + 1 : 1;
            $this->job['row_count']     = $this->writer->get_total_rows_written();
            $this->job['files']         = $this->writer->get_files();
            $this->job['progress']      = $this->calculate_progress();
            $this->job['status']        = 'running';
            $this->job['last_updated']  = time();

            if ( $product_id ) {
                $this->job['last_product_id'] = $product_id;
            }

            $variants = isset( $package['variants'] ) && is_array( $package['variants'] ) ? $package['variants'] : array();
            $this->job['last_variant_index'] = empty( $variants ) ? -1 : ( count( $variants ) - 1 );

            $this->job['message'] = sprintf(
                /* translators: %d: processed products count */
                __( 'Exported %d products…', 'woo-to-shopify-exporter' ),
                (int) $this->job['product_count']
            );

            $this->persist_job_state();

            if ( $this->logger instanceof WSE_Export_Logger ) {
                $variant_count = count( $variants );
                $this->logger->log(
                    'info',
                    sprintf(
                        /* translators: 1: product handle, 2: product ID. */
                        __( 'Processed product %1$s (ID %2$d).', 'woo-to-shopify-exporter' ),
                        $handle ? $handle : __( '(no handle)', 'woo-to-shopify-exporter' ),
                        $product_id
                    ),
                    array(
                        'handle'      => $handle,
                        'product_id'  => $product_id,
                        'variants'    => $variant_count,
                        'rows_written'=> $this->writer->get_total_rows_written(),
                    )
                );
            }

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

            $files       = $this->writer->get_files();
            $additional  = $this->generate_additional_manifests();

            if ( is_wp_error( $additional ) ) {
                return $this->fail_job( $additional );
            }

            if ( ! empty( $additional ) ) {
                $files = array_merge( $files, $additional );
            }

            $files = array_values( $files );
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

            if ( $this->logger instanceof WSE_Export_Logger ) {
                $this->logger->log(
                    'info',
                    __( 'Export job completed successfully.', 'woo-to-shopify-exporter' ),
                    array(
                        'products' => $products,
                        'files'    => $count,
                        'rows'     => $this->job['row_count'],
                    )
                );
            }

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

            if ( $this->logger instanceof WSE_Export_Logger ) {
                $this->logger->log(
                    'info',
                    __( 'Export job paused.', 'woo-to-shopify-exporter' ),
                    array(
                        'reason'   => $message,
                        'products' => isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] : 0,
                        'rows'     => $this->job['row_count'],
                    )
                );
            }

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

            if ( $this->logger instanceof WSE_Export_Logger ) {
                $context = array(
                    'code'     => $error->get_error_code(),
                    'products' => isset( $this->job['product_count'] ) ? (int) $this->job['product_count'] : 0,
                    'rows'     => isset( $this->job['row_count'] ) ? (int) $this->job['row_count'] : 0,
                );

                $this->logger->log( 'error', $error->get_error_message(), $context );
                $this->logger->record_failure( $error->get_error_code(), $error->get_error_message(), $context );
            }

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

            $this->output_directory = array(
                'path' => isset( $directory['path'] ) ? $directory['path'] : '',
                'url'  => isset( $directory['url'] ) ? $directory['url'] : '',
            );
            $this->file_delimiter   = $delimiter;

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

            $this->output_directory = array(
                'path' => isset( $writer_args['output_dir'] ) ? $writer_args['output_dir'] : $this->output_directory['path'],
                'url'  => isset( $writer_args['base_url'] ) ? $writer_args['base_url'] : $this->output_directory['url'],
            );
            $this->file_delimiter   = isset( $writer_args['delimiter'] ) && '' !== $writer_args['delimiter'] ? $writer_args['delimiter'] : $this->file_delimiter;

            $this->writer = new WSE_Shopify_CSV_Writer( $writer_args );

            return true;
        }

        /**
         * Releases the mutex lock for the job.
         *
         * @return void
         */
        protected function release_lock() {
            $this->teardown_logger();

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

