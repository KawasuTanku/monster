<?php

declare(strict_types=1);

namespace Monster;

/**
 * Read/write layer for customers, backed by Storage.
 */
final class CustomerRepository
{
    private const KEY = 'customers';

    /** Cache of all() for the lifetime of this request. */
    private ?array $allCache = null;

    public function __construct(private Storage $storage) {}

    /** @return list<Customer> */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }
        $out = [];
        foreach ($this->storage->getList(self::KEY) as $row) {
            $out[] = Customer::fromArray($row);
        }
        // Name ascending for a stable, scannable list.
        usort($out, static fn(Customer $a, Customer $b): int => strcasecmp($a->name, $b->name));
        $this->allCache = $out;
        return $out;
    }

    public function find(string $id): ?Customer
    {
        $row = $this->storage->find(self::KEY, $id);
        return $row === null ? null : Customer::fromArray($row);
    }

    public function save(Customer $c): void
    {
        $this->storage->put(self::KEY, $c->toArray());
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
     * new request re-reads the store.
     */
    public function clearCache(): void
    {
        $this->allCache = null;
    }
}
