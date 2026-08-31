<?php

declare(strict_types=1);

namespace Monster;

/**
 * Read/write layer for tab payments, backed by Storage.
 */
final class TabPaymentRepository
{
    private const KEY = 'tab_payments';

    /** Cache of all() for the lifetime of this request. */
    private ?array $allCache = null;

    public function __construct(private Storage $storage) {}

    /** @return list<TabPayment> */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }
        $out = [];
        foreach ($this->storage->getList(self::KEY) as $row) {
            $out[] = TabPayment::fromArray($row);
        }
        // Newest first by date, then by creation time.
        usort($out, static fn(TabPayment $a, TabPayment $b): int =>
            $b->date <=> $a->date ?: $b->createdAt <=> $a->createdAt);
        $this->allCache = $out;
        return $out;
    }

    public function find(string $id): ?TabPayment
    {
        $row = $this->storage->find(self::KEY, $id);
        return $row === null ? null : TabPayment::fromArray($row);
    }

    public function save(TabPayment $p): void
    {
        $this->storage->put(self::KEY, $p->toArray());
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

    /** @return list<TabPayment> Payments for a single customer, newest first. */
    public function forCustomer(string $customerId): array
    {
        return array_values(array_filter($this->all(), static fn(TabPayment $p): bool => $p->customerId === $customerId));
    }

    /**
     * Drop any cached all() result. In FrankenPHP worker mode the repo instance
     * lives across requests, so the per-request cache must be cleared before each
     * new request re-reads the store.
     */
    public function clearCache(): void
    {
        $this->allCache = null;
    }
}
