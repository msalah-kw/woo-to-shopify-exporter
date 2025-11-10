<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

use NSB\WooToShopify\Utils\Filesystem;

final class FailureLogger
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $entries = [];
    private bool $loaded = false;

    public function __construct(
        private readonly string $path,
        private readonly Filesystem $filesystem
    ) {
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->loaded = true;
        $this->filesystem->putJson($this->path, []);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function record(array $entry): void
    {
        if (!$this->loaded) {
            $data = $this->filesystem->readJson($this->path);
            $this->entries = is_array($data) ? $data : [];
            $this->loaded = true;
        }

        $this->entries[] = $entry;
        $this->filesystem->putJson($this->path, $this->entries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (!$this->loaded) {
            $data = $this->filesystem->readJson($this->path);
            $this->entries = is_array($data) ? $data : [];
            $this->loaded = true;
        }

        return $this->entries;
    }
}
