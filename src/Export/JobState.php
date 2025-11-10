<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

use NSB\WooToShopify\Utils\Filesystem;

final class JobState
{
    public function __construct(
        private readonly string $path,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @return array{state: array<string, mixed>, resume: bool}
     */
    public function begin(bool $forceRestart = false): array
    {
        $state = $this->filesystem->readJson($this->path);
        $resume = false;

        if (!$forceRestart && !empty($state) && empty($state['completed'])) {
            $resume = true;
        } else {
            $state = [
                'last_product_id' => 0,
                'total_products' => 0,
                'total_variants' => 0,
                'started_at' => time(),
                'completed' => false,
            ];
            $this->filesystem->putJson($this->path, $state);
        }

        return ['state' => $state, 'resume' => $resume];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        $this->filesystem->putJson($this->path, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function complete(array $state): void
    {
        $state['completed'] = true;
        $state['completed_at'] = time();
        $this->filesystem->putJson($this->path, $state);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
