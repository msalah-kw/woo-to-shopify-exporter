<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Domain;

use NSB\WooToShopify\Domain\ProductSource;
use NSB\WooToShopify\Domain\ProductNormalizer;
use WC_Product;

final class WooProductSource implements ProductSource
{
    private int $batchSize;

    public function __construct(
        private readonly ProductNormalizer $normalizer,
        int $batchSize = 50
    ) {
        $this->batchSize = max(1, $batchSize);
    }

    public function stream(callable $callback, ?int $resumeAfterId = null): void
    {
        if (!function_exists('wc_get_products')) {
            return;
        }

        $page = 1;
        $lastProcessed = $resumeAfterId ?? 0;

        while (true) {
            $query = [
                'status' => ['publish'],
                'type' => ['simple', 'variable'],
                'paginate' => true,
                'limit' => $this->batchSize,
                'page' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
                'return' => 'objects',
            ];

            $result = wc_get_products($query);
            $products = [];

            if (is_array($result) && isset($result['products'])) {
                $products = $result['products'];
            } elseif (is_array($result)) {
                $products = $result;
            }

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (!($product instanceof WC_Product)) {
                    continue;
                }

                $productId = $product->get_id();
                if ($resumeAfterId !== null && $productId <= $lastProcessed) {
                    continue;
                }

                $normalized = $this->normalizer->normalize($product);
                $callback($normalized);
                $lastProcessed = $productId;
            }

            if (count($products) < $this->batchSize) {
                break;
            }

            $page++;
        }
    }
}
