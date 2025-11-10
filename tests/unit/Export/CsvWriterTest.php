<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use NSB\WooToShopify\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

class CsvWriterTest extends TestCase
{
    public function testWritesHeaderAndRowsWithoutBom(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'wse_csv');
        $this->assertIsString($temp);

        $writer = new CsvWriter($temp, false);
        $rowsWritten = $writer->writeProduct($this->buildProduct('sample-product', 1));
        $this->assertSame(2, $rowsWritten); // 1 variant row + 1 extra image row.
        $writer->close();

        $contents = file_get_contents($temp);
        $this->assertIsString($contents);
        $this->assertStringStartsWith('Handle,Title,Body (HTML)', $contents);
        $this->assertStringContainsString('sample-product,Sample Product,<p>Body</p>', $contents);
        $this->assertStringContainsString('TRUE', $contents); // Boolean conversion.
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $contents);

        // Append another product using resume mode and ensure header is not duplicated.
        $resumeWriter = new CsvWriter($temp, true);
        $resumeWriter->writeProduct($this->buildProduct('second-product', 2));
        $resumeWriter->close();

        $lines = array_filter(array_map('trim', explode("\n", (string) file_get_contents($temp))));
        $headerCount = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Handle,Title,Body (HTML)')) {
                $headerCount++;
            }
        }

        $this->assertSame(1, $headerCount, 'Header row should appear only once when resuming.');

        @unlink($temp);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProduct(string $handle, int $index): array
    {
        return [
            'handle' => $handle,
            'title' => 'Sample Product',
            'body_html' => '<p>Body</p>',
            'vendor' => 'Demo Vendor',
            'product_type' => 'Demo Type',
            'tags' => 'tag-a, tag-b',
            'seo_title' => 'SEO Title',
            'seo_description' => 'SEO Description',
            'options' => [
                [
                    'name' => 'Flavor',
                    'position' => 1,
                    'values' => ['Mint'],
                ],
            ],
            'variants' => [
                [
                    'sku' => sprintf('SKU-%d', $index),
                    'option1' => 'Mint',
                    'option2' => null,
                    'option3' => null,
                    'price' => '10.00',
                    'compare_at_price' => '12.00',
                    'inventory_management' => 'shopify',
                    'inventory_quantity' => 5,
                    'inventory_policy' => 'deny',
                    'taxable' => true,
                ],
            ],
            'images' => [
                ['src' => 'https://example.com/image-' . $index . '.jpg', 'position' => 1],
                ['src' => 'https://example.com/image-extra-' . $index . '.jpg', 'position' => 2],
            ],
        ];
    }
}
