<?php

declare(strict_types=1);

namespace Tests\Unit\Map\Support;

use NSB\WooToShopify\Domain\Product;
use NSB\WooToShopify\Domain\Variant;

/**
 * Build a representative WooCommerce variant fixture for mapping tests.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function makeVariant(array $overrides = []): array
{
    $defaults = [
        'sku' => null,
        'regular_price' => '5.000',
        'sale_price' => null,
        'sale_start' => null,
        'sale_end' => null,
        'manage_stock' => true,
        'stock_quantity' => 5,
        'stock_status' => 'instock',
        'backorders' => 'no',
        'tax_status' => 'taxable',
        'attributes' => [
            [
                'name' => 'Nicotine',
                'value' => '20mg',
                'is_variation' => true,
                'is_visible' => true,
            ],
            [
                'name' => 'Flavor',
                'value' => 'Mango',
                'is_variation' => true,
                'is_visible' => true,
            ],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $defaults[$key] = $value;
    }

    return $defaults;
}

/**
 * Build a representative WooCommerce product fixture for mapping tests.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function makeProduct(array $overrides = []): array
{
    $defaults = [
        'post_name' => 'نكهة-مانجو-30ml-نيكوتين-20mg',
        'name' => 'نكهة مانجو 30مل – نيكوتين 20mg',
        'description' => '<p style="color:red"><script>bad()</script>وصف</p>',
        'attributes' => [
            [
                'name' => 'brand',
                'value' => 'DZRT',
            ],
        ],
        'categories' => [
            [
                'name' => 'نكهات فيب',
                'depth' => 0,
            ],
        ],
        'tags' => ['فيب الكويت', 'نكهات'],
        'regular_price' => '5.000',
        'sale_price' => null,
        'sale_start' => null,
        'sale_end' => null,
        'current_time' => 'now',
        'manage_stock' => true,
        'stock_quantity' => 25,
        'stock_status' => 'instock',
        'backorders' => 'no',
        'tax_status' => 'taxable',
        'featured_image' => 'https://example.com/images/mango-main.jpg',
        'gallery' => [
            'https://example.com/images/mango-main.jpg',
            'https://example.com/images/mango-alt.jpg',
        ],
        'variants' => [makeVariant()],
        'seo_title' => 'نكهة مانجو 30مل – نيكوتين 20mg',
        'seo_description' => 'نكهة مانجو عربية وإنجليزية تلائم متاجر Shopify.',
    ];

    if (array_key_exists('variants', $overrides)) {
        $defaults['variants'] = $overrides['variants'];
        unset($overrides['variants']);
    }

    foreach ($overrides as $key => $value) {
        $defaults[$key] = $value;
    }

    return $defaults;
}

// Reference domain classes for static analysis tools without instantiating them.
if (false) {
    $unusedProduct = Product::class;
    $unusedVariant = Variant::class;
}
