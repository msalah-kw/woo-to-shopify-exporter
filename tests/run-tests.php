<?php
// Minimal WordPress compatibility layer for running exporter unit tests.

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected $errors = array();
        protected $error_data = array();

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' === $code ) {
                return;
            }

            $this->add( $code, $message, $data );
        }

        public function add( $code, $message, $data = '' ) {
            $code    = (string) $code;
            $message = (string) $message;

            if ( '' === $code ) {
                return;
            }

            if ( ! isset( $this->errors[ $code ] ) ) {
                $this->errors[ $code ] = array();
            }

            $this->errors[ $code ][] = $message;

            if ( '' !== $data ) {
                $this->error_data[ $code ] = $data;
            }
        }

        public function get_error_messages( $code = '' ) {
            if ( '' !== $code ) {
                return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
            }

            $messages = array();
            foreach ( $this->errors as $messages_for_code ) {
                $messages = array_merge( $messages, $messages_for_code );
            }

            return $messages;
        }

        public function get_error_message( $code = '' ) {
            $messages = $this->get_error_messages( $code );

            return empty( $messages ) ? '' : $messages[0];
        }

        public function get_error_code() {
            $codes = array_keys( $this->errors );

            return empty( $codes ) ? '' : $codes[0];
        }

        public function get_error_data( $code = '' ) {
            if ( '' === $code ) {
                $code = $this->get_error_code();
            }

            return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
        }

        public function has_errors() {
            return ! empty( $this->errors );
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        return $value;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        // No-op in tests.
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
        // No-op in tests.
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $hook, $callback, $priority = 10 ) {
        // No-op in tests.
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
    function sanitize_file_name( $filename ) {
        $filename = (string) $filename;
        $filename = preg_replace( '/[^A-Za-z0-9\._-]/', '-', $filename );

        return trim( $filename, '-' );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        $key = strtolower( (string) $key );

        return preg_replace( '/[^a-z0-9_]/', '', $key );
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title ) {
        if ( function_exists( 'mb_strtolower' ) ) {
            $title = mb_strtolower( (string) $title, 'UTF-8' );
        } else {
            $title = strtolower( (string) $title );
        }
        $title = preg_replace( '/[^\p{L}\p{Nd}_\- ]+/u', '', $title );
        $title = preg_replace( '/\s+/u', '-', trim( $title ) );

        return $title;
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return strip_tags( (string) $text );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) {
        $text = (string) $text;
        $text = wp_strip_all_tags( $text );

        return trim( $text );
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        }

        if ( is_array( $args ) ) {
            return array_merge( $defaults, $args );
        }

        return $defaults;
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, "\\/" ) . '/';
    }
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
    function wp_normalize_path( $path ) {
        return str_replace( '\\', '/', $path );
    }
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
    function taxonomy_exists( $taxonomy ) {
        $taxonomy = (string) $taxonomy;

        return isset( $GLOBALS['wse_test_terms'][ $taxonomy ] );
    }
}

if ( ! function_exists( 'get_term' ) ) {
    function get_term( $term_id, $taxonomy ) {
        if ( isset( $GLOBALS['wse_test_terms'][ $taxonomy ][ $term_id ] ) ) {
            $term = $GLOBALS['wse_test_terms'][ $taxonomy ][ $term_id ];

            return (object) array(
                'term_id'  => $term['term_id'],
                'name'     => $term['name'],
                'slug'     => $term['slug'],
                'parent'   => $term['parent'],
                'taxonomy' => $taxonomy,
            );
        }

        return null;
    }
}

if ( ! function_exists( 'get_term_by' ) ) {
    function get_term_by( $field, $value, $taxonomy ) {
        if ( ! isset( $GLOBALS['wse_test_terms'][ $taxonomy ] ) ) {
            return false;
        }

        foreach ( $GLOBALS['wse_test_terms'][ $taxonomy ] as $term ) {
            if ( isset( $term[ $field ] ) && $term[ $field ] === $value ) {
                return (object) array(
                    'term_id'  => $term['term_id'],
                    'name'     => $term['name'],
                    'slug'     => $term['slug'],
                    'parent'   => $term['parent'],
                    'taxonomy' => $taxonomy,
                );
            }
        }

        return false;
    }
}

