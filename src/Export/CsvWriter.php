<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

use RuntimeException;

final class CsvWriter
{
    private const HEADERS = [
        'Handle',
        'Title',
        'Body (HTML)',
        'Vendor',
        'Product Category',
        'Type',
        'Tags',
        'Published',
        'Option1 Name',
        'Option1 Value',
        'Option2 Name',
        'Option2 Value',
        'Option3 Name',
        'Option3 Value',
        'Variant SKU',
        'Variant Inventory Tracker',
        'Variant Inventory Qty',
        'Variant Inventory Policy',
        'Variant Fulfillment Service',
        'Variant Price',
        'Variant Compare At Price',
        'Variant Requires Shipping',
        'Variant Taxable',
        'Variant Barcode',
        'Image Src',
        'Image Position',
        'SEO Title',
        'SEO Description',
        'Status',
    ];

    private $handle = null;
    private bool $initialized = false;

    public function __construct(
        private readonly string $path,
        private readonly bool $resume
    ) {
    }

    /**
     * @param array<string, mixed> $product
     */
    public function writeProduct(array $product): int
    {
        $this->ensureHandle();

        $rows = 0;
        $images = is_array($product['images'] ?? null) ? $product['images'] : [];
        $imageQueue = $images;
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];

        $options = $this->indexOptions($product['options'] ?? []);

        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $image = array_shift($imageQueue);
            $row = $this->buildVariantRow($product, $variant, $options, $image, $index === 0);
            $this->writeRow($row);
            $rows++;
        }

        foreach ($imageQueue as $image) {
            if (!is_array($image)) {
                continue;
            }

            $this->writeRow($this->buildImageRow($product, $image));
            $rows++;
        }

        return $rows;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->initialized = false;
    }

    private function ensureHandle(): void
    {
        if ($this->initialized) {
            return;
        }

        $exists = is_file($this->path);
        $filesize = $exists ? filesize($this->path) : 0;
        $mode = ($this->resume && $exists && $filesize !== false && $filesize > 0) ? 'ab' : 'wb';

        $handle = fopen($this->path, $mode);
        if (!is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to open CSV file: %s', $this->path));
        }

        $this->handle = $handle;
        $this->initialized = true;

        if (!$this->resume || !$exists || $filesize === 0) {
            $this->writeRow(array_combine(self::HEADERS, self::HEADERS));
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $variant
     * @param array<int, array{name: string, position: int, values: array<int, string>}> $options
     * @param array<string, mixed>|null $image
     * @return array<string, mixed>
     */
    private function buildVariantRow(array $product, array $variant, array $options, ?array $image, bool $includeProductColumns): array
    {
        $optionNames = $this->optionNames($options);

        return [
            'Handle' => (string) ($product['handle'] ?? ''),
            'Title' => $includeProductColumns ? (string) ($product['title'] ?? '') : '',
            'Body (HTML)' => $includeProductColumns ? (string) ($product['body_html'] ?? '') : '',
            'Vendor' => $includeProductColumns ? (string) ($product['vendor'] ?? '') : '',
            'Product Category' => '',
            'Type' => $includeProductColumns ? (string) ($product['product_type'] ?? '') : '',
            'Tags' => $includeProductColumns ? (string) ($product['tags'] ?? '') : '',
            'Published' => $includeProductColumns ? 'TRUE' : '',
            'Option1 Name' => $optionNames[0] ?? '',
            'Option1 Value' => (string) ($variant['option1'] ?? ''),
            'Option2 Name' => $optionNames[1] ?? '',
            'Option2 Value' => (string) ($variant['option2'] ?? ''),
            'Option3 Name' => $optionNames[2] ?? '',
            'Option3 Value' => (string) ($variant['option3'] ?? ''),
            'Variant SKU' => (string) ($variant['sku'] ?? ''),
            'Variant Inventory Tracker' => (string) ($variant['inventory_management'] ?? ''),
            'Variant Inventory Qty' => (string) ($variant['inventory_quantity'] ?? '0'),
            'Variant Inventory Policy' => (string) ($variant['inventory_policy'] ?? ''),
            'Variant Fulfillment Service' => 'manual',
            'Variant Price' => (string) ($variant['price'] ?? ''),
            'Variant Compare At Price' => (string) ($variant['compare_at_price'] ?? ''),
            'Variant Requires Shipping' => 'TRUE',
            'Variant Taxable' => !empty($variant['taxable']) ? 'TRUE' : 'FALSE',
            'Variant Barcode' => (string) ($variant['barcode'] ?? ''),
            'Image Src' => $image['src'] ?? '',
            'Image Position' => $image['position'] ?? '',
            'SEO Title' => $includeProductColumns ? (string) ($product['seo_title'] ?? '') : '',
            'SEO Description' => $includeProductColumns ? (string) ($product['seo_description'] ?? '') : '',
            'Status' => $includeProductColumns ? 'active' : '',
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $image
     * @return array<string, mixed>
     */
    private function buildImageRow(array $product, array $image): array
    {
        return [
            'Handle' => (string) ($product['handle'] ?? ''),
            'Title' => '',
            'Body (HTML)' => '',
            'Vendor' => '',
            'Product Category' => '',
            'Type' => '',
            'Tags' => '',
            'Published' => '',
            'Option1 Name' => '',
            'Option1 Value' => '',
            'Option2 Name' => '',
            'Option2 Value' => '',
            'Option3 Name' => '',
            'Option3 Value' => '',
            'Variant SKU' => '',
            'Variant Inventory Tracker' => '',
            'Variant Inventory Qty' => '',
            'Variant Inventory Policy' => '',
            'Variant Fulfillment Service' => '',
            'Variant Price' => '',
            'Variant Compare At Price' => '',
            'Variant Requires Shipping' => '',
            'Variant Taxable' => '',
            'Variant Barcode' => '',
            'Image Src' => $image['src'] ?? '',
            'Image Position' => $image['position'] ?? '',
            'SEO Title' => '',
            'SEO Description' => '',
            'Status' => '',
        ];
    }

    /**
     * @param array<int, array{name: string, position: int, values: array<int, string>}> $options
     * @return array<int, string>
     */
    private function optionNames(array $options): array
    {
        $names = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $position = (int) ($option['position'] ?? 0);
            if ($position < 1 || $position > 3) {
                continue;
            }

            $names[$position - 1] = (string) ($option['name'] ?? '');
        }

        return [
            $names[0] ?? 'Option',
            $names[1] ?? 'Option2',
            $names[2] ?? 'Option3',
        ];
    }

    /**
     * @param array<int, mixed> $options
     * @return array<int, array{name: string, position: int, values: array<int, string>}>
     */
    private function indexOptions(array $options): array
    {
        $indexed = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $position = (int) ($option['position'] ?? 0);
            if ($position < 1 || $position > 3) {
                continue;
            }

            $indexed[$position] = $option;
        }

        ksort($indexed);

        return array_values($indexed);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeRow(array $row): void
    {
        if (!is_resource($this->handle)) {
            throw new RuntimeException('CSV handle is not initialized.');
        }

        $line = [];
        foreach (self::HEADERS as $column) {
            $value = $row[$column] ?? '';
            if (is_bool($value)) {
                $line[] = $value ? 'TRUE' : 'FALSE';
            } elseif (is_scalar($value)) {
                $line[] = (string) $value;
            } else {
                $line[] = '';
            }
        }

        if (fputcsv($this->handle, $line) === false) {
            throw new RuntimeException('Failed to write CSV row.');
        }
    }
}
