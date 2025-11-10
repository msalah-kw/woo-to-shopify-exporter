<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use NSB\WooToShopify\Map\InventoryMapper;
use NSB\WooToShopify\Map\PriceMapper;
use NSB\WooToShopify\Map\ProductMapper;
use NSB\WooToShopify\Map\SeoMapper;
use NSB\WooToShopify\Map\VariantMapper;
use PHPUnit\Framework\TestCase;
use function Tests\Unit\Map\Support\makeProduct;
use function Tests\Unit\Map\Support\makeVariant;

class ProductMapperTest extends TestCase
{
    private function createMapper(): ProductMapper
    {
        return new ProductMapper(
            new VariantMapper(new PriceMapper(), new InventoryMapper()),
            new PriceMapper(),
            new InventoryMapper(),
            new SeoMapper(),
        );
    }

    public function testMapsArabicProduct(): void
    {
        $mapper = $this->createMapper();

        $product = [
            'post_name' => 'منتج-فاخر–جديد',
            'name' => 'سجادة شرقية فاخرة',
            'description' => '<p style="color:red"><script>bad()</script>محتوى غني</p>',
            'attributes' => [
                ['name' => 'brand', 'value' => 'متجر الشرق'],
            ],
            'categories' => [
                ['name' => 'ديكور', 'depth' => 0],
                ['name' => 'سجاد شرقي', 'depth' => 2],
            ],
            'tags' => ['حصير', ['name' => 'منسوج يدوي']],
            'featured_image' => 'https://example.com/main.jpg',
            'gallery' => [
                ['src' => 'https://example.com/extra1.jpg'],
                'https://example.com/main.jpg',
                ['url' => 'https://example.com/extra2.jpg'],
            ],
            'sku' => 'SK-AR-1',
            'regular_price' => 200,
            'manage_stock' => true,
            'stock_quantity' => 7,
            'tax_status' => 'taxable',
            'seo_title' => 'عنوان عربي مميز',
            'seo_description' => 'وصف قصير مخصص للبحث',
        ];

        $result = $mapper->map($product);

        $this->assertSame('منتج-فاخر-جديد', $result['handle']);
        $this->assertSame('سجادة شرقية فاخرة', $result['title']);
        $this->assertStringNotContainsString('style=', $result['body_html']);
        $this->assertStringNotContainsString('<script', $result['body_html']);
        $this->assertSame('متجر الشرق', $result['vendor']);
        $this->assertSame('سجاد شرقي', $result['product_type']);
        $this->assertSame('حصير, منسوج يدوي', $result['tags']);
        $this->assertCount(0, $result['options']);
        $this->assertCount(1, $result['variants']);
        $this->assertSame('سجادة شرقية فاخرة', $result['variants'][0]['title']);
        $this->assertSame('عنوان عربي مميز', $result['seo_title']);
        $this->assertSame('وصف قصير مخصص للبحث', $result['seo_description']);
        $this->assertCount(3, $result['images']);
        $this->assertSame('https://example.com/main.jpg', $result['images'][0]['src']);
    }

    public function testMergesOverflowOptionsIntoTags(): void
    {
        $mapper = $this->createMapper();

        $product = makeProduct([
            'tags' => ['فيب الكويت'],
            'variants' => [
                makeVariant([
                    'attributes' => [
                        ['name' => 'النكهة', 'value' => 'نعناع'],
                        ['name' => 'النيكوتين', 'value' => '3mg'],
                        ['name' => 'الحجم', 'value' => '60ml'],
                        ['name' => 'التعبئة', 'value' => 'زجاج'],
                    ],
                ]),
                makeVariant([
                    'attributes' => [
                        ['name' => 'النكهة', 'value' => 'عنب'],
                        ['name' => 'النيكوتين', 'value' => '6mg'],
                        ['name' => 'الحجم', 'value' => '120ml'],
                        ['name' => 'التعبئة', 'value' => 'ألمنيوم'],
                    ],
                ]),
            ],
        ]);

        $result = $mapper->map($product);

        $this->assertStringContainsString('التعبئة: زجاج', $result['tags']);
        $this->assertStringContainsString('التعبئة: ألمنيوم', $result['tags']);
        $this->assertCount(3, $result['options']);
    }
}
