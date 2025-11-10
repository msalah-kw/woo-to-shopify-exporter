<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Map;

class VariantMapper
{
    public function __construct(
        private readonly PriceMapper $priceMapper,
        private readonly InventoryMapper $inventoryMapper,
    ) {
    }

    /**
     * @param array<string, mixed> $product
     * @return array{variants: array<int, array<string, mixed>>, options: array<int, array{name: string, position: int, values: array<int, string>>>}
     */
    public function map(array $product): array
    {
        $optionAccumulator = [];
        $variants = [];

        foreach ($product['variants'] ?? [] as $variant) {
            $attributes = $this->normalizeAttributes($variant['attributes'] ?? []);
            $optionValues = array_values(array_slice($attributes, 0, 3, true));
            $options = $this->mapOptions($optionValues, $optionAccumulator);
            $optionAccumulator = $options['accumulator'];

            $price = $this->priceMapper->map($variant + $product);
            $inventory = $this->inventoryMapper->map($variant + $product);

            $variantRow = [
                'sku' => (string) ($variant['sku'] ?? ''),
                'option1' => $optionValues[0]['value'] ?? null,
                'option2' => $optionValues[1]['value'] ?? null,
                'option3' => $optionValues[2]['value'] ?? null,
                'title' => $this->buildTitle($variant, $attributes),
            ];

            $variants[] = array_merge(
                $variantRow,
                $price,
                $inventory,
            );
        }

        return [
            'variants' => $variants,
            'options' => $this->formatOptions($optionAccumulator),
        ];
    }

    /**
     * @param array<int, array{name: string, value: string}> $attributes
     * @return array{accumulator: array<int, array{name: string, values: array<string, bool>>}}
     */
    private function mapOptions(array $attributes, array $accumulator): array
    {
        foreach ($attributes as $position => $attribute) {
            $index = $position + 1;
            $name = $attribute['name'];
            $value = $attribute['value'];

            if (!isset($accumulator[$index])) {
                $accumulator[$index] = [
                    'name' => $name,
                    'values' => [],
                ];
            }

            if ($accumulator[$index]['name'] !== $name) {
                $accumulator[$index]['name'] = $name;
            }

            $accumulator[$index]['values'][$value] = true;
        }

        return ['accumulator' => $accumulator];
    }

    /**
     * @param array<int, array{name: string, value: string}> $attributes
     */
    private function buildTitle(array $variant, array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $attribute) {
            $parts[] = sprintf('%s: %s', $attribute['name'], $attribute['value']);
        }

        if (count($attributes) > 3 && isset($variant['extra_tags'])) {
            $parts[] = (string) $variant['extra_tags'];
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array{name: string, value: string}>
     */
    private function normalizeAttributes(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $attribute) {
            if (is_array($attribute)) {
                $name = (string) ($attribute['name'] ?? $attribute['attribute'] ?? '');
                $value = (string) ($attribute['value'] ?? $attribute['option'] ?? '');
            } elseif (is_object($attribute) && isset($attribute->name, $attribute->value)) {
                $name = (string) $attribute->name;
                $value = (string) $attribute->value;
            } else {
                continue;
            }

            if ($name === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{name: string, values: array<string, bool>>> $accumulator
     * @return array<int, array{name: string, position: int, values: array<int, string>}>
     */
    private function formatOptions(array $accumulator): array
    {
        $options = [];
        foreach ($accumulator as $position => $entry) {
            $options[] = [
                'name' => $entry['name'],
                'position' => $position,
                'values' => array_keys($entry['values']),
            ];
        }

        return $options;
    }
}
