<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Map;

use IntlChar;
use Normalizer;

class ProductMapper
{
    public function __construct(
        private readonly VariantMapper $variantMapper,
        private readonly PriceMapper $priceMapper,
        private readonly InventoryMapper $inventoryMapper,
        private readonly SeoMapper $seoMapper,
    ) {
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function map(array $product): array
    {
        $variantData = $this->variantMapper->map($product);
        if (empty($variantData['variants'])) {
            $variantData = $this->buildFallbackVariant($product);
        }

        $seo = $this->seoMapper->map($product);

        return [
            'handle' => $this->normalizeHandle((string) ($product['post_name'] ?? '')),
            'title' => (string) ($product['name'] ?? ''),
            'body_html' => $this->sanitizeHtml((string) ($product['description'] ?? '')),
            'vendor' => $this->extractVendor($product),
            'product_type' => $this->extractProductType($product['categories'] ?? []),
            'tags' => $this->formatTags($product['tags'] ?? []),
            'options' => $variantData['options'],
            'variants' => $variantData['variants'],
            'images' => $this->mapImages($product),
            'seo_title' => $seo['title'],
            'seo_description' => $seo['description'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array{variants: array<int, array<string, mixed>>, options: array<int, array{name: string, position: int, values: array<int, string>>>}
     */
    private function buildFallbackVariant(array $product): array
    {
        $price = $this->priceMapper->map($product);
        $inventory = $this->inventoryMapper->map($product);

        $variant = array_merge(
            [
                'sku' => (string) ($product['sku'] ?? ''),
                'option1' => null,
                'option2' => null,
                'option3' => null,
                'title' => (string) ($product['name'] ?? ''),
            ],
            $price,
            $inventory,
        );

        return [
            'variants' => [$variant],
            'options' => [],
        ];
    }

    private function normalizeHandle(string $handle): string
    {
        if ($handle === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($handle, Normalizer::FORM_C);
            if (is_string($normalized)) {
                $handle = $normalized;
            }
        }

        $handle = str_replace(['‐', '‑', '‒', '–', '—', '―', '_', ' '], '-', $handle);

        $result = '';
        $length = mb_strlen($handle);
        $lastHyphen = false;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($handle, $i, 1);

            if ($char === '-') {
                if ($lastHyphen || $result === '') {
                    continue;
                }
                $result .= '-';
                $lastHyphen = true;
                continue;
            }

            $code = IntlChar::ord($char);
            if ($code === null || !IntlChar::isalnum($code)) {
                $lastHyphen = false;
                continue;
            }

            $result .= mb_strtolower($char);
            $lastHyphen = false;
        }

        return trim($result, '-');
    }

    private function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $result = '';
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $pos = stripos($html, ' style=', $offset);
            if ($pos === false) {
                $result .= substr($html, $offset);
                break;
            }

            $result .= substr($html, $offset, $pos - $offset);

            $quote = $html[$pos + 7] ?? '';
            $doubleQuote = chr(34);
            if ($quote === $doubleQuote || $quote === chr(39)) {
                $end = strpos($html, $quote, $pos + 8);
                if ($end === false) {
                    break;
                }
                $offset = $end + 1;
            } else {
                $offset = $pos + 7;
            }
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $categories
     */
    private function extractProductType(array $categories): string
    {
        $selected = '';
        $depth = -1;

        foreach ($categories as $category) {
            $name = '';
            $currentDepth = 0;

            if (is_array($category)) {
                $name = (string) ($category['name'] ?? '');
                $currentDepth = (int) ($category['depth'] ?? $this->deriveDepth($category));
            } elseif (is_object($category) && isset($category->name)) {
                $name = (string) $category->name;
                if (isset($category->depth)) {
                    $currentDepth = (int) $category->depth;
                }
            }

            if ($name === '') {
                continue;
            }

            if ($currentDepth > $depth) {
                $selected = $name;
                $depth = $currentDepth;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $category
     */
    private function deriveDepth(array $category): int
    {
        $depth = 0;
        $parent = $category['parent'] ?? null;

        while (is_array($parent)) {
            $depth++;
            $parent = $parent['parent'] ?? null;
        }

        return $depth;
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function formatTags(array $tags): string
    {
        $names = [];

        foreach ($tags as $tag) {
            if (is_array($tag)) {
                $name = (string) ($tag['name'] ?? '');
            } elseif (is_object($tag) && isset($tag->name)) {
                $name = (string) $tag->name;
            } else {
                $name = (string) $tag;
            }

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractVendor(array $product): string
    {
        if (!empty($product['brand'])) {
            return (string) $product['brand'];
        }

        foreach ($product['attributes'] ?? [] as $attribute) {
            if (is_array($attribute)) {
                $name = strtolower((string) ($attribute['name'] ?? $attribute['attribute'] ?? ''));
                $value = (string) ($attribute['value'] ?? $attribute['option'] ?? '');
            } elseif (is_object($attribute) && isset($attribute->name, $attribute->value)) {
                $name = strtolower((string) $attribute->name);
                $value = (string) $attribute->value;
            } else {
                continue;
            }

            if (in_array($name, ['brand', 'pa_brand'], true) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, array{src: string, position: int}>
     */
    private function mapImages(array $product): array
    {
        $images = [];
        $position = 1;

        $featured = $product['featured_image'] ?? null;
        if (is_string($featured) && $featured !== '') {
            $images[] = [
                'src' => $featured,
                'position' => $position++,
            ];
        }

        $gallery = [];
        if (isset($product['gallery']) && is_array($product['gallery'])) {
            $gallery = $product['gallery'];
        } elseif (isset($product['images']) && is_array($product['images'])) {
            $gallery = $product['images'];
        }

        foreach ($gallery as $image) {
            $src = is_array($image) ? (string) ($image['src'] ?? $image['url'] ?? '') : (string) $image;
            if ($src === '' || $src === $featured) {
                continue;
            }

            $images[] = [
                'src' => $src,
                'position' => $position++,
            ];
        }

        return $images;
    }
}