if ( ! function_exists( 'get_ancestors' ) ) {
    function get_ancestors( $term_id, $taxonomy ) {
        if ( isset( $GLOBALS['wse_test_terms'][ $taxonomy ][ $term_id ] ) ) {
            return (array) $GLOBALS['wse_test_terms'][ $taxonomy ][ $term_id ]['ancestors'];
        }

        return array();
    }
}

if ( ! function_exists( 'get_terms' ) ) {
    function get_terms( $args = array() ) {
        $taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
        if ( ! isset( $GLOBALS['wse_test_terms'][ $taxonomy ] ) ) {
            return array();
        }

        $include = isset( $args['include'] ) && is_array( $args['include'] ) ? $args['include'] : array();
        $terms   = array();

        foreach ( $GLOBALS['wse_test_terms'][ $taxonomy ] as $term_id => $term ) {
            if ( ! empty( $include ) && ! in_array( $term_id, $include, true ) ) {
                continue;
            }

            $terms[] = (object) array(
                'term_id'  => $term['term_id'],
                'name'     => $term['name'],
                'slug'     => $term['slug'],
                'parent'   => $term['parent'],
                'taxonomy' => $taxonomy,
            );
        }

        return $terms;
    }
}

if ( ! function_exists( 'get_the_terms' ) ) {
    function get_the_terms( $product_id, $taxonomy ) {
        if ( isset( $GLOBALS['wse_product_terms'][ $product_id ][ $taxonomy ] ) ) {
            $term_ids = $GLOBALS['wse_product_terms'][ $product_id ][ $taxonomy ];
            $terms    = array();

            foreach ( $term_ids as $term_id ) {
                $term = get_term( $term_id, $taxonomy );
                if ( $term ) {
                    $terms[] = $term;
                }
            }

            return $terms;
        }

        return array();
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        if ( isset( $GLOBALS['wse_post_meta'][ $post_id ][ $key ] ) ) {
            return $GLOBALS['wse_post_meta'][ $post_id ][ $key ];
        }

        return '';
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        if ( isset( $GLOBALS['wse_options'][ $option ] ) ) {
            return $GLOBALS['wse_options'][ $option ];
        }

        return $default;
    }
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() {
        return 'USD';
    }
}

if ( ! function_exists( 'wc_tax_enabled' ) ) {
    function wc_tax_enabled() {
        return true;
    }
}

if ( ! function_exists( 'wc_prices_include_tax' ) ) {
    function wc_prices_include_tax() {
        return false;
    }
}

if ( ! function_exists( 'wc_string_to_bool' ) ) {
    function wc_string_to_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        $value = strtolower( (string) $value );

        return in_array( $value, array( 'yes', 'true', '1', 'on' ), true );
    }
}

if ( ! function_exists( 'wc_stock_amount' ) ) {
    function wc_stock_amount( $value ) {
        return (float) $value;
    }
}

if ( ! function_exists( 'wc_variation_attribute_name' ) ) {
    function wc_variation_attribute_name( $name ) {
        $name = sanitize_title( $name );

        if ( 0 === strpos( $name, 'attribute_' ) ) {
            return $name;
        }

        if ( 0 === strpos( $name, 'pa_' ) ) {
            return 'attribute_' . $name;
        }

        return 'attribute_' . $name;
    }
}

if ( ! function_exists( 'wc_attribute_label' ) ) {
    function wc_attribute_label( $name ) {
        $name = (string) $name;

        return mb_convert_case( str_replace( '_', ' ', $name ), MB_CASE_TITLE, 'UTF-8' );
    }
}

if ( ! function_exists( 'wc_get_product_type_label' ) ) {
    function wc_get_product_type_label( $type ) {
        $labels = array(
            'simple'   => 'Simple product',
            'variable' => 'Variable product',
            'external' => 'External product',
        );

        return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
    }
}

