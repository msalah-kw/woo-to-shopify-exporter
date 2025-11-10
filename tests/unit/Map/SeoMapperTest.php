<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use NSB\WooToShopify\Map\SeoMapper;
use PHPUnit\Framework\TestCase;

class SeoMapperTest extends TestCase
{
    public function testTruncatesAndNormalizesArabicContent(): void
    {
        $mapper = new SeoMapper();
        $product = [
            'name' => 'عسل السدر اليمني الطبيعي',
            'seo_description' => 'عسل سدر فاخر بجودة عالية معتمد ومناسب للاستخدام اليومي والصحي مع تفاصيل طويلة جداً للاختبار.',
        ];

        $result = $mapper->map($product);

        $this->assertSame('عسل السدر اليمني الطبيعي', $result['title']);
        $this->assertNotNull($result['description']);
        $this->assertLessThanOrEqual(161, mb_strlen($result['description'] ?? ''));
    }
}
