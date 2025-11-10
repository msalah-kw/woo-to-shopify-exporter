<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

use NSB\WooToShopify\Utils\Filesystem;

final class JobLogger
{
    public function __construct(
        private readonly string $path,
        private readonly Filesystem $filesystem
    ) {
        $this->filesystem->ensureDirectory(dirname($this->path));
    }

    public function reset(): void
    {
        file_put_contents($this->path, '');
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf('[%s] %s: %s%s', gmdate('c'), $level, $message, PHP_EOL);
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
