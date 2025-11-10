<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use NSB\WooToShopify\Domain\ProductSource;
use NSB\WooToShopify\Export\ExportContext;
use NSB\WooToShopify\Export\ExportJob;
use NSB\WooToShopify\Map\InventoryMapper;
use NSB\WooToShopify\Map\PriceMapper;
use NSB\WooToShopify\Map\ProductMapper;
use NSB\WooToShopify\Map\SeoMapper;
use NSB\WooToShopify\Map\VariantMapper;
use NSB\WooToShopify\Utils\Filesystem;
use PHPUnit\Framework\TestCase;
use function Tests\Unit\Map\Support\makeProduct;

class ExportJobTest extends TestCase
{
    public function testRunsExportAndProducesArtifacts(): void
    {
        $root = sys_get_temp_dir() . '/wse-job-' . uniqid();
        $filesystem = new Filesystem();
        $context = new ExportContext(
            $root,
            $root . '/shopify-products.csv',
            $root . '/state.json',
            $root . '/job.log',
            $root . '/failures.json',
            $root . '/images'
        );

        $product = makeProduct(['id' => 1001, 'image_files' => []]);
        $source = new class([$product]) implements ProductSource {
            public function __construct(private array $products) {}
            public function stream(callable $callback, ?int $resumeAfterId = null): void
            {
                foreach ($this->products as $product) {
                    $callback($product);
                }
            }
        };

        $job = new ExportJob($source, $this->mapper(), $filesystem);
        $result = $job->run($context, ['copy_images' => false]);

        $this->assertFalse($result->wasResumed());
        $this->assertSame(1, $result->getProducts());
        $this->assertSame(1, $result->getVariants());
        $this->assertFileExists($context->getCsvPath());
        $this->assertFileExists($context->getLogPath());
        $this->assertSame([], $filesystem->readJson($context->getFailuresPath()));

        $contents = file_get_contents($context->getCsvPath());
        $this->assertIsString($contents);
        $this->assertStringContainsString('Handle,Title,Body (HTML)', $contents);

        $state = $filesystem->readJson($context->getStatePath());
        $this->assertTrue($state['completed']);
    }

    public function testResumesFromExistingState(): void
    {
        $root = sys_get_temp_dir() . '/wse-resume-' . uniqid();
        $filesystem = new Filesystem();
        $context = new ExportContext(
            $root,
            $root . '/shopify-products.csv',
            $root . '/state.json',
            $root . '/job.log',
            $root . '/failures.json',
            $root . '/images'
        );

        $products = [
            makeProduct(['id' => 2001, 'image_files' => []]),
            makeProduct(['id' => 2002, 'image_files' => []]),
        ];

        $filesystem->putJson($context->getStatePath(), [
            'last_product_id' => 2001,
            'total_products' => 1,
            'total_variants' => 1,
            'started_at' => time() - 60,
            'completed' => false,
        ]);

        $source = new class($products) implements ProductSource {
            public function __construct(private array $products) {}
            public function stream(callable $callback, ?int $resumeAfterId = null): void
            {
                foreach ($this->products as $product) {
                    $id = $product['id'] ?? 0;
                    if ($resumeAfterId !== null && $id <= $resumeAfterId) {
                        continue;
                    }
                    $callback($product);
                }
            }
        };

        $job = new ExportJob($source, $this->mapper(), $filesystem);
        $result = $job->run($context, ['copy_images' => false]);

        $this->assertTrue($result->wasResumed());
        $this->assertSame(2, $result->getProducts());
        $this->assertSame(2, $result->getVariants());
    }

    private function mapper(): ProductMapper
    {
        $price = new PriceMapper();
        $inventory = new InventoryMapper();
        $variant = new VariantMapper($price, $inventory);

        return new ProductMapper($variant, $price, $inventory, new SeoMapper());
    }
}
