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
require_once dirname( __DIR__ ) . '/includes/export-orchestrator.php';

$all_tests_passed = true;

function assert_true( $condition, $message ) {
    global $all_tests_passed;
    if ( $condition ) {
        echo "PASS: {$message}\n";
    } else {
        $all_tests_passed = false;
        echo "FAIL: {$message}\n";
    }
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

$tests = array(
    'test_logger_records_entries',
    'test_writer_exposes_validation_failures',
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