if ( ! function_exists( 'wc_get_product_statuses' ) ) {
    function wc_get_product_statuses() {
        return array(
            'publish' => 'Published',
            'draft'   => 'Draft',
            'pending' => 'Pending',
            'private' => 'Private',
        );
    }
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $target ) {
        if ( is_dir( $target ) ) {
            return true;
        }

        return mkdir( $target, 0777, true );
    }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        $base = sys_get_temp_dir() . '/wse-test-uploads';

        if ( ! is_dir( $base ) ) {
            mkdir( $base, 0777, true );
        }

        return array(
            'path'    => $base,
            'basedir' => $base,
            'url'     => 'http://example.com/uploads',
            'error'   => false,
        );
    }
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
    function wp_check_invalid_utf8( $string ) {
        return $string;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        return time();
    }
}

require_once dirname( __DIR__ ) . '/includes/csv-generator.php';
require_once dirname( __DIR__ ) . '/includes/data-query.php';
require_once dirname( __DIR__ ) . '/includes/export-orchestrator.php';

$all_tests_passed = true;

function wse_read_fixture( $file ) {
    $path = dirname( __FILE__ ) . '/fixtures/' . $file;
    if ( ! file_exists( $path ) ) {
        return array();
    }

    $contents = file_get_contents( $path );
    $data     = json_decode( $contents, true );

    return is_array( $data ) ? $data : array();
}

class WSE_Test_Product_Source extends WSE_WooCommerce_Product_Source {
    public function map_product_row( array $data ) {
        return $this->build_shopify_product_row( $data );
    }

    public function flatten_variations( array $meta, array $definitions, array $product ) {
        return $this->flatten_variation_meta( $meta, $definitions, $product );
    }
}

function assert_true( $condition, $message ) {
    global $all_tests_passed;
    if ( $condition ) {
        echo "PASS: {$message}\n";
    } else {
        $all_tests_passed = false;
        echo "FAIL: {$message}\n";
    }
}

