<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Domain;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variation;

/**
 * Convert WooCommerce product objects into normalized arrays for mapping.
 */
final class ProductNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(WC_Product $product): array
    {
        $productId = $product->get_id();
        $currentTime = function_exists('current_time') ? (int) current_time('timestamp') : time();

        $imageFiles = $this->collectImageFiles($product);

        $data = [
            'id' => $productId,
            'post_name' => $product->get_slug(),
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'sale_start' => $this->toTimestamp($product->get_date_on_sale_from()),
            'sale_end' => $this->toTimestamp($product->get_date_on_sale_to()),
            'current_time' => $currentTime,
            'manage_stock' => $product->managing_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'backorders' => $product->get_backorders(),
            'tax_status' => $product->get_tax_status(),
            'attributes' => $this->mapAttributes($product),
            'categories' => $this->mapTerms($productId, 'product_cat'),
            'tags' => $this->mapTags($productId),
            'featured_image' => $this->getImageUrl($product->get_image_id()),
            'gallery' => $this->mapGallery($imageFiles),
            'image_files' => $imageFiles,
            'variants' => $this->mapVariants($product),
            'seo_title' => $this->resolveSeoTitle($productId),
            'seo_description' => $this->resolveSeoDescription($productId),
        ];

        return $data;
    }

    private function toTimestamp($maybeDate): ?int
    {
        if ($maybeDate === null) {
            return null;
        }

        if (method_exists($maybeDate, 'getTimestamp')) {
            return (int) $maybeDate->getTimestamp();
        }

        if (is_numeric($maybeDate)) {
            return (int) $maybeDate;
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, depth?: int}>
     */
    private function mapTerms(int $productId, string $taxonomy): array
    {
        if (!function_exists('get_the_terms')) {
            return [];
        }

        $terms = get_the_terms($productId, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }

        $mapped = [];
        foreach ($terms as $term) {
            if (!($term instanceof \WP_Term)) {
                continue;
            }

            $mapped[] = [
                'term_id' => $term->term_id,
                'name' => $term->name,
                'depth' => $this->calculateTermDepth($term),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function mapTags(int $productId): array
    {
        if (!function_exists('wp_get_post_terms')) {
            return [];
        }

        $terms = wp_get_post_terms($productId, 'product_tag', ['fields' => 'names']);
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $terms)));
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function mapAttributes(WC_Product $product): array
    {
        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!($attribute instanceof WC_Product_Attribute)) {
                continue;
            }

            $name = $attribute->get_name();
            $label = $this->resolveAttributeLabel($name);
            $values = [];

            if ($attribute->is_taxonomy()) {
                if (function_exists('wc_get_product_terms')) {
                    $values = wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                }
            } else {
                $values = $attribute->get_options();
            }

            $values = array_filter(array_map('strval', (array) $values));
            $value = implode(', ', $values);

            if ($label === '') {
                $label = $name;
            }

            if ($value === '') {
                continue;
            }

            $attributes[] = [
                'name' => $label,
                'value' => $value,
            ];
        }

        return $attributes;
    }

    private function resolveAttributeLabel(string $name): string
    {
        $normalized = $name;
        if (str_starts_with($normalized, 'pa_')) {
            $normalized = $normalized;
        } elseif (str_starts_with($normalized, 'attribute_')) {
            $normalized = substr($normalized, strlen('attribute_'));
        }

        if (function_exists('wc_attribute_label')) {
            $label = wc_attribute_label($normalized);
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        $normalized = str_replace(['pa_', '_'], ['', ' '], $normalized);

        return ucwords($normalized);
    }

    /**
     * @param array<int, array{id: int, src: string, path: string|null, featured: bool, alt?: string}> $files
     * @return array<int, array{src: string}>
     */
    private function mapGallery(array $files): array
    {
        $images = [];
        foreach ($files as $file) {
            $src = is_array($file) && isset($file['src']) ? (string) $file['src'] : '';
            if ($src === '') {
                continue;
            }

            $images[] = ['src' => $src];
        }

        return $images;
    }

    /**
     * @return array<int, array{id: int, src: string, path: string|null, featured: bool, alt?: string}>
     */
    private function collectImageFiles(WC_Product $product): array
    {
        $images = [];
        $featuredId = (int) $product->get_image_id();
        if ($featuredId > 0) {
            $images[] = $this->buildImageFile($featuredId, true);
        }

        foreach ($product->get_gallery_image_ids() as $imageId) {
            $imageId = (int) $imageId;
            if ($imageId <= 0 || $imageId === $featuredId) {
                continue;
            }
            $images[] = $this->buildImageFile($imageId, false);
        }

        return $images;
    }

    /**
     * @return array{id: int, src: string, path: string|null, featured: bool, alt?: string}
     */
    private function buildImageFile(int $attachmentId, bool $featured): array
    {
        $src = $this->getImageUrl($attachmentId);
        $path = function_exists('get_attached_file') ? get_attached_file($attachmentId) : false;
        $alt = '';
        if (function_exists('get_post_meta')) {
            $alt = (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
        }

        return [
            'id' => $attachmentId,
            'src' => is_string($src) ? $src : '',
            'path' => is_string($path) ? $path : null,
            'featured' => $featured,
            'alt' => $alt,
        ];
    }

    private function getImageUrl(int $attachmentId): string
    {
        if ($attachmentId <= 0 || !function_exists('wp_get_attachment_url')) {
            return '';
        }

        $url = wp_get_attachment_url($attachmentId);
        return is_string($url) ? $url : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapVariants(WC_Product $product): array
    {
        if (!$product->is_type('variable')) {
            return [];
        }

        $variants = [];
        foreach ($product->get_children() as $childId) {
            $variation = function_exists('wc_get_product') ? wc_get_product($childId) : null;
            if (!($variation instanceof WC_Product_Variation)) {
                continue;
            }

            $variants[] = $this->normalizeVariation($product, $variation);
        }

        return $variants;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeVariation(WC_Product $parent, WC_Product_Variation $variation): array
    {
        $data = [
            'id' => $variation->get_id(),
            'sku' => $variation->get_sku(),
            'regular_price' => $variation->get_regular_price() ?: $parent->get_regular_price(),
            'sale_price' => $variation->get_sale_price() ?: $parent->get_sale_price(),
            'sale_start' => $this->toTimestamp($variation->get_date_on_sale_from()),
            'sale_end' => $this->toTimestamp($variation->get_date_on_sale_to()),
            'manage_stock' => $variation->managing_stock() ?: $parent->managing_stock(),
            'stock_quantity' => $variation->get_stock_quantity() ?? $parent->get_stock_quantity(),
            'stock_status' => $variation->get_stock_status() ?: $parent->get_stock_status(),
            'backorders' => $variation->get_backorders() ?: $parent->get_backorders(),
            'tax_status' => $variation->get_tax_status() ?: $parent->get_tax_status(),
            'attributes' => $this->mapVariationAttributes($variation->get_attributes(), $parent),
        ];

        $imageId = (int) $variation->get_image_id();
        if ($imageId > 0) {
            $data['image'] = $this->buildImageFile($imageId, false);
        }

        return $data;
    }

    /**
     * @param array<string, string> $attributes
     * @return array<int, array{name: string, value: string}>
     */
    private function mapVariationAttributes(array $attributes, WC_Product $product): array
    {
        $mapped = [];
        foreach ($attributes as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $key = is_string($name) ? $name : '';
            if (str_starts_with($key, 'attribute_')) {
                $key = substr($key, strlen('attribute_'));
            }

            $label = $this->resolveAttributeLabel($key);
            $mapped[] = [
                'name' => $label,
                'value' => is_array($value) ? implode(' / ', $value) : (string) $value,
            ];
        }

        return $mapped;
    }

    private function calculateTermDepth(\WP_Term $term): int
    {
        if (!function_exists('get_ancestors')) {
            return 0;
        }

        $ancestors = get_ancestors($term->term_id, $term->taxonomy);
        return is_array($ancestors) ? count($ancestors) : 0;
    }

    private function resolveSeoTitle(int $productId): ?string
    {
        if (!function_exists('get_post_meta')) {
            return null;
        }

        $title = get_post_meta($productId, '_yoast_wpseo_title', true);
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return null;
    }

    private function resolveSeoDescription(int $productId): ?string
    {
        if (!function_exists('get_post_meta')) {
            return null;
        }

        $description = get_post_meta($productId, '_yoast_wpseo_metadesc', true);
        if (is_string($description) && $description !== '') {
            return $description;
        }

        return null;
    }
}
