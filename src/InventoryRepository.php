<?php

declare(strict_types=1);

namespace Monster;

/**
 * Read/write layer for inventory items, backed by Storage.
 */
final class InventoryRepository
{
    private const KEY = 'inventory';

    /** Cache of all() for the lifetime of this request. totalStockValue(),
     *  lowStock(), and the /inventory + /transactions views each call all();
     *  this collapses them into one load per request. */
    private ?array $allCache = null;

    public function __construct(private Storage $storage) {}

    /** @return list<InventoryItem> */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }
        $out = [];
        foreach ($this->storage->getList(self::KEY) as $row) {
            $out[] = InventoryItem::fromArray($row);
        }
        // Name ascending for a stable, scannable list.
        usort($out, static fn(InventoryItem $a, InventoryItem $b): int => $a->name <=> $b->name ?: $a->variant <=> $b->variant);
        $this->allCache = $out;
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
        $this->allCache = null;
    }

    public function delete(string $id): bool
    {
        $ok = $this->storage->delete(self::KEY, $id);
        if ($ok) {
            $this->allCache = null;
        }
        return $ok;
    }

    /**
     * Drop any cached all() result. In FrankenPHP worker mode the repo instance
     * lives across requests, so the per-request cache must be cleared before each
     * new request re-reads the store. No-op in traditional (per-request) mode.
     */
    public function clearCache(): void
    {
        $this->allCache = null;
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
