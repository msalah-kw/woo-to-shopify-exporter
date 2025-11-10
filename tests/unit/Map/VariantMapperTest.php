<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use NSB\WooToShopify\Map\InventoryMapper;
use NSB\WooToShopify\Map\PriceMapper;
use NSB\WooToShopify\Map\VariantMapper;
use PHPUnit\Framework\TestCase;

class VariantMapperTest extends TestCase
{
    public function testMapsVariantsWithArabicOptions(): void
    {
        $mapper = new VariantMapper(new PriceMapper(), new InventoryMapper());

        $product = [
            'regular_price' => 150,
            'manage_stock' => true,
            'stock_quantity' => 3,
            'variants' => [
                [
                    'sku' => 'AR-001',
                    'sale_price' => 110,
                    'attributes' => [
                        ['name' => 'النكهة', 'value' => 'نعناع'],
                        ['name' => 'النيكوتين', 'value' => '3mg'],
                        ['name' => 'الحجم', 'value' => '60ml'],
                        ['name' => 'التعبئة', 'value' => 'زجاج'],
                    ],
                ],
                [
                    'sku' => 'AR-002',
                    'sale_price' => 115,
                    'attributes' => [
                        ['name' => 'النكهة', 'value' => 'عنب'],
                        ['name' => 'النيكوتين', 'value' => '6mg'],
                        ['name' => 'الحجم', 'value' => '120ml'],
                        ['name' => 'التعبئة', 'value' => 'ألمنيوم'],
                    ],
                ],
            ],
        ];

        $result = $mapper->map($product);

        $this->assertCount(2, $result['variants']);
        $this->assertSame('نعناع', $result['variants'][0]['option1']);
        $this->assertSame('3mg', $result['variants'][0]['option2']);
        $this->assertSame('60ml', $result['variants'][0]['option3']);
        $this->assertStringContainsString('التعبئة: زجاج', $result['variants'][0]['title']);

        $this->assertCount(3, $result['options']);
        $this->assertSame('النكهة', $result['options'][0]['name']);
        $this->assertContains('عنب', $result['options'][0]['values']);
    }
}
