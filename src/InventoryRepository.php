<?php

declare(strict_types=1);

namespace Monster;

/**
 * Read/write layer for inventory items, backed by Storage.
 */
final class InventoryRepository
{
    private const KEY = 'inventory';

    public function __construct(private Storage $storage) {}

    /** @return list<InventoryItem> */
    public function all(): array
    {
        $out = [];
        foreach ($this->storage->getList(self::KEY) as $row) {
            $out[] = InventoryItem::fromArray($row);
        }
        // Name ascending for a stable, scannable list.
        usort($out, static fn(InventoryItem $a, InventoryItem $b): int => $a->name <=> $b->name ?: $a->variant <=> $b->variant);
        return $out;
    }

    public function find(string $id): ?InventoryItem
    {
        $row = $this->storage->find(self::KEY, $id);
        return $row === null ? null : InventoryItem::fromArray($row);
    }

    public function save(InventoryItem $item): void
    {
        $item->updatedAt = time();
        $this->storage->put(self::KEY, $item->toArray());
    }

    public function delete(string $id): bool
    {
        return $this->storage->delete(self::KEY, $id);
    }

    /** Items at or below their reorder threshold. @return list<InventoryItem> */
    public function lowStock(): array
    {
        return array_values(array_filter($this->all(), static fn(InventoryItem $i): bool => $i->isLow()));
    }

    /** Total capital tied up across all on-hand stock. */
    public function totalStockValue(): float
    {
        $sum = 0.0;
        foreach ($this->all() as $i) {
            $sum += $i->stockValue();
        }
        return round($sum, 2);
    }

    /** Distinct variant/flavor names, sorted. @return list<string> */
    public function variants(): array
    {
        $out = [];
        foreach ($this->all() as $i) {
            if ($i->variant !== '') {
                $out[$i->variant] = true;
            }
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }
}
