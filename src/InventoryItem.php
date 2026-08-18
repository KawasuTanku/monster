<?php

declare(strict_types=1);

namespace Monster;

/**
 * A stock-keeping unit for the energy-drink side business, tracked per can.
 *
 * Amounts are per-can: unitCost = what you paid per can, unitPrice = what you
 * sell a can for. qtyOnHand is the number of cans currently in stock.
 */
final class InventoryItem
{
    public string $id = '';
    public string $sku = '';
    public string $name = '';
    public string $variant = '';
    public int $qtyOnHand = 0;
    public float $unitCost = 0.0;
    public float $unitPrice = 0.0;
    public int $reorderAt = 0;
    public string $supplier = '';
    public int $createdAt = 0;
    public int $updatedAt = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $t = new self();
        $t->id = (string) ($row['id'] ?? '');
        $t->sku = (string) ($row['sku'] ?? '');
        $t->name = (string) ($row['name'] ?? '');
        $t->variant = (string) ($row['variant'] ?? '');
        $t->qtyOnHand = (int) ($row['qtyOnHand'] ?? 0);
        $t->unitCost = (float) ($row['unitCost'] ?? 0);
        $t->unitPrice = (float) ($row['unitPrice'] ?? 0);
        $t->reorderAt = (int) ($row['reorderAt'] ?? 0);
        $t->supplier = (string) ($row['supplier'] ?? '');
        $t->createdAt = (int) ($row['createdAt'] ?? time());
        $t->updatedAt = (int) ($row['updatedAt'] ?? time());
        return $t;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'variant' => $this->variant,
            'qtyOnHand' => $this->qtyOnHand,
            'unitCost' => round($this->unitCost, 2),
            'unitPrice' => round($this->unitPrice, 2),
            'reorderAt' => $this->reorderAt,
            'supplier' => $this->supplier,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /** Total capital tied up in this item's on-hand stock. */
    public function stockValue(): float
    {
        return round($this->qtyOnHand * $this->unitCost, 2);
    }

    /** True when stock has fallen to (or below) the reorder threshold. */
    public function isLow(): bool
    {
        return $this->reorderAt > 0 && $this->qtyOnHand <= $this->reorderAt;
    }

    /** Per-can margin (sell price minus cost). */
    public function margin(): float
    {
        return round($this->unitPrice - $this->unitCost, 2);
    }
}
