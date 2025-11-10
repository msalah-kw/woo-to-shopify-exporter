<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Export;

use NSB\WooToShopify\Domain\ProductSource;
use NSB\WooToShopify\Map\ProductMapper;
use NSB\WooToShopify\Utils\Filesystem;
use RuntimeException;
use Throwable;

final class ExportJob
{
    public function __construct(
        private readonly ProductSource $source,
        private readonly ProductMapper $mapper,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function run(ExportContext $context, array $options = []): ExportResult
    {
        $copyImages = !empty($options['copy_images']);
        $forceRestart = !empty($options['force_restart']);

        $jobState = new JobState($context->getStatePath(), $this->filesystem);
        $logger = new JobLogger($context->getLogPath(), $this->filesystem);
        $failureLogger = new FailureLogger($context->getFailuresPath(), $this->filesystem);
        $imageExporter = new ImageExporter($context->getImagesDir(), $this->filesystem);

        $stateInfo = $jobState->begin($forceRestart);
        $state = $stateInfo['state'];
        $resume = (bool) $stateInfo['resume'];

        if ($resume) {
            $logger->info('Resuming export job.');
        } else {
            $logger->reset();
            $failureLogger->reset();
            if ($copyImages) {
                $imageExporter->reset();
            }
            $logger->info('Starting new export job.');
        }

        $writer = new CsvWriter($context->getCsvPath(), $resume);

        $productsExported = (int) ($state['total_products'] ?? 0);
        $variantsExported = (int) ($state['total_variants'] ?? 0);
        $warnings = [];

        $lastProductId = $resume ? (int) ($state['last_product_id'] ?? 0) : null;

        try {
            $this->source->stream(function (array $product) use (
                &$state,
                &$productsExported,
                &$variantsExported,
                &$warnings,
                $jobState,
                $failureLogger,
                $logger,
                $writer,
                $imageExporter,
                $copyImages
            ): void {
                $productId = (int) ($product['id'] ?? $product['product_id'] ?? 0);

                try {
                    $mapped = $this->mapper->map($product);

                    if (($mapped['handle'] ?? '') === '') {
                        throw new RuntimeException('Product is missing a handle.');
                    }

                    if ($copyImages) {
                        $imageFiles = is_array($product['image_files'] ?? null) ? $product['image_files'] : [];
                        $mapped = $imageExporter->copyForProduct($mapped['handle'], $imageFiles, $mapped);
                    }

                    $rows = $writer->writeProduct($mapped);
                    if ($rows === 0) {
                        throw new RuntimeException('No CSV rows were written for this product.');
                    }

                    $variantCount = is_countable($mapped['variants'] ?? null) ? count($mapped['variants']) : 0;
                    if ($variantCount > 100) {
                        $warning = sprintf(
                            'Product %s exports %d variants which exceeds Shopify\'s soft limit of 100.',
                            $mapped['handle'],
                            $variantCount
                        );
                        $warnings[$mapped['handle']] = $warning;
                        $logger->warning($warning);
                    }

                    $productsExported++;
                    $variantsExported += $variantCount;

                    $state['last_product_id'] = $productId;
                    $state['total_products'] = $productsExported;
                    $state['total_variants'] = $variantsExported;
                    $jobState->save($state);
                } catch (Throwable $exception) {
                    $message = $exception->getMessage();
                    $logger->error(sprintf('Failed exporting product %d: %s', $productId, $message));
                    $failureLogger->record([
                        'product_id' => $productId,
                        'handle' => $product['post_name'] ?? $product['slug'] ?? '',
                        'message' => $message,
                    ]);
                }
            }, $lastProductId);
        } finally {
            $writer->close();
        }

        $jobState->complete($state);

        return new ExportResult(
            $resume,
            $productsExported,
            $variantsExported,
            $context->getCsvPath(),
            array_values($warnings),
            $failureLogger->all()
        );
    }
}
