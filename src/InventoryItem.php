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
    public bool $discontinued = false;
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
        $t->discontinued = (bool) ($row['discontinued'] ?? false);
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
            'discontinued' => $this->discontinued ? 1 : 0,
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

    /**
     * Weighted-average unit cost after receiving `addQty` cans at `addUnitCost`,
     * blended with the current on-hand lot. Returns the new per-can cost.
     * Pure: does not mutate $this.
     */
    public function averageCost(float $addQty, float $addUnitCost): float
    {
        $addQty = max(0.0, $addQty);
        if ($addQty <= 0.0) {
            return $this->unitCost;
        }
        $oldValue = $this->qtyOnHand * $this->unitCost;
        $newValue = $oldValue + ($addQty * $addUnitCost);
        $newQty = $this->qtyOnHand + $addQty;
        return $newQty > 0 ? round($newValue / $newQty, 4) : $addUnitCost;
    }

    /**
     * Units to order to bring stock back up to (and a little above) the reorder
     * threshold. Returns 0 when the item isn't flagged low. Suggests topping up
     * to double the threshold so a single restock covers near-term demand.
     */
    public function reorderQty(): int
    {
        if ($this->reorderAt <= 0 || !$this->isLow()) {
            return 0;
        }
        return max(1, ($this->reorderAt * 2) - $this->qtyOnHand);
    }

    /** Per-can margin (sell price minus cost). */
    public function margin(): float
    {
        return round($this->unitPrice - $this->unitCost, 2);
    }

    /**
     * Units sold per day over the lookback window. Pure: caller passes in
     * the precomputed unit count (so we don't reach into the DB).
     */
    public function salesVelocity(int $unitsSoldInWindow, int $lookbackDays): float
    {
        if ($lookbackDays <= 0) {
            return 0.0;
        }
        return round($unitsSoldInWindow / $lookbackDays, 4);
    }

    /** Days of stock remaining at current sales velocity (INF if no velocity). */
    public function daysOfStock(int $unitsSoldInWindow, int $lookbackDays): float
    {
        $v = $this->salesVelocity($unitsSoldInWindow, $lookbackDays);
        if ($v <= 0.0) {
            return INF;
        }
        return round($this->qtyOnHand / $v, 1);
    }

    /**
     * Dynamic reorder point: ceil(velocity × safetyDays).
     * Returns 0 if no sales data (can't compute).
     */
    public function dynamicReorderPoint(int $unitsSoldInWindow, int $lookbackDays, int $safetyDays): int
    {
        $v = $this->salesVelocity($unitsSoldInWindow, $lookbackDays);
        if ($v <= 0.0) {
            return 0;
        }
        return (int) ceil($v * $safetyDays);
    }

    /**
     * True if item should be reordered: either manual reorderAt triggered
     * OR velocity-based reorder point triggered. Discontinued items never reorder.
     */
    public function needsReorder(int $unitsSoldInWindow, int $lookbackDays, int $safetyDays): bool
    {
        if ($this->discontinued) {
            return false;
        }
        if ($this->reorderAt > 0) {
            return $this->qtyOnHand <= $this->reorderAt;
        }
        $rop = $this->dynamicReorderPoint($unitsSoldInWindow, $lookbackDays, $safetyDays);
        return $rop > 0 && $this->qtyOnHand <= $rop;
    }

    /**
     * Suggested restock quantity: ceil(velocity × coverageDays).
     * Falls back to manual reorderQty if reorderAt is set.
     * Returns 0 for discontinued items or items with no sales data.
     */
    public function suggestedRestockQty(int $unitsSoldInWindow, int $lookbackDays, int $safetyDays, int $coverageDays): int
    {
        if ($this->discontinued) {
            return 0;
        }
        if ($this->reorderAt > 0) {
            return $this->reorderQty(); // legacy path
        }
        $v = $this->salesVelocity($unitsSoldInWindow, $lookbackDays);
        if ($v <= 0.0) {
            return 0;
        }
        return max(1, (int) ceil($v * $coverageDays));
    }
}
