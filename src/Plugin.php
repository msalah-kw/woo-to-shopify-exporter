<?php

declare(strict_types=1);

namespace NSB\WooToShopify;

use NSB\WooToShopify\Admin\ExportPage;
use NSB\WooToShopify\Domain\ProductNormalizer;
use NSB\WooToShopify\Domain\WooProductSource;
use NSB\WooToShopify\Export\ExportJob;
use NSB\WooToShopify\Map\InventoryMapper;
use NSB\WooToShopify\Map\PriceMapper;
use NSB\WooToShopify\Map\ProductMapper;
use NSB\WooToShopify\Map\SeoMapper;
use NSB\WooToShopify\Map\VariantMapper;
use NSB\WooToShopify\Utils\Filesystem;

/**
 * Core plugin bootstrapper.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    public static function init(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('plugins_loaded', [self::class, 'bootstrap']);
    }

    public static function bootstrap(): void
    {
        if (is_admin()) {
            $filesystem = new Filesystem();
            $priceMapper = new PriceMapper();
            $inventoryMapper = new InventoryMapper();
            $variantMapper = new VariantMapper($priceMapper, $inventoryMapper);
            $productMapper = new ProductMapper($variantMapper, $priceMapper, $inventoryMapper, new SeoMapper());
            $normalizer = new ProductNormalizer();
            $source = new WooProductSource($normalizer);
            $job = new ExportJob($source, $productMapper, $filesystem);

            (new ExportPage($job, $filesystem))->register();
        }
    }
}
