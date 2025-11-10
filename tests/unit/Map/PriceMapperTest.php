<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use NSB\WooToShopify\Map\PriceMapper;
use PHPUnit\Framework\TestCase;

class PriceMapperTest extends TestCase
{
    public function testMapsSalePriceAndCompareAt(): void
    {
        $mapper = new PriceMapper();
        $product = [
            'regular_price' => '120.50',
            'sale_price' => '99.90',
            'sale_start' => '2023-10-01',
            'sale_end' => '2024-12-31',
            'current_time' => '2024-05-10',
        ];

        $result = $mapper->map($product);

        $this->assertSame('99.90', $result['price']);
        $this->assertSame('120.50', $result['compare_at_price']);
    }

    public function testFallsBackToRegularPriceWhenSaleExpired(): void
    {
        $mapper = new PriceMapper();
        $product = [
            'regular_price' => 75,
            'sale_price' => 60,
            'sale_start' => '2023-01-01',
            'sale_end' => '2023-12-31',
            'current_time' => '2024-02-15',
        ];

        $result = $mapper->map($product);

        $this->assertSame('75.00', $result['price']);
        $this->assertNull($result['compare_at_price']);
    }
}
