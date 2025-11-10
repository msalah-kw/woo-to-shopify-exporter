<?php
/**
 * Admin export page rendering and request handling.
 *
 * @package WooToShopifyExporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the export wizard page.
 *
 * @return void
 */
function wse_render_export_page() {
    if ( ! current_user_can( wse_get_admin_capability() ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-to-shopify-exporter' ) );
    }

    $saved_settings = wse_get_saved_export_settings();
    $default_settings = wse_get_default_export_settings();
    $active_job = wse_get_active_job();

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wse_export_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( check_admin_referer( 'wse_export_start', 'wse_export_nonce' ) ) {
            $submitted_settings = wse_sanitize_export_settings( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            wse_store_export_settings( $submitted_settings );
            $saved_settings = $submitted_settings;

            add_settings_error(
                'wse_export',
                'wse-settings-saved',
                __( 'Export preferences saved. You can start the export when you are ready.', 'woo-to-shopify-exporter' ),
                'updated'
            );
        } else {
            add_settings_error(
                'wse_export',
                'wse-settings-invalid-nonce',
                __( 'The request could not be verified. Please try again.', 'woo-to-shopify-exporter' ),
                'error'
            );
        }
    }

    $settings = wp_parse_args( $saved_settings, $default_settings );
    $categories = wse_get_product_categories();
    $tags       = wse_get_product_tags();
    $statuses   = wse_get_product_statuses();
    ?>
    <div class="wrap wse-export-page">
        <h1><?php esc_html_e( 'Woo to Shopify Exporter', 'woo-to-shopify-exporter' ); ?></h1>

        <?php settings_errors( 'wse_export' ); ?>

        <form method="post" id="wse-export-form">
            <?php wp_nonce_field( 'wse_export_start', 'wse_export_nonce' ); ?>

            <ol class="wse-steps" data-total="4">
                <li class="wse-step is-active" data-step="1">
                    <header>
                        <span class="wse-step-index">1</span>
                        <h2><?php esc_html_e( 'Choose export scope', 'woo-to-shopify-exporter' ); ?></h2>
                        <p><?php esc_html_e( 'Decide which products should be included in this export run.', 'woo-to-shopify-exporter' ); ?></p>
                    </header>

                    <fieldset class="wse-fieldset">
                        <legend class="screen-reader-text"><?php esc_html_e( 'Export scope', 'woo-to-shopify-exporter' ); ?></legend>
                        <label class="wse-radio">
                            <input type="radio" name="export_scope" value="all" <?php checked( $settings['export_scope'], 'all' ); ?> />
                            <span><?php esc_html_e( 'All products', 'woo-to-shopify-exporter' ); ?></span>
                        </label>

                        <label class="wse-radio">
                            <input type="radio" name="export_scope" value="category" <?php checked( $settings['export_scope'], 'category' ); ?> />
                            <span><?php esc_html_e( 'Specific categories', 'woo-to-shopify-exporter' ); ?></span>
                        </label>
                        <div class="wse-scope-extended" data-scope="category">
                            <?php if ( ! empty( $categories ) ) : ?>
                                <label for="wse-scope-categories" class="wse-field-label"><?php esc_html_e( 'Product categories', 'woo-to-shopify-exporter' ); ?></label>
                                <select id="wse-scope-categories" name="scope_categories[]" multiple size="6" class="wse-select">
                                    <?php
                                    $selected_categories = array_map( 'intval', $settings['scope_categories'] );
                                    foreach ( $categories as $category ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $category['id'] ); ?>" <?php selected( in_array( (int) $category['id'], $selected_categories, true ) ); ?>><?php echo esc_html( $category['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Hold Ctrl or Cmd to select multiple categories.', 'woo-to-shopify-exporter' ); ?></p>
                            <?php else : ?>
                                <p class="description">&mdash; <?php esc_html_e( 'No categories were found.', 'woo-to-shopify-exporter' ); ?></p>
                            <?php endif; ?>
                        </div>

                        <label class="wse-radio">
                            <input type="radio" name="export_scope" value="tag" <?php checked( $settings['export_scope'], 'tag' ); ?> />
                            <span><?php esc_html_e( 'Specific tags', 'woo-to-shopify-exporter' ); ?></span>
                        </label>
                        <div class="wse-scope-extended" data-scope="tag">
                            <?php if ( ! empty( $tags ) ) : ?>
                                <label for="wse-scope-tags" class="wse-field-label"><?php esc_html_e( 'Product tags', 'woo-to-shopify-exporter' ); ?></label>
                                <select id="wse-scope-tags" name="scope_tags[]" multiple size="6" class="wse-select">
                                    <?php
                                    $selected_tags = array_map( 'intval', $settings['scope_tags'] );
                                    foreach ( $tags as $tag ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $tag['id'] ); ?>" <?php selected( in_array( (int) $tag['id'], $selected_tags, true ) ); ?>><?php echo esc_html( $tag['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select one or more tags that should be included.', 'woo-to-shopify-exporter' ); ?></p>
                            <?php else : ?>
                                <p class="description">&mdash; <?php esc_html_e( 'No product tags were found.', 'woo-to-shopify-exporter' ); ?></p>
                            <?php endif; ?>
                        </div>

                        <label class="wse-radio">
                            <input type="radio" name="export_scope" value="status" <?php checked( $settings['export_scope'], 'status' ); ?> />
                            <span><?php esc_html_e( 'Filter by product status', 'woo-to-shopify-exporter' ); ?></span>
                        </label>
                        <div class="wse-scope-extended" data-scope="status">
                            <label for="wse-scope-status" class="wse-field-label"><?php esc_html_e( 'Statuses to include', 'woo-to-shopify-exporter' ); ?></label>
                            <select id="wse-scope-status" name="scope_status[]" multiple size="4" class="wse-select">
                                <?php foreach ( $statuses as $status_key => $status_label ) : ?>
                                    <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( in_array( $status_key, $settings['scope_status'], true ) ); ?>><?php echo esc_html( $status_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Only products matching these statuses will be exported.', 'woo-to-shopify-exporter' ); ?></p>
                        </div>
                    </fieldset>

                    <footer class="wse-step-navigation">
                        <button type="button" class="button button-primary wse-next-step" data-next="2"><?php esc_html_e( 'Continue to presets', 'woo-to-shopify-exporter' ); ?></button>
                    </footer>
                </li>

                <li class="wse-step" data-step="2">
                    <header>
                        <span class="wse-step-index">2</span>
                        <h2><?php esc_html_e( 'Select field mapping preset', 'woo-to-shopify-exporter' ); ?></h2>
                        <p><?php esc_html_e( 'Pick the field layout that matches the Shopify import template you plan to use.', 'woo-to-shopify-exporter' ); ?></p>
                    </header>

                    <div class="wse-fieldset">
                        <label for="wse-field-preset" class="wse-field-label"><?php esc_html_e( 'Preset', 'woo-to-shopify-exporter' ); ?></label>
                        <select id="wse-field-preset" name="field_preset" class="wse-select">
                            <option value="shopify-default" <?php selected( $settings['field_preset'], 'shopify-default' ); ?>><?php esc_html_e( 'Shopify default (recommended)', 'woo-to-shopify-exporter' ); ?></option>
                            <option value="minimal" <?php selected( $settings['field_preset'], 'minimal' ); ?>><?php esc_html_e( 'Minimal product export', 'woo-to-shopify-exporter' ); ?></option>
                            <option value="extended" <?php selected( $settings['field_preset'], 'extended' ); ?>><?php esc_html_e( 'Extended with metafields', 'woo-to-shopify-exporter' ); ?></option>
                            <option value="custom" <?php selected( $settings['field_preset'], 'custom' ); ?>><?php esc_html_e( 'Custom mapping', 'woo-to-shopify-exporter' ); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Presets determine which WooCommerce product attributes map to Shopify columns. Choose “Custom mapping” to tailor the CSV headers.', 'woo-to-shopify-exporter' ); ?>
                        </p>
                    </div>

                    <div class="wse-fieldset wse-custom-mapping" data-visible-when="custom">
                        <label for="wse-custom-fields" class="wse-field-label"><?php esc_html_e( 'Custom field list', 'woo-to-shopify-exporter' ); ?></label>
                        <textarea id="wse-custom-fields" name="custom_fields" rows="5" class="wse-textarea" placeholder="Handle,Title,Body (HTML),Vendor,Type,Tags,Published"><?php echo esc_textarea( $settings['custom_fields'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Provide a comma-separated list of Shopify columns in the order you would like to export them.', 'woo-to-shopify-exporter' ); ?></p>
                    </div>

                    <footer class="wse-step-navigation">
                        <button type="button" class="button button-secondary wse-previous-step" data-previous="1"><?php esc_html_e( 'Back', 'woo-to-shopify-exporter' ); ?></button>
                        <button type="button" class="button button-primary wse-next-step" data-next="3"><?php esc_html_e( 'Continue to output options', 'woo-to-shopify-exporter' ); ?></button>
                    </footer>
                </li>

                <li class="wse-step" data-step="3">
                    <header>
                        <span class="wse-step-index">3</span>
                        <h2><?php esc_html_e( 'Configure output options', 'woo-to-shopify-exporter' ); ?></h2>
                        <p><?php esc_html_e( 'Choose how your export file should be generated.', 'woo-to-shopify-exporter' ); ?></p>
                    </header>

                    <div class="wse-fieldset">
                        <label class="wse-checkbox">
                            <input type="checkbox" name="include_images" value="1" <?php checked( $settings['include_images'], true ); ?> />
                            <span><?php esc_html_e( 'Include product gallery images', 'woo-to-shopify-exporter' ); ?></span>
                        </label>

                        <label class="wse-checkbox">
                            <input type="checkbox" name="include_inventory" value="1" <?php checked( $settings['include_inventory'], true ); ?> />
                            <span><?php esc_html_e( 'Include inventory counts', 'woo-to-shopify-exporter' ); ?></span>
                        </label>

                        <label class="wse-checkbox">
                            <input type="checkbox" name="include_variations" value="1" <?php checked( $settings['include_variations'], true ); ?> />
                            <span><?php esc_html_e( 'Include variation-level rows', 'woo-to-shopify-exporter' ); ?></span>
                        </label>
                    </div>

                    <div class="wse-fieldset wse-columns">
                        <div class="wse-column">
                            <label for="wse-output-format" class="wse-field-label"><?php esc_html_e( 'File format', 'woo-to-shopify-exporter' ); ?></label>
                            <select id="wse-output-format" name="file_format" class="wse-select">
                                <option value="csv" <?php selected( $settings['file_format'], 'csv' ); ?>><?php esc_html_e( 'CSV (Shopify default)', 'woo-to-shopify-exporter' ); ?></option>
                                <option value="tsv" <?php selected( $settings['file_format'], 'tsv' ); ?>><?php esc_html_e( 'TSV (tab separated)', 'woo-to-shopify-exporter' ); ?></option>
                                <option value="json" <?php selected( $settings['file_format'], 'json' ); ?>><?php esc_html_e( 'JSON (debug)', 'woo-to-shopify-exporter' ); ?></option>
                            </select>
                        </div>
                        <div class="wse-column">
                            <label for="wse-output-delimiter" class="wse-field-label"><?php esc_html_e( 'Delimiter', 'woo-to-shopify-exporter' ); ?></label>
                            <select id="wse-output-delimiter" name="delimiter" class="wse-select">
                                <option value="," <?php selected( $settings['delimiter'], ',' ); ?>><?php esc_html_e( 'Comma (,)', 'woo-to-shopify-exporter' ); ?></option>
                                <option value=";" <?php selected( $settings['delimiter'], ';' ); ?>><?php esc_html_e( 'Semicolon (;)', 'woo-to-shopify-exporter' ); ?></option>
                                <option value="\t" <?php selected( $settings['delimiter'], "\t" ); ?>><?php esc_html_e( 'Tab', 'woo-to-shopify-exporter' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="wse-fieldset">
                        <label for="wse-output-filename" class="wse-field-label"><?php esc_html_e( 'File name', 'woo-to-shopify-exporter' ); ?></label>
                        <input type="text" id="wse-output-filename" name="file_name" class="regular-text" value="<?php echo esc_attr( $settings['file_name'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'The generated export file will use this name.', 'woo-to-shopify-exporter' ); ?></p>
                    </div>

                    <footer class="wse-step-navigation">
                        <button type="button" class="button button-secondary wse-previous-step" data-previous="2"><?php esc_html_e( 'Back', 'woo-to-shopify-exporter' ); ?></button>
                        <button type="button" class="button button-primary wse-next-step" data-next="4"><?php esc_html_e( 'Review and export', 'woo-to-shopify-exporter' ); ?></button>
                    </footer>
                </li>

                <li class="wse-step" data-step="4">
                    <header>
                        <span class="wse-step-index">4</span>
                        <h2><?php esc_html_e( 'Review and launch export', 'woo-to-shopify-exporter' ); ?></h2>
                        <p><?php esc_html_e( 'Double-check your selections before starting the export process.', 'woo-to-shopify-exporter' ); ?></p>
                    </header>

                    <div class="wse-review" id="wse-review-summary" aria-live="polite">
                        <p><?php esc_html_e( 'Your export summary will appear here once all steps are completed.', 'woo-to-shopify-exporter' ); ?></p>
                    </div>

                    <footer class="wse-step-navigation">
                        <button type="button" class="button button-secondary wse-previous-step" data-previous="3"><?php esc_html_e( 'Back', 'woo-to-shopify-exporter' ); ?></button>
                        <button type="submit" class="button button-primary wse-start-export"><?php esc_html_e( 'Start export', 'woo-to-shopify-exporter' ); ?></button>
                    </footer>
                </li>
            </ol>
        </form>

        <section class="wse-progress-panel" id="wse-progress-panel" data-active-job='<?php echo esc_attr( wp_json_encode( $active_job ) ); ?>'>
            <header>
                <h2><?php esc_html_e( 'Export progress', 'woo-to-shopify-exporter' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Track the export job in real time or resume a paused run.', 'woo-to-shopify-exporter' ); ?></p>
            </header>

            <div class="wse-progress-status" data-status><?php echo esc_html( wse_describe_job_status( $active_job ) ); ?></div>

            <div class="wse-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( isset( $active_job['progress'] ) ? (int) $active_job['progress'] : 0 ); ?>">
                <span class="wse-progress-fill" style="width: <?php echo esc_attr( isset( $active_job['progress'] ) ? (int) $active_job['progress'] : 0 ); ?>%"></span>
            </div>

            <p class="wse-progress-message" data-message>
                <?php echo esc_html( isset( $active_job['message'] ) ? $active_job['message'] : __( 'No export is currently running.', 'woo-to-shopify-exporter' ) ); ?>
            </p>

            <dl class="wse-progress-meta">
                <div>
                    <dt><?php esc_html_e( 'Job ID', 'woo-to-shopify-exporter' ); ?></dt>
                    <dd data-job-id><?php echo ! empty( $active_job['id'] ) ? esc_html( $active_job['id'] ) : '&mdash;'; ?></dd>
                </div>
                <div>
                    <dt><?php esc_html_e( 'Last updated', 'woo-to-shopify-exporter' ); ?></dt>
                    <dd data-last-updated><?php echo ! empty( $active_job['last_updated'] ) ? esc_html( wse_format_datetime( $active_job['last_updated'] ) ) : '&mdash;'; ?></dd>
                </div>
            </dl>

            <div class="wse-progress-actions">
                <button type="button" class="button button-secondary wse-resume-export" <?php disabled( empty( $active_job ) ); ?>><?php esc_html_e( 'Resume export', 'woo-to-shopify-exporter' ); ?></button>
                <button type="button" class="button button-secondary wse-refresh-progress"><?php esc_html_e( 'Refresh status', 'woo-to-shopify-exporter' ); ?></button>
                <a href="<?php echo ! empty( $active_job['download_url'] ) ? esc_url( $active_job['download_url'] ) : '#'; ?>" class="button button-primary wse-download-export" <?php echo empty( $active_job['download_url'] ) ? 'hidden' : ''; ?>><?php esc_html_e( 'Download file', 'woo-to-shopify-exporter' ); ?></a>
            </div>
        </section>
    </div>
    <?php
}

/**
 * Provides default export settings.
 *
 * @return array
 */
function wse_get_default_export_settings() {
    return array(
        'export_scope'       => 'all',
        'scope_categories'   => array(),
        'scope_tags'         => array(),
        'scope_status'       => array( 'publish' ),
        'field_preset'       => 'shopify-default',
        'custom_fields'      => '',
        'include_images'     => true,
        'include_inventory'  => true,
        'include_variations' => true,
        'file_format'        => 'csv',
        'delimiter'          => ',',
        'file_name'          => sprintf( 'shopify-export-%s.csv', gmdate( 'Y-m-d' ) ),
    );
}

/**
 * Retrieves saved export settings.
 *
 * @return array
 */
function wse_get_saved_export_settings() {
    $settings = get_option( 'wse_export_settings', array() );

    return is_array( $settings ) ? $settings : array();
}

/**
 * Stores the export settings.
 *
 * @param array $settings Settings to persist.
 *
 * @return void
 */
function wse_store_export_settings( array $settings ) {
    update_option( 'wse_export_settings', $settings );
}

/**
 * Sanitize export settings coming from a request.
 *
 * @param array $source Raw request data.
 *
 * @return array
 */
function wse_sanitize_export_settings( array $source ) {
    $default = wse_get_default_export_settings();

    $sanitized = array();
    $sanitized['export_scope'] = isset( $source['export_scope'] ) ? sanitize_key( $source['export_scope'] ) : $default['export_scope'];
    $sanitized['scope_categories'] = array();
    if ( ! empty( $source['scope_categories'] ) && is_array( $source['scope_categories'] ) ) {
        $sanitized['scope_categories'] = array_map( 'absint', $source['scope_categories'] );
    }

    $sanitized['scope_tags'] = array();
    if ( ! empty( $source['scope_tags'] ) && is_array( $source['scope_tags'] ) ) {
        $sanitized['scope_tags'] = array_map( 'absint', $source['scope_tags'] );
    }

    $sanitized['scope_status'] = array();
    if ( ! empty( $source['scope_status'] ) && is_array( $source['scope_status'] ) ) {
        $sanitized['scope_status'] = array_map( 'sanitize_key', $source['scope_status'] );
    }

    $sanitized['field_preset'] = isset( $source['field_preset'] ) ? sanitize_key( $source['field_preset'] ) : $default['field_preset'];
    $sanitized['custom_fields'] = isset( $source['custom_fields'] ) ? wse_sanitize_textarea( $source['custom_fields'] ) : $default['custom_fields'];

    $sanitized['include_images']     = ! empty( $source['include_images'] );
    $sanitized['include_inventory']  = ! empty( $source['include_inventory'] );
    $sanitized['include_variations'] = ! empty( $source['include_variations'] );

    $allowed_formats = array( 'csv', 'tsv', 'json' );
    $format = isset( $source['file_format'] ) ? sanitize_key( $source['file_format'] ) : $default['file_format'];
    $sanitized['file_format'] = in_array( $format, $allowed_formats, true ) ? $format : $default['file_format'];

    $allowed_delimiters = array( ',', ';', "\t" );
    $delimiter = isset( $source['delimiter'] ) ? wp_unslash( $source['delimiter'] ) : $default['delimiter'];
    $sanitized['delimiter'] = in_array( $delimiter, $allowed_delimiters, true ) ? $delimiter : $default['delimiter'];

    $sanitized['file_name'] = isset( $source['file_name'] ) ? sanitize_file_name( $source['file_name'] ) : $default['file_name'];
    if ( empty( $sanitized['file_name'] ) ) {
        $sanitized['file_name'] = $default['file_name'];
    }

    return $sanitized;
}

/**
 * Sanitizes textarea content preserving commas.
 *
 * @param string $content Raw text.
 *
 * @return string
 */
function wse_sanitize_textarea( $content ) {
    $content = wp_kses_post( $content );
    $content = preg_replace( '/[\r\n]+/', ',', $content );
    $content = preg_replace( '/,{2,}/', ',', $content );

    return trim( $content );
}

/**
 * Retrieves the active export job.
 *
 * @return array
 */
function wse_get_active_job() {
    $job = get_option( 'wse_active_job', array() );

    return is_array( $job ) ? $job : array();
}

/**
 * Stores an export job as active.
 *
 * @param array $job Job payload.
 *
 * @return void
 */
function wse_store_active_job( array $job ) {
    update_option( 'wse_active_job', $job );
}

/**
 * Formats a timestamp for display.
 *
 * @param int|string $timestamp Timestamp to format.
 *
 * @return string
 */
function wse_format_datetime( $timestamp ) {
    if ( empty( $timestamp ) ) {
        return '';
    }

    $timestamp = is_numeric( $timestamp ) ? (int) $timestamp : strtotime( (string) $timestamp );

    if ( ! $timestamp ) {
        return '';
    }

    return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Returns product categories with id and name.
 *
 * @return array
 */
function wse_get_product_categories() {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return array();
    }

    $terms = get_terms(
        array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'number'     => 200,
        )
    );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return array();
    }

    return array_map(
        static function ( $term ) {
            return array(
                'id'   => (int) $term->term_id,
                'name' => $term->name,
            );
        },
        $terms
    );
}

/**
 * Returns product tags with id and name.
 *
 * @return array
 */
function wse_get_product_tags() {
    if ( ! taxonomy_exists( 'product_tag' ) ) {
        return array();
    }

    $terms = get_terms(
        array(
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
            'number'     => 200,
        )
    );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return array();
    }

    return array_map(
        static function ( $term ) {
            return array(
                'id'   => (int) $term->term_id,
                'name' => $term->name,
            );
        },
        $terms
    );
}

/**
 * Returns relevant product statuses.
 *
 * @return array
 */
function wse_get_product_statuses() {
    $statuses = array(
        'publish' => __( 'Published', 'woo-to-shopify-exporter' ),
        'draft'   => __( 'Draft', 'woo-to-shopify-exporter' ),
        'pending' => __( 'Pending review', 'woo-to-shopify-exporter' ),
        'private' => __( 'Private', 'woo-to-shopify-exporter' ),
    );

    return apply_filters( 'wse_product_statuses', $statuses );
}

/**
 * Provides a human readable status description.
 *
 * @param array $job Job information.
 *
 * @return string
 */
function wse_describe_job_status( $job ) {
    if ( empty( $job ) || empty( $job['status'] ) ) {
        return __( 'Idle', 'woo-to-shopify-exporter' );
    }

    $statuses = array(
        'queued'    => __( 'Queued', 'woo-to-shopify-exporter' ),
        'running'   => __( 'Running', 'woo-to-shopify-exporter' ),
        'paused'    => __( 'Paused', 'woo-to-shopify-exporter' ),
        'completed' => __( 'Completed', 'woo-to-shopify-exporter' ),
        'failed'    => __( 'Failed', 'woo-to-shopify-exporter' ),
    );

    return isset( $statuses[ $job['status'] ] ) ? $statuses[ $job['status'] ] : $statuses['queued'];
}

add_action( 'wp_ajax_wse_start_export', 'wse_ajax_start_export' );
add_action( 'wp_ajax_wse_poll_export', 'wse_ajax_poll_export' );
add_action( 'wp_ajax_wse_resume_export', 'wse_ajax_resume_export' );

/**
 * Handles the AJAX request to start an export.
 *
 * @return void
 */
function wse_ajax_start_export() {
    wse_verify_ajax_request( 'wse_start_export' );

    $settings = wse_sanitize_export_settings( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    wse_store_export_settings( $settings );

    $job = array(
        'id'           => uniqid( 'wse_job_', true ),
        'status'       => 'queued',
        'progress'     => 0,
        'message'      => __( 'Export has been queued. Processing will start shortly.', 'woo-to-shopify-exporter' ),
        'created_at'   => time(),
        'last_updated' => time(),
        'settings'     => $settings,
    );

    wse_store_active_job( $job );
    $result = wse_run_export_job( $job );

    if ( is_wp_error( $result ) ) {
        $stored_job = wse_get_active_job();

        wp_send_json_error(
            array(
                'message' => $result->get_error_message(),
                'job'     => $stored_job,
            )
        );
    }

    wp_send_json_success(
        array(
            'job'      => $result,
            'settings' => $settings,
            'notice'   => isset( $result['message'] ) ? $result['message'] : __( 'Export completed successfully.', 'woo-to-shopify-exporter' ),
        )
    );
}

/**
 * Handles polling requests.
 *
 * @return void
 */
function wse_ajax_poll_export() {
    wse_verify_ajax_request( 'wse_poll_export' );

    $job = wse_get_active_job();
    if ( ! empty( $job ) ) {
        $job['last_updated'] = time();
        wse_store_active_job( $job );
    }

    wp_send_json_success(
        array(
            'job' => $job,
        )
    );
}

/**
 * Handles resume requests.
 *
 * @return void
 */
function wse_ajax_resume_export() {
    wse_verify_ajax_request( 'wse_resume_export' );

    $job = wse_get_active_job();

    if ( empty( $job ) ) {
        wp_send_json_error(
            array(
                'message' => __( 'There is no export to resume.', 'woo-to-shopify-exporter' ),
            )
        );
    }

    $job['status']       = 'queued';
    $job['message']      = __( 'Export resumed. We will continue where things left off.', 'woo-to-shopify-exporter' );
    $job['last_updated'] = time();

    wse_store_active_job( $job );

    $result = wse_run_export_job( $job );

    if ( is_wp_error( $result ) ) {
        $stored_job = wse_get_active_job();

        wp_send_json_error(
            array(
                'message' => $result->get_error_message(),
                'job'     => $stored_job,
            )
        );
    }

    wp_send_json_success(
        array(
            'job'    => $result,
            'notice' => isset( $result['message'] ) ? $result['message'] : __( 'Export job resumed successfully.', 'woo-to-shopify-exporter' ),
        )
    );
}

/**
 * Verifies AJAX requests for the exporter.
 *
 * @param string $action Action nonce name.
 *
 * @return void
 */
function wse_verify_ajax_request( $action ) {
    check_ajax_referer( $action, 'nonce' );

    if ( ! current_user_can( wse_get_admin_capability() ) ) {
        wp_send_json_error(
            array(
                'message' => __( 'You do not have permission to perform this action.', 'woo-to-shopify-exporter' ),
            ),
            403
        );
    }
}
