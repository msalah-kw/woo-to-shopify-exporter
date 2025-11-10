<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use NSB\WooToShopify\Map\InventoryMapper;
use PHPUnit\Framework\TestCase;

class InventoryMapperTest extends TestCase
{
    public function testMapsManagedStockWithArabicContext(): void
    {
        $mapper = new InventoryMapper();
        $product = [
            'manage_stock' => true,
            'backorders' => 'no',
            'stock_quantity' => 12,
            'tax_status' => 'taxable',
            'name' => 'قهوة عربية فاخرة',
        ];

        $result = $mapper->map($product);

        $this->assertSame('shopify', $result['inventory_management']);
        $this->assertSame('deny', $result['inventory_policy']);
        $this->assertSame(12, $result['inventory_quantity']);
        $this->assertTrue($result['taxable']);
    }

    public function testAllowsBackorders(): void
    {
        $mapper = new InventoryMapper();
        $product = [
            'manage_stock' => false,
            'backorders' => 'notify',
            'stock_quantity' => 0,
            'tax_status' => 'none',
        ];

        $result = $mapper->map($product);

        $this->assertNull($result['inventory_management']);
        $this->assertSame('continue', $result['inventory_policy']);
        $this->assertSame(0, $result['inventory_quantity']);
        $this->assertFalse($result['taxable']);
    }
}
