<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Admin;

use NSB\WooToShopify\Export\ExportContext;
use NSB\WooToShopify\Plugin;
use NSB\WooToShopify\Export\ExportJob;
use NSB\WooToShopify\Utils\Filesystem;
use Throwable;

final class ExportPage
{
    private const OPTION_NAME = 'wse_settings';
    private const SETTINGS_GROUP = 'wse_settings_group';

    public function __construct(
        private readonly ExportJob $job,
        private readonly Filesystem $filesystem
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_wse_run_export', [$this, 'handleExport']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wse-export') {
            return;
        }

        $style = plugin_dir_url(WSE_PLUGIN_FILE) . 'assets/admin/export.css';
        wp_enqueue_style('wse-exporter-admin', $style, [], Plugin::VERSION);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Woo to Shopify Exporter', 'woo-to-shopify-exporter'),
            __('Shopify Export', 'woo-to-shopify-exporter'),
            'manage_woocommerce',
            'wse-export',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => ['copy_images' => false],
            ]
        );

        add_settings_section(
            'wse_general',
            __('Export options', 'woo-to-shopify-exporter'),
            '__return_false',
            self::SETTINGS_GROUP
        );

        add_settings_field(
            'copy_images',
            __('Copy product images into export bundle', 'woo-to-shopify-exporter'),
            [$this, 'renderCopyImagesField'],
            self::SETTINGS_GROUP,
            'wse_general'
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings($input): array
    {
        $copy = is_array($input) ? $input : [];
        $copy['copy_images'] = !empty($copy['copy_images']);

        return $copy;
    }

    public function renderCopyImagesField(): void
    {
        $settings = $this->getSettings();
        $checked = !empty($settings['copy_images']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[copy_images]" value="1" ' . $checked . '> ';
        esc_html_e('Include physical image files alongside the CSV export.', 'woo-to-shopify-exporter');
        echo '</label>';
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'woo-to-shopify-exporter'));
        }

        $settings = $this->getSettings();
        $context = $this->buildContext();
        $state = $this->filesystem->readJson($context->getStatePath());
        $failures = $this->filesystem->readJson($context->getFailuresPath());
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => '', 'baseurl' => ''];

        $viewData = [
            'settings' => $settings,
            'context' => $context,
            'state' => $state,
            'csv_path' => $context->getCsvPath(),
            'csv_url' => $this->pathToUrl($context->getCsvPath(), $uploads),
            'log_path' => $context->getLogPath(),
            'log_url' => $this->pathToUrl($context->getLogPath(), $uploads),
            'failures_path' => $context->getFailuresPath(),
            'failures_url' => $this->pathToUrl($context->getFailuresPath(), $uploads),
            'failures' => is_array($failures) ? $failures : [],
        ];

        $view = plugin_dir_path(WSE_PLUGIN_FILE) . 'views/admin/export-page.php';
        if (is_file($view)) {
            /** @psalm-suppress UnresolvableInclude */
            require $view;
        }
    }

    public function handleExport(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to run exports.', 'woo-to-shopify-exporter'));
        }

        check_admin_referer('wse_run_export');

        $settings = $this->getSettings();
        $copyImages = !empty($settings['copy_images']);
        $forceRestart = !empty($_POST['wse_force_restart']);

        $context = $this->buildContext();

        try {
            $result = $this->job->run($context, [
                'copy_images' => $copyImages,
                'force_restart' => $forceRestart,
            ]);

            $message = sprintf(
                /* translators: 1: product count, 2: variant count */
                __('Exported %1$d products and %2$d variants.', 'woo-to-shopify-exporter'),
                $result->getProducts(),
                $result->getVariants()
            );
            add_settings_error(self::OPTION_NAME, 'wse_success', $message, 'updated');

            if ($result->wasResumed()) {
                add_settings_error(
                    self::OPTION_NAME,
                    'wse_resumed',
                    __('The export resumed from a previous checkpoint.', 'woo-to-shopify-exporter'),
                    'notice-warning'
                );
            }

            foreach ($result->getWarnings() as $warning) {
                add_settings_error(self::OPTION_NAME, 'wse_warning_' . md5($warning), $warning, 'notice-warning');
            }

            $failures = $result->getFailures();
            if (!empty($failures)) {
                add_settings_error(
                    self::OPTION_NAME,
                    'wse_failures',
                    sprintf(
                        /* translators: %d failure count */
                        __('%d products failed validation. See failures.json for details.', 'woo-to-shopify-exporter'),
                        count($failures)
                    ),
                    'error'
                );
            }
        } catch (Throwable $exception) {
            add_settings_error(
                self::OPTION_NAME,
                'wse_error',
                sprintf(__('Export failed: %s', 'woo-to-shopify-exporter'), $exception->getMessage()),
                'error'
            );
        }

        $errors = get_settings_errors(self::OPTION_NAME);
        set_transient('settings_errors_' . self::OPTION_NAME, $errors, 30);
        wp_safe_redirect(add_query_arg('page', 'wse-export', admin_url('admin.php')));
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);
        return is_array($settings) ? $settings : [];
    }

    private function buildContext(): ExportContext
    {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
        $baseDir = (string) ($uploads['basedir'] ?? sys_get_temp_dir());
        $root = $this->trailingslash($baseDir) . 'woo-to-shopify-export';

        $this->filesystem->ensureDirectory($root);

        return new ExportContext(
            $root,
            $root . '/shopify-products.csv',
            $root . '/state.json',
            $root . '/job.log',
            $root . '/failures.json',
            $root . '/images'
        );
    }

    /**
     * @param array<string, string> $uploads
     */
    private function pathToUrl(string $path, array $uploads): ?string
    {
        $basedir = $uploads['basedir'] ?? '';
        $baseurl = $uploads['baseurl'] ?? '';

        if ($basedir === '' || $baseurl === '') {
            return null;
        }

        if (str_starts_with($path, $basedir)) {
            $relative = ltrim(str_replace($basedir, '', $path), '/\\');
            return $this->trailingslashUrl($baseurl) . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        return null;
    }

    private function trailingslash(string $path): string
    {
        $trimmed = rtrim($path, '/\\');
        if ($trimmed === '') {
            return DIRECTORY_SEPARATOR;
        }

        return $trimmed . DIRECTORY_SEPARATOR;
    }

    private function trailingslashUrl(string $url): string
    {
        return rtrim($url, '/') . '/';
    }
}
