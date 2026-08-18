<?php

declare(strict_types=1);

namespace Monster;

/**
 * Read/write layer for transactions, backed by Storage.
 */
final class TransactionRepository
{
    private const KEY = 'transactions';

    public function __construct(private Storage $storage) {}

    /** @return list<Transaction> */
    public function all(): array
    {
        $out = [];
        foreach ($this->storage->getList(self::KEY) as $row) {
            $out[] = Transaction::fromArray($row);
        }
        // Newest first by date, then by creation time.
        usort($out, static fn(Transaction $a, Transaction $b): int =>
            $b->date <=> $a->date ?: $b->createdAt <=> $a->createdAt);
        return $out;
    }

    public function find(string $id): ?Transaction
    {
        $row = $this->storage->find(self::KEY, $id);
        return $row === null ? null : Transaction::fromArray($row);
    }

    public function save(Transaction $t): void
    {
        $this->storage->put(self::KEY, $t->toArray());
    }

    public function delete(string $id): bool
    {
        return $this->storage->delete(self::KEY, $id);
    }

    /**
     * All transactions, optionally filtered.
     * @param array{type?: string, category?: string, from?: string, to?: string} $filters
     * @return list<Transaction>
     */
    public function filtered(array $filters = []): array
    {
        $type = $filters['type'] ?? 'all';
        $category = trim($filters['category'] ?? '');
        $from = trim($filters['from'] ?? '');
        $to = trim($filters['to'] ?? '');

        return array_values(array_filter($this->all(), static function (Transaction $t) use ($type, $category, $from, $to): bool {
            if ($type !== 'all' && $t->type !== $type) {
                return false;
            }
            if ($category !== '' && $t->category !== $category) {
                return false;
            }
            if ($from !== '' && $t->date < $from) {
                return false;
            }
            if ($to !== '' && $t->date > $to) {
                return false;
            }
            return true;
        }));
    }

    /** Distinct, sorted category names across all transactions. @return list<string> */
    public function categories(): array
    {
        $out = [];
        foreach ($this->all() as $t) {
            if ($t->category !== '') {
                $out[$t->category] = true;
            }
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * Aggregate totals. Returns revenue, expenses, net, and per-category breakdowns.
     * @return array{revenue: float, expenses: float, net: float, by_category: array<string, float>}
     */
    public function summary(): array
    {
        $revenue = 0.0;
        $expenses = 0.0;
        $byCategory = [];
        foreach ($this->all() as $t) {
            if ($t->type === Transaction::TYPE_EXPENSE) {
                $expenses += $t->amount;
                $byCategory[$t->category] = ($byCategory[$t->category] ?? 0.0) - $t->amount;
            } else {
                $revenue += $t->amount;
                $byCategory[$t->category] = ($byCategory[$t->category] ?? 0.0) + $t->amount;
            }
        }
        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'net' => round($revenue - $expenses, 2),
            'by_category' => array_map(static fn(float $v): float => round($v, 2), $byCategory),
        ];
    }
}
