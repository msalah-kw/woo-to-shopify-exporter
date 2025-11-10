<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

final class ExportContext
{
    public function __construct(
        private readonly string $rootDir,
        private readonly string $csvPath,
        private readonly string $statePath,
        private readonly string $logPath,
        private readonly string $failuresPath,
        private readonly string $imagesDir
    ) {
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getCsvPath(): string
    {
        return $this->csvPath;
    }

    public function getStatePath(): string
    {
        return $this->statePath;
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    public function getFailuresPath(): string
    {
        return $this->failuresPath;
    }

    public function getImagesDir(): string
    {
        return $this->imagesDir;
    }
}
