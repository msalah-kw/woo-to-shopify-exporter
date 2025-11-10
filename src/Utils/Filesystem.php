<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Utils;

final class Filesystem
{
    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($path);
            return;
        }

        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $path));
        }
    }

    public function putJson(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode JSON.');
        }

        $this->writeFile($path, $encoded . "\n");
    }

    public function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);

        $tmp = tempnam($dir, 'wse');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary file.');
        }

        $bytes = file_put_contents($tmp, $contents);
        if ($bytes === false) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to write temporary file.');
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Unable to move %s to %s', $tmp, $path));
        }
    }
}
