<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Map;

class SeoMapper
{
    /**
     * @param array<string, mixed> $product
     * @return array{title: string|null, description: string|null}
     */
    public function map(array $product): array
    {
        $title = $this->truncate($product['seo_title'] ?? $product['name'] ?? null, 70);
        $description = $this->truncate($product['seo_description'] ?? $product['short_description'] ?? null, 160);

        return [
            'title' => $title,
            'description' => $description,
        ];
    }

    private function truncate(mixed $value, int $limit): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $normalized = $this->normalizeWhitespace($value);
        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $limit - 1)) . '…';
    }

    private function normalizeWhitespace(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        return $collapsed ?? trim($value);
    }
}