function assert_arrays_match( $expected, $actual, $message ) {
    $expected_json = json_encode( $expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    $actual_json   = json_encode( $actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

    if ( $expected_json !== $actual_json ) {
        echo "Expected:\n{$expected_json}\nActual:\n{$actual_json}\n";
    }

    assert_true( $expected_json === $actual_json, $message );
}

function test_logger_records_entries() {
    $directory = sys_get_temp_dir() . '/wse-logger-' . uniqid();

    $logger = new WSE_Export_Logger( $directory, 'job-123' );
    assert_true( file_exists( trailingslashit( $directory ) . 'job.log' ), 'Logger initializes job.log file.' );
    assert_true( file_exists( trailingslashit( $directory ) . 'failures.json' ), 'Logger initializes failures.json file.' );

    $logger->log( 'info', 'Test message', array( 'product_id' => 15 ) );
    $logger->record_failure( 'wse_test', 'Failure message', array( 'variant_id' => 30 ) );

    $log_contents = file_get_contents( trailingslashit( $directory ) . 'job.log' );
    assert_true( false !== strpos( $log_contents, 'Test message' ), 'Logger writes structured log entries.' );

    $failures = json_decode( file_get_contents( trailingslashit( $directory ) . 'failures.json' ), true );
    assert_true( is_array( $failures ) && 1 === count( $failures ), 'Logger records failures in JSON manifest.' );
    assert_true( isset( $failures[0]['data']['variant_id'] ) && 30 === $failures[0]['data']['variant_id'], 'Logger persists failure context.' );
}

function test_writer_exposes_validation_failures() {
    $directory = sys_get_temp_dir() . '/wse-writer-' . uniqid();
    wp_mkdir_p( $directory );

    $writer = new WSE_Shopify_CSV_Writer(
        array(
            'output_dir'       => $directory,
            'file_name'        => 'test.csv',
            'include_variants' => true,
        )
    );

    $package = array(
        'product'  => array( 'Handle' => '' ),
        'variants' => array(),
        'images'   => array(),
    );

    $result = $writer->write_product_package( $package );
    assert_true( false === $result, 'Writer rejects invalid packages.' );

    $failure = $writer->get_last_failure();
    assert_true( is_array( $failure ) && isset( $failure['code'] ), 'Writer exposes failure payloads.' );
    assert_true( 'wse_missing_handle' === $failure['code'], 'Writer reports the missing handle failure code.' );
    assert_true( $writer->get_last_error() instanceof WP_Error, 'Writer sets a WP_Error on validation failure.' );
}

function test_product_mapping_matches_golden_fixture() {
    $fixture = wse_read_fixture( 'sample-product.json' );

    $GLOBALS['wse_test_terms'] = array(
        'product_cat' => array(
            10 => array(
                'term_id'   => 10,
                'name'      => 'ملابس',
                'slug'      => 'clothing',
                'parent'    => 0,
                'ancestors' => array(),
            ),
            11 => array(
                'term_id'   => 11,
                'name'      => 'قمصان',
                'slug'      => 'shirts',
                'parent'    => 10,
                'ancestors' => array( 10 ),
            ),
        ),
        'product_tag' => array(
            201 => array(
                'term_id'   => 201,
                'name'      => 'رجالي',
                'slug'      => 'men',
                'parent'    => 0,
                'ancestors' => array(),
            ),
            202 => array(
                'term_id'   => 202,
                'name'      => 'صيف',
                'slug'      => 'summer',
                'parent'    => 0,
                'ancestors' => array(),
            ),
        ),
    );

    $GLOBALS['wse_product_terms'] = array(
        1 => array(
            'product_cat' => array( 11 ),
            'product_tag' => array( 201, 202 ),
        ),
    );

    $source  = new WSE_Test_Product_Source();
    $actual  = $source->map_product_row( $fixture['product_data'] );
    $expected = $fixture['expected']['shopify_product'];

    assert_arrays_match( $expected, $actual, 'Product mapping matches golden Shopify row.' );

    unset( $GLOBALS['wse_test_terms'], $GLOBALS['wse_product_terms'] );
}

function test_variation_flattening_matches_golden_fixture() {
    $fixture = wse_read_fixture( 'sample-product.json' );

    $source    = new WSE_Test_Product_Source();
    $flattened = $source->flatten_variations( $fixture['variation_meta'], $fixture['option_definitions'], $fixture['product_data'] );

    assert_arrays_match( $fixture['expected']['flattened_variations'], $flattened, 'Variation flattening matches golden payload.' );
}

function test_writer_outputs_expected_utf8_csv() {
    $fixture = wse_read_fixture( 'sample-product.json' );
    $package = $fixture['expected']['writer_package'];

    $directory = sys_get_temp_dir() . '/wse-writer-fixture-' . uniqid();
    wp_mkdir_p( $directory );

    $writer = new WSE_Shopify_CSV_Writer(
        array(
            'output_dir'        => $directory,
            'file_name'         => 'fixture.csv',
            'include_variants'  => true,
            'include_images'    => true,
            'include_inventory' => true,
            'batch_size'        => 10,
        )
    );

    $result = $writer->write_product_package( $package );
    assert_true( $result, 'Writer accepts the golden product package.' );

    $finished = $writer->finish();
    assert_true( $finished, 'Writer finalizes without errors.' );

    $files = $writer->get_files();
    assert_true( ! empty( $files ), 'Writer produced at least one CSV file.' );

    $path = $files[0]['path'];
    $csv  = file_get_contents( $path );

    $encoding = mb_detect_encoding( $csv, 'UTF-8', true );
    assert_true( 'UTF-8' === $encoding, 'CSV output is encoded as UTF-8.' );

    $expected_path = dirname( __FILE__ ) . '/fixtures/expected-shopify.csv';
    $expected_csv  = file_get_contents( $expected_path );

    if ( $csv !== $expected_csv ) {
        echo "Golden CSV mismatch.\n--- Actual ---\n{$csv}\n--- Expected ---\n{$expected_csv}\n";
    }

    assert_true( $csv === $expected_csv, 'CSV output matches the golden file.' );

    unlink( $path );
    foreach ( glob( $directory . '/*' ) as $file ) {
        if ( is_file( $file ) ) {
            unlink( $file );
        }
    }
    @rmdir( $directory );
}

function test_large_export_performance_metrics() {
    $fixture = wse_read_fixture( 'sample-product.json' );
    $package = $fixture['expected']['writer_package'];

    foreach ( array( 1000, 10000 ) as $count ) {
        $directory = sys_get_temp_dir() . '/wse-perf-' . $count . '-' . uniqid();
        wp_mkdir_p( $directory );

        $writer = new WSE_Shopify_CSV_Writer(
            array(
                'output_dir'        => $directory,
                'file_name'         => 'bulk.csv',
                'include_variants'  => true,
                'include_images'    => false,
                'include_inventory' => true,
                'batch_size'        => 500,
            )
        );

        $start_memory = memory_get_usage( true );
        $start_time   = microtime( true );

        for ( $i = 0; $i < $count; $i++ ) {
            $package_instance                 = $package;
            $package_instance['product']      = $package['product'];
            $package_instance['variants']     = $package['variants'];
            $package_instance['images']       = $package['images'];
            $suffix                           = '-' . $i;
            $unique_handle                    = $package['product']['Handle'] . $suffix;
            $package_instance['product']['Handle'] = $unique_handle;

            if ( isset( $package_instance['product']['SEO Title'] ) ) {
                $package_instance['product']['SEO Title'] = $package['product']['SEO Title'];
            }

            foreach ( $package_instance['variants'] as $variant_index => $variant ) {
                $package_instance['variants'][ $variant_index ]['Handle'] = $unique_handle;

                if ( isset( $variant['Variant SKU'] ) && '' !== $variant['Variant SKU'] ) {
                    $package_instance['variants'][ $variant_index ]['Variant SKU'] = $variant['Variant SKU'] . $suffix;
                }
            }

            foreach ( $package_instance['images'] as $image_index => $image ) {
                $package_instance['images'][ $image_index ]['Handle'] = $unique_handle;
            }

            $writer->write_product_package( $package_instance );
        }

        $writer->finish();

        $duration_ms  = ( microtime( true ) - $start_time ) * 1000;
        $memory_delta = memory_get_usage( true ) - $start_memory;

        $files       = $writer->get_files();
        $total_lines = 0;
        foreach ( $files as $file ) {
            if ( ! isset( $file['path'] ) || ! file_exists( $file['path'] ) ) {
                continue;
            }

            $lines = file( $file['path'] );
            if ( false === $lines ) {
                continue;
            }

            $line_count = count( $lines );
            if ( $line_count > 0 ) {
                $line_count--; // Discount header row per file.
            }

            $total_lines += $line_count;
        }

        assert_true( $duration_ms > 0, "Duration captured for {$count} package export." );
        assert_true( $memory_delta >= 0, "Memory delta captured for {$count} package export." );
        assert_true( $total_lines >= $count, "Row count covers {$count} product packages." );

        echo sprintf( "INFO: %d packages exported in %.2fms using %d bytes across %d data rows\n", $count, $duration_ms, $memory_delta, $total_lines );

        foreach ( $files as $file ) {
            if ( isset( $file['path'] ) && file_exists( $file['path'] ) ) {
                unlink( $file['path'] );
            }
        }

        foreach ( glob( $directory . '/*' ) as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }

        @rmdir( $directory );
    }
}

$tests = array(
    'test_logger_records_entries',
    'test_writer_exposes_validation_failures',
    'test_product_mapping_matches_golden_fixture',
    'test_variation_flattening_matches_golden_fixture',
    'test_writer_outputs_expected_utf8_csv',
    'test_large_export_performance_metrics',
);

foreach ( $tests as $test ) {
    try {
        $test();
    } catch ( Exception $exception ) {
        $all_tests_passed = false;
        echo 'FAIL: ' . $test . ' threw ' . $exception->getMessage() . "\n";
    }
}

if ( $all_tests_passed ) {
    echo "All tests passed.\n";
    exit( 0 );
}

echo "Tests failed.\n";
exit( 1 );
