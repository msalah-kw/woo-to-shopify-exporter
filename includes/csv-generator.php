<?php
/**
 * Streaming CSV generator for Shopify exports.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WSE_Shopify_CSV_Writer' ) ) {

    /**
     * Provides a streaming CSV writer that targets the Shopify import schema.
     */
    class WSE_Shopify_CSV_Writer {

        /**
         * Writer configuration options.
         *
         * @var array
         */
        protected $options = array();

        /**
         * CSV header columns.
         *
         * @var array
         */
        protected $columns = array();

        /**
         * Buffered rows awaiting flush.
         *
         * @var array
         */
        protected $buffer = array();

        /**
         * Previously recorded writer state used for resume support.
         *
         * @var array|null
         */
        protected $resume_state = null;

        /**
         * Current file resource handle.
         *
         * @var resource|null
         */
        protected $file_handle = null;

        /**
         * Current file index (1-based).
         *
         * @var int
         */
        protected $file_index = 0;

        /**
         * Current file path.
         *
         * @var string
         */
        protected $current_path = '';

        /**
         * Current file URL.
         *
         * @var string
         */
        protected $current_url = '';

        /**
         * Bytes written to the current file (including header).
         *
         * @var int
         */
        protected $current_size = 0;

        /**
         * Data rows written to the current file.
         *
         * @var int
         */
        protected $current_rows = 0;

        /**
         * Total data rows written across all files.
         *
         * @var int
         */
        protected $total_rows = 0;

        /**
         * Generated file metadata.
         *
         * @var array
         */
        protected $files = array();

        /**
         * Last error that occurred during writing.
         *
         * @var WP_Error|null
         */
        protected $last_error = null;

        /**
         * Constructor.
         *
         * @param array $args Writer configuration.
         */
        public function __construct( array $args = array() ) {
            $defaults = array(
                'delimiter'          => ',',
                'enclosure'          => '"',
                'escape_char'        => '\\',
                'batch_size'         => 500,
                'max_file_size'      => 15 * 1024 * 1024, // 15MB.
                'output_dir'         => '',
                'base_url'           => '',
                'file_name'          => 'shopify-products.csv',
                'columns'            => self::get_default_columns(),
                'include_variants'   => true,
                'include_images'     => true,
                'include_inventory'  => true,
            );

            $args = wp_parse_args( $args, $defaults );

            $args['batch_size']    = max( 1, absint( apply_filters( 'wse_csv_batch_size', $args['batch_size'], $args ) ) );
            $args['max_file_size'] = (int) apply_filters( 'wse_csv_max_file_size', $args['max_file_size'], $args );
            $args['file_name']     = sanitize_file_name( $args['file_name'] );

            if ( empty( $args['file_name'] ) ) {
                $args['file_name'] = 'shopify-products.csv';
            }

            if ( empty( $args['columns'] ) || ! is_array( $args['columns'] ) ) {
                $args['columns'] = self::get_default_columns();
            }

            $this->options      = $args;
            $this->columns      = array_values( apply_filters( 'wse_csv_columns', $this->options['columns'], $this->options ) );
            $this->buffer       = array();
            $this->files        = array();
            $this->resume_state = null;
        }

        /**
         * Provides the default Shopify CSV header columns.
         *
         * @return array
         */
        public static function get_default_columns() {
            $columns = array(
                'Handle',
                'Title',
                'Body (HTML)',
                'Vendor',
                'Product Category',
                'Type',
                'Tags',
                'Published',
                'Status',
                'SEO Title',
                'SEO Description',
                'Option1 Name',
                'Option1 Value',
                'Option2 Name',
                'Option2 Value',
                'Option3 Name',
                'Option3 Value',
                'Variant SKU',
                'Variant Price',
                'Compare At Price',
                'Variant Inventory Qty',
                'Inventory Policy',
                'Inventory Tracker',
                'Variant Barcode',
                'Variant Weight',
                'Variant Weight Unit',
                'Variant Requires Shipping',
                'Variant Taxable',
                'Image Src',
                'Image Position',
                'Image Alt Text',
            );

            return apply_filters( 'wse_csv_default_columns', $columns );
        }

        /**
         * Returns the configured batch size.
         *
         * @return int
         */
        public function get_batch_size() {
            return $this->options['batch_size'];
        }

        /**
         * Retrieves the last error encountered during writing.
         *
         * @return WP_Error|null
         */
        public function get_last_error() {
            return $this->last_error;
        }

        /**
         * Returns generated file metadata.
         *
         * @return array
         */
        public function get_files() {
            return array_values( $this->files );
        }

        /**
         * Restores a previously persisted writer state to enable resuming exports.
         *
         * @param array $state Serialized writer state.
         *
         * @return void
         */
        public function restore_state( array $state ) {
            if ( empty( $state ) ) {
                return;
            }

            if ( isset( $state['files'] ) && is_array( $state['files'] ) ) {
                $this->files = $state['files'];
            }

            if ( isset( $state['total_rows'] ) ) {
                $this->total_rows = (int) $state['total_rows'];
            } elseif ( empty( $this->total_rows ) && ! empty( $this->files ) ) {
                $total = 0;

                foreach ( $this->files as $file ) {
                    $total += isset( $file['rows'] ) ? (int) $file['rows'] : 0;
                }

                $this->total_rows = $total;
            }

            $this->resume_state = array(
                'file_index'   => isset( $state['file_index'] ) ? max( 0, (int) $state['file_index'] ) : 0,
                'current_path' => isset( $state['current_path'] ) ? $state['current_path'] : '',
                'current_url'  => isset( $state['current_url'] ) ? $state['current_url'] : '',
                'current_rows' => isset( $state['current_rows'] ) ? (int) $state['current_rows'] : 0,
                'current_size' => isset( $state['current_size'] ) ? (int) $state['current_size'] : 0,
            );

            if ( $this->resume_state['file_index'] > 0 ) {
                $this->file_index = (int) $this->resume_state['file_index'];
            } elseif ( ! empty( $this->files ) ) {
                $this->file_index = max( array_keys( $this->files ) );
            }
        }

        /**
         * Provides the current writer state for persistence.
         *
         * @return array
         */
        public function get_state() {
            return array(
                'file_index'   => $this->file_index,
                'current_path' => $this->current_path,
                'current_url'  => $this->current_url,
                'current_rows' => $this->current_rows,
                'current_size' => $this->current_size,
                'files'        => $this->files,
                'total_rows'   => $this->total_rows,
            );
        }

        /**
         * Flushes buffered rows and closes handles without finalizing the writer.
         *
         * @return void
         */
        public function pause() {
            $this->flush_buffer( true );
            $this->close_handle();
        }

        /**
         * Returns the total number of data rows written.
         *
         * @return int
         */
        public function get_total_rows_written() {
            return $this->total_rows;
        }

        /**
         * Writes a product package (product, variants, images) to the CSV stream.
         *
         * @param array $package Product package returned from the product source.
         *
         * @return bool
         */
        public function write_product_package( array $package ) {
            if ( $this->last_error instanceof WP_Error ) {
                return false;
            }

            $rows = $this->build_rows_from_package( $package );

            foreach ( $rows as $row ) {
                if ( ! $this->add_row( $row ) ) {
                    return false;
                }
            }

            return true;
        }

        /**
         * Adds a single row to the buffer and flushes if necessary.
         *
         * @param array $row Row data keyed by column names.
         *
         * @return bool
         */
        public function add_row( array $row ) {
            if ( $this->last_error instanceof WP_Error ) {
                return false;
            }

            $this->buffer[] = $row;

            if ( count( $this->buffer ) >= $this->options['batch_size'] ) {
                return $this->flush_buffer();
            }

            return true;
        }

        /**
         * Flushes any buffered rows to disk.
         *
         * @param bool $force Whether to force flush even if buffer is below batch size.
         *
         * @return bool
         */
        public function flush_buffer( $force = false ) {
            if ( empty( $this->buffer ) ) {
                return true;
            }

            if ( ! $force && count( $this->buffer ) < $this->options['batch_size'] ) {
                return true;
            }

            if ( ! $this->ensure_file_handle() ) {
                return false;
            }

            foreach ( $this->buffer as $row ) {
                if ( ! $this->write_row_to_file( $row ) ) {
                    $this->buffer = array();
                    return false;
                }
            }

            $this->buffer = array();

            return true;
        }

        /**
         * Finalizes the writer by flushing buffers and closing handles.
         *
         * @return bool
         */
        public function finish() {
            if ( ! $this->file_handle && empty( $this->files ) ) {
                if ( ! $this->ensure_file_handle() ) {
                    return false;
                }
            }

            if ( ! $this->flush_buffer( true ) ) {
                $this->close_handle();
                return false;
            }

            $this->close_handle();

            return ! ( $this->last_error instanceof WP_Error );
        }

        /**
         * Builds rows for a product package.
         *
         * @param array $package Product package with product, variants, and images arrays.
         *
         * @return array
         */
        protected function build_rows_from_package( array $package ) {
            $rows        = array();
            $product_row = isset( $package['product'] ) && is_array( $package['product'] ) ? $package['product'] : array();
            $variants    = isset( $package['variants'] ) && is_array( $package['variants'] ) ? $package['variants'] : array();
            $images      = isset( $package['images'] ) && is_array( $package['images'] ) ? $package['images'] : array();

            if ( empty( $variants ) ) {
                $variants = array(
                    array(
                        'Handle' => isset( $product_row['Handle'] ) ? $product_row['Handle'] : '',
                    ),
                );
            }

            if ( ! $this->options['include_variants'] && count( $variants ) > 1 ) {
                $variants = array( reset( $variants ) );
            }

            foreach ( array_values( $variants ) as $index => $variant ) {
                $rows[] = $this->merge_product_and_variant_row( $product_row, $variant, $index );
            }

            if ( $this->options['include_images'] && ! empty( $images ) ) {
                foreach ( $images as $image ) {
                    $rows[] = $this->merge_product_and_image_row( $product_row, $image );
                }
            }

            return $rows;
        }

        /**
         * Combines product and variant information into a single CSV row.
         *
         * @param array $product Product level columns.
         * @param array $variant Variant level columns.
         * @param int   $index   Variant position.
         *
         * @return array
         */
        protected function merge_product_and_variant_row( array $product, array $variant, $index ) {
            $row = $this->empty_row();

            if ( 0 === $index ) {
                $row = $this->fill_row_values( $row, $product );
            } else {
                if ( isset( $product['Handle'] ) ) {
                    $row['Handle'] = $product['Handle'];
                }
            }

            $row = $this->fill_row_values( $row, $variant );

            if ( ! $this->options['include_inventory'] ) {
                $row['Variant Inventory Qty'] = '';
                $row['Inventory Policy']      = '';
                $row['Inventory Tracker']     = '';
            }

            if ( empty( $row['Handle'] ) && isset( $product['Handle'] ) ) {
                $row['Handle'] = $product['Handle'];
            }

            return $row;
        }

        /**
         * Combines product and image information into a CSV row.
         *
         * @param array $product Product level columns.
         * @param array $image   Image row columns.
         *
         * @return array
         */
        protected function merge_product_and_image_row( array $product, array $image ) {
            $row = $this->empty_row();

            if ( isset( $product['Handle'] ) ) {
                $row['Handle'] = $product['Handle'];
            }

            $row = $this->fill_row_values( $row, $image );

            if ( empty( $row['Handle'] ) && isset( $image['Handle'] ) ) {
                $row['Handle'] = $image['Handle'];
            }

            return $row;
        }

        /**
         * Ensures the output directory exists.
         *
         * @return bool
         */
        protected function ensure_output_directory() {
            if ( empty( $this->options['output_dir'] ) ) {
                $uploads = wp_upload_dir();

                if ( ! empty( $uploads['error'] ) ) {
                    $this->last_error = new WP_Error( 'wse_upload_dir_error', $uploads['error'] );
                    return false;
                }

                $directory = trailingslashit( $uploads['basedir'] ) . 'woo-to-shopify-exporter';
                $url       = trailingslashit( $uploads['baseurl'] ) . 'woo-to-shopify-exporter';

                if ( ! wp_mkdir_p( $directory ) ) {
                    $this->last_error = new WP_Error( 'wse_directory_unwritable', __( 'Unable to create export directory.', 'woo-to-shopify-exporter' ) );
                    return false;
                }

                $this->options['output_dir'] = $directory;
                $this->options['base_url']   = $url;
            } else {
                if ( ! wp_mkdir_p( $this->options['output_dir'] ) ) {
                    $this->last_error = new WP_Error( 'wse_directory_unwritable', __( 'Unable to create export directory.', 'woo-to-shopify-exporter' ) );
                    return false;
                }
            }

            return true;
        }

        /**
         * Ensures a writable file handle is available.
         *
         * @return bool
         */
        protected function ensure_file_handle() {
            if ( $this->file_handle ) {
                return true;
            }

            if ( $this->resume_state ) {
                $state = $this->resume_state;
                $path  = isset( $state['current_path'] ) ? $state['current_path'] : '';

                if ( $path && file_exists( $path ) && is_writable( $path ) ) {
                    $handle = fopen( $path, 'ab' );

                    if ( $handle ) {
                        $this->file_handle  = $handle;
                        $this->current_path = $path;
                        $this->current_url  = ! empty( $state['current_url'] ) ? $state['current_url'] : $this->build_file_url( basename( $path ) );
                        $this->current_rows = isset( $state['current_rows'] ) ? (int) $state['current_rows'] : 0;
                        $this->current_size = isset( $state['current_size'] ) && $state['current_size'] > 0 ? (int) $state['current_size'] : filesize( $path );
                        $this->file_index   = isset( $state['file_index'] ) && $state['file_index'] > 0 ? (int) $state['file_index'] : ( ! empty( $this->files ) ? max( array_keys( $this->files ) ) : 1 );

                        if ( empty( $this->files[ $this->file_index ] ) ) {
                            $this->files[ $this->file_index ] = array(
                                'path'     => $this->current_path,
                                'url'      => $this->current_url,
                                'filename' => basename( $this->current_path ),
                                'rows'     => $this->current_rows,
                                'size'     => $this->current_size,
                            );
                        }

                        $this->resume_state = null;

                        return true;
                    }
                }

                $this->resume_state = null;
            }

            if ( ! $this->ensure_output_directory() ) {
                return false;
            }

            $this->file_index++; // 1-based.

            $filename = $this->build_filename( $this->file_index );
            $path     = trailingslashit( $this->options['output_dir'] ) . $filename;
            $handle   = fopen( $path, 'wb' );

            if ( ! $handle ) {
                $this->last_error = new WP_Error( 'wse_file_open_failed', __( 'Unable to open export file for writing.', 'woo-to-shopify-exporter' ) );
                return false;
            }

            $this->file_handle  = $handle;
            $this->current_path = $path;
            $this->current_url  = $this->build_file_url( $filename );
            $this->current_size = 0;
            $this->current_rows = 0;

            $this->files[ $this->file_index ] = array(
                'path'     => $this->current_path,
                'url'      => $this->current_url,
                'filename' => $filename,
                'rows'     => 0,
                'size'     => 0,
            );

            if ( false === fputcsv( $this->file_handle, $this->columns, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape_char'] ) ) {
                $this->last_error = new WP_Error( 'wse_header_write_failed', __( 'Unable to write CSV header.', 'woo-to-shopify-exporter' ) );
                $this->close_handle();
                return false;
            }

            $position = ftell( $this->file_handle );
            if ( false !== $position ) {
                $this->current_size = (int) $position;
                $this->files[ $this->file_index ]['size'] = $this->current_size;
            }

            return true;
        }

        /**
         * Writes a normalized row to the active CSV file.
         *
         * @param array $row Row data.
         *
         * @return bool
         */
        protected function write_row_to_file( array $row ) {
            if ( ! $this->file_handle && ! $this->ensure_file_handle() ) {
                return false;
            }

            $normalized = $this->normalize_row( $row );
            $row_bytes  = $this->estimate_row_size( $normalized );

            if ( $this->should_rotate_file( $row_bytes ) ) {
                $this->rotate_file();

                if ( ! $this->file_handle && ! $this->ensure_file_handle() ) {
                    return false;
                }
            }

            if ( false === fputcsv( $this->file_handle, $normalized, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape_char'] ) ) {
                $this->last_error = new WP_Error( 'wse_row_write_failed', __( 'Unable to write export row to disk.', 'woo-to-shopify-exporter' ) );
                return false;
            }

            $this->total_rows++;
            $this->current_rows++;
            $position = ftell( $this->file_handle );

            if ( false !== $position ) {
                $this->current_size = (int) $position;
            } else {
                $this->current_size += $row_bytes;
            }

            $this->files[ $this->file_index ]['rows'] = $this->current_rows;
            $this->files[ $this->file_index ]['size'] = $this->current_size;

            return true;
        }

        /**
         * Determines whether the current file should rotate based on the next row size.
         *
         * @param int $row_bytes Estimated bytes for the next row.
         *
         * @return bool
         */
        protected function should_rotate_file( $row_bytes ) {
            if ( $this->options['max_file_size'] <= 0 ) {
                return false;
            }

            if ( ! $this->file_handle ) {
                return false;
            }

            if ( $this->current_rows <= 0 ) {
                return false;
            }

            return ( $this->current_size + (int) $row_bytes ) > $this->options['max_file_size'];
        }

        /**
         * Rotates the writer to a new file.
         *
         * @return void
         */
        protected function rotate_file() {
            $this->close_handle();
            $this->ensure_file_handle();
        }

        /**
         * Closes the current file handle.
         *
         * @return void
         */
        protected function close_handle() {
            if ( $this->file_handle ) {
                fclose( $this->file_handle );
                $this->file_handle = null;
            }
        }

        /**
         * Builds a sanitized filename for the given index.
         *
         * @param int $index File index (1-based).
         *
         * @return string
         */
        protected function build_filename( $index ) {
            $base_name = $this->options['file_name'];
            $extension = pathinfo( $base_name, PATHINFO_EXTENSION );
            $stem      = pathinfo( $base_name, PATHINFO_FILENAME );

            if ( empty( $extension ) ) {
                $extension = 'csv';
            }

            if ( empty( $stem ) ) {
                $stem = 'shopify-products';
            }

            if ( 1 === (int) $index ) {
                $filename = $stem . '.' . $extension;
            } else {
                $filename = sprintf( '%s-part-%d.%s', $stem, (int) $index, $extension );
            }

            return sanitize_file_name( $filename );
        }

        /**
         * Resolves the URL for the given filename.
         *
         * @param string $filename File name.
         *
         * @return string
         */
        protected function build_file_url( $filename ) {
            if ( empty( $this->options['base_url'] ) ) {
                $this->ensure_output_directory();
            }

            if ( empty( $this->options['base_url'] ) ) {
                return '';
            }

            return trailingslashit( $this->options['base_url'] ) . $filename;
        }

        /**
         * Creates an empty row array seeded with header columns.
         *
         * @return array
         */
        protected function empty_row() {
            return array_fill_keys( $this->columns, '' );
        }

        /**
         * Fills a row with provided values while respecting known columns.
         *
         * @param array $row    Base row.
         * @param array $values Values to merge.
         *
         * @return array
         */
        protected function fill_row_values( array $row, array $values ) {
            foreach ( $values as $column => $value ) {
                if ( array_key_exists( $column, $row ) ) {
                    $row[ $column ] = $this->normalize_value( $value );
                }
            }

            return $row;
        }

        /**
         * Normalizes a row to ensure all columns exist.
         *
         * @param array $row Row values keyed by column name.
         *
         * @return array
         */
        protected function normalize_row( array $row ) {
            $normalized = $this->empty_row();

            foreach ( $row as $column => $value ) {
                if ( array_key_exists( $column, $normalized ) ) {
                    $normalized[ $column ] = $this->normalize_value( $value );
                }
            }

            return $normalized;
        }

        /**
         * Normalizes a scalar value for CSV output.
         *
         * @param mixed $value Value to normalize.
         *
         * @return string
         */
        protected function normalize_value( $value ) {
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

            return $value;
        }

        /**
         * Estimates the number of bytes a row will use on disk.
         *
         * @param array $row Normalized row.
         *
         * @return int
         */
        protected function estimate_row_size( array $row ) {
            $temp = fopen( 'php://temp', 'wb+' );

            if ( ! $temp ) {
                return 0;
            }

            fputcsv( $temp, $row, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape_char'] );
            $position = ftell( $temp );
            fclose( $temp );

            return $position ? (int) $position : 0;
        }
    }
}
