<?php

declare(strict_types=1);

namespace NSB\WooToShopify\Map;

class InventoryMapper
{
    /**
     * @param array<string, mixed> $product
     * @return array{inventory_management: string|null, inventory_policy: string, inventory_quantity: int, taxable: bool}
     */
    public function map(array $product): array
    {
        $manageStock = (bool) ($product['manage_stock'] ?? false);
        $backorders = strtolower((string) ($product['backorders'] ?? 'no'));
        $qty = (int) ($product['stock_quantity'] ?? 0);
        $taxStatus = strtolower((string) ($product['tax_status'] ?? 'taxable'));

        return [
            'inventory_management' => $manageStock ? 'shopify' : null,
            'inventory_policy' => $this->mapPolicy($backorders),
            'inventory_quantity' => $qty,
            'taxable' => $taxStatus === 'taxable',
        ];
    }

    private function mapPolicy(string $backorders): string
    {
        return in_array($backorders, ['notify', 'yes'], true) ? 'continue' : 'deny';
    }
}
