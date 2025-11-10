=== Woo to Shopify Exporter ===
Contributors: nsb
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WooCommerce catalogs to Shopify-compatible CSV bundles with resumable jobs, validation, and logging.

== Description ==

Woo to Shopify Exporter streams WooCommerce products into a Shopify-ready CSV while keeping memory usage low. It
normalises pricing, inventory, options, SEO metadata, and images, and enforces Shopify’s limits—warning when a product
exceeds 100 variants or when required handles are missing. Optional image bundling copies referenced media into the
export directory so merchants can upload assets alongside the CSV.

The plugin provides:

* A four-step admin screen under **WooCommerce → Shopify Export** with capability checks and nonces.
* Resumable export jobs that persist checkpoints, log progress to `job.log`, and record per-product failures in `failures.json`.
* Streaming CSV writing without BOM using UTF-8 encoding and Shopify’s column layout.
* Variant mapping that preserves up to three option columns and rolls overflow attributes into variant titles and tags.
* Sanitised HTML bodies that remove inline styles as well as `<script>`/`<style>` blocks.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/woo-to-shopify-exporter` directory or install via Composer.
2. Run `composer install` within the plugin directory to generate the PSR-4 autoloader.
3. Activate the plugin through the **Plugins** screen in WordPress.
4. Visit **WooCommerce → Shopify Export** to configure image copying and launch exports.

== Frequently Asked Questions ==

= Does this plugin export data today? =
Yes. The exporter streams products directly from WooCommerce into a Shopify-compatible CSV, logging warnings and
failures along the way.

= Where are exports stored? =
Exports are written under the WordPress uploads directory in `woo-to-shopify-export/`. The folder contains the CSV,
`job.log`, `failures.json`, the job state file, and an `images/` directory when image copying is enabled.

== Changelog ==

= 0.1.0 =
* Initial public release with streaming CSV writer, resumable export jobs, admin UI, image bundling, and validation improvements.
