<?php
/** @var array<string, mixed> $viewData */
$settings = $viewData['settings'] ?? [];
$state = is_array($viewData['state'] ?? null) ? $viewData['state'] : [];
$failures = is_array($viewData['failures'] ?? null) ? $viewData['failures'] : [];
$csvUrl = $viewData['csv_url'] ?? null;
$logUrl = $viewData['log_url'] ?? null;
$failuresUrl = $viewData['failures_url'] ?? null;
?>
<div class="wrap wse-export-page">
    <h1><?php esc_html_e('Woo to Shopify Exporter', 'woo-to-shopify-exporter'); ?></h1>

    <?php settings_errors('wse_settings'); ?>

    <ol class="wse-steps">
        <li class="wse-step">
            <h2><?php esc_html_e('Step 1 – Configure exporter options', 'woo-to-shopify-exporter'); ?></h2>
            <p><?php esc_html_e('Choose whether to copy product images into the export bundle before running an export.', 'woo-to-shopify-exporter'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('wse_settings_group'); ?>
                <?php do_settings_sections('wse_settings_group'); ?>
                <?php submit_button(__('Save settings', 'woo-to-shopify-exporter')); ?>
            </form>
        </li>
        <li class="wse-step">
            <h2><?php esc_html_e('Step 2 – Validate prerequisites', 'woo-to-shopify-exporter'); ?></h2>
            <p><?php esc_html_e('Ensure WooCommerce is active, products have slugs, and large catalogs are ready for streaming export.', 'woo-to-shopify-exporter'); ?></p>
        </li>
        <li class="wse-step">
            <h2><?php esc_html_e('Step 3 – Run export', 'woo-to-shopify-exporter'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wse-export-form">
                <?php wp_nonce_field('wse_run_export'); ?>
                <input type="hidden" name="action" value="wse_run_export" />
                <label class="wse-restart-checkbox">
                    <input type="checkbox" name="wse_force_restart" value="1" />
                    <?php esc_html_e('Restart export from scratch and discard the previous checkpoint.', 'woo-to-shopify-exporter'); ?>
                </label>
                <?php submit_button(__('Export products to CSV', 'woo-to-shopify-exporter'), 'primary', 'submit', false); ?>
            </form>
        </li>
        <li class="wse-step">
            <h2><?php esc_html_e('Step 4 – Review results and download files', 'woo-to-shopify-exporter'); ?></h2>
            <table class="widefat fixed">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Last product ID', 'woo-to-shopify-exporter'); ?></th>
                        <td><?php echo esc_html((string) ($state['last_product_id'] ?? '—')); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Products exported', 'woo-to-shopify-exporter'); ?></th>
                        <td><?php echo esc_html((string) ($state['total_products'] ?? 0)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Variants exported', 'woo-to-shopify-exporter'); ?></th>
                        <td><?php echo esc_html((string) ($state['total_variants'] ?? 0)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Job started at', 'woo-to-shopify-exporter'); ?></th>
                        <td>
                            <?php
                            $started = $state['started_at'] ?? null;
                            if (is_int($started)) {
                                echo esc_html(wp_date('Y-m-d H:i:s', $started));
                            } else {
                                esc_html_e('Not started', 'woo-to-shopify-exporter');
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="wse-downloads">
                <h3><?php esc_html_e('Download artifacts', 'woo-to-shopify-exporter'); ?></h3>
                <ul>
                    <li>
                        <?php if ($csvUrl) : ?>
                            <a href="<?php echo esc_url($csvUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download shopify-products.csv', 'woo-to-shopify-exporter'); ?></a>
                        <?php else : ?>
                            <?php esc_html_e('CSV file not generated yet.', 'woo-to-shopify-exporter'); ?>
                        <?php endif; ?>
                    </li>
                    <li>
                        <?php if ($logUrl) : ?>
                            <a href="<?php echo esc_url($logUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View job.log', 'woo-to-shopify-exporter'); ?></a>
                        <?php else : ?>
                            <?php esc_html_e('Log file will appear after the first run.', 'woo-to-shopify-exporter'); ?>
                        <?php endif; ?>
                    </li>
                    <li>
                        <?php if ($failuresUrl) : ?>
                            <a href="<?php echo esc_url($failuresUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download failures.json', 'woo-to-shopify-exporter'); ?></a>
                        <?php else : ?>
                            <?php esc_html_e('No failures logged yet.', 'woo-to-shopify-exporter'); ?>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <?php if (!empty($failures)) : ?>
                <div class="notice notice-warning">
                    <p><?php printf(esc_html__('%d products have failed validation. Check failures.json for the full list.', 'woo-to-shopify-exporter'), count($failures)); ?></p>
                </div>
            <?php endif; ?>
        </li>
    </ol>
</div>
