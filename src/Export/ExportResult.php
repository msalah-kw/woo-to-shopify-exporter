<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

final class ExportResult
{
    /**
     * @param array<int, string> $warnings
     * @param array<int, array<string, mixed>> $failures
     */
    public function __construct(
        private readonly bool $resumed,
        private readonly int $products,
        private readonly int $variants,
        private readonly string $csvPath,
        private readonly array $warnings,
        private readonly array $failures
    ) {
    }

    public function wasResumed(): bool
    {
        return $this->resumed;
    }

    public function getProducts(): int
    {
        return $this->products;
    }

    public function getVariants(): int
    {
        return $this->variants;
    }

    public function getCsvPath(): string
    {
        return $this->csvPath;
    }

    /**
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
