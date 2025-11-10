<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

use NSB\WooToShopify\Utils\Filesystem;

final class ImageExporter
{
    private bool $prepared = false;

    public function __construct(
        private readonly string $imagesDir,
        private readonly Filesystem $filesystem
    ) {
    }

    public function reset(): void
    {
        if (is_dir($this->imagesDir)) {
            $files = glob($this->imagesDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
        $this->prepared = false;
    }

    /**
     * @param array<int, array{id: int, src: string, path: string|null, featured: bool, alt?: string}> $sourceImages
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    public function copyForProduct(string $handle, array $sourceImages, array $mapped): array
    {
        if (empty($mapped['images']) || !is_array($mapped['images'])) {
            return $mapped;
        }

        $this->prepare();

        foreach ($mapped['images'] as $index => $image) {
            $src = is_array($image) && isset($image['src']) ? (string) $image['src'] : '';
            if ($src === '') {
                continue;
            }

            $matched = $this->findSourceImage($src, $sourceImages);
            if ($matched === null) {
                continue;
            }

            $path = $matched['path'] ?? null;
            if (!is_string($path) || $path === '' || !is_readable($path)) {
                continue;
            }

            $relative = $this->copyFile($handle, $path, $index + 1);
            if ($relative !== null) {
                $mapped['images'][$index]['src'] = $relative;
            }
        }

        return $mapped;
    }

    private function prepare(): void
    {
        if ($this->prepared) {
            return;
        }

        $this->filesystem->ensureDirectory($this->imagesDir);
        $this->prepared = true;
    }

    /**
     * @param array<int, array{id: int, src: string, path: string|null, featured: bool, alt?: string}> $sourceImages
     */
    private function findSourceImage(string $src, array $sourceImages): ?array
    {
        foreach ($sourceImages as $image) {
            if (!is_array($image)) {
                continue;
            }

            $candidate = (string) ($image['src'] ?? '');
            if ($candidate === $src) {
                return $image;
            }

            if ($candidate !== '' && basename($candidate) === basename($src)) {
                return $image;
            }
        }

        return null;
    }

    private function copyFile(string $handle, string $path, int $position): ?string
    {
        $slug = $handle !== '' ? $handle : 'product';
        $slug = strtolower(preg_replace('/[^a-z0-9\-]+/i', '-', $slug) ?? 'product');
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'product';
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!is_string($extension) || $extension === '') {
            $extension = 'jpg';
        }

        $filename = sprintf('%s-%03d.%s', $slug, $position, strtolower($extension));
        $destination = rtrim($this->imagesDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($path, $destination)) {
            return null;
        }

        return 'images/' . $filename;
    }
}
