<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Map;

class PriceMapper
{
    /**
     * @param array<string, mixed> $product
     * @return array{price: string, compare_at_price: string|null}
     */
    public function map(array $product): array
    {
        $regular = $this->normalizePrice($product['regular_price'] ?? null);
        $sale = $this->normalizePrice($product['sale_price'] ?? null);
        $onSale = $this->isSaleActive($product, $sale);

        $price = $onSale && $sale !== null ? $sale : ($regular ?? '0.00');
        $compareAt = $onSale && $regular !== null && $sale !== null && $sale !== $regular
            ? $regular
            : null;

        return [
            'price' => $price,
            'compare_at_price' => $compareAt,
        ];
    }

    private function normalizePrice(null|string|int|float $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $clean = trim(str_replace(',', '.', $value));
            if ($clean === '') {
                return null;
            }
            $value = (float) $clean;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $product
     */
    private function isSaleActive(array $product, ?string $sale): bool
    {
        if ($sale === null) {
            return false;
        }

        $start = $product['sale_start'] ?? null;
        $end = $product['sale_end'] ?? null;
        $now = $product['current_time'] ?? 'now';
        $timestamp = is_numeric($now) ? (int) $now : strtotime((string) $now);

        if ($start) {
            $startTs = is_numeric($start) ? (int) $start : strtotime((string) $start);
            if ($startTs !== false && $timestamp < $startTs) {
                return false;
            }
        }

        if ($end) {
            $endTs = is_numeric($end) ? (int) $end : strtotime((string) $end);
            if ($endTs !== false && $timestamp > $endTs) {
                return false;
            }
        }

        return true;
    }
}
