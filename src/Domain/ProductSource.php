<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Domain;

/**
 * Stream WooCommerce products in a memory-friendly fashion.
 */
interface ProductSource
{
    /**
     * @param callable(array $product): void $callback Receives normalized product arrays.
     * @param int|null $resumeAfterId Skip products up to and including this ID.
     */
    public function stream(callable $callback, ?int $resumeAfterId = null): void;
}
