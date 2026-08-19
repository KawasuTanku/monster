<?php

declare(strict_types=1);

namespace Monster;

/**
 * Read/write layer for transactions, backed by Storage.
 */
final class TransactionRepository
{
    private const KEY = 'transactions';

    /** Cache of all() for the lifetime of this request. Collapses the many
     *  callers (summary, categories, roiSeries, roiOverall, paged, …) that each
     *  otherwise re-read + re-sort the full table into a single load. */
    private ?array $allCache = null;

    public function __construct(private Storage $storage) {}

    /** @return list<Transaction> */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }
        $out = [];
        foreach ($this->storage->getList(self::KEY) as $row) {
            $out[] = Transaction::fromArray($row);
        }
        // Newest first by date, then by creation time.
        usort($out, static fn(Transaction $a, Transaction $b): int =>
            $b->date <=> $a->date ?: $b->createdAt <=> $a->createdAt);
        $this->allCache = $out;
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

    /** Remove every transaction (settings reset). One statement, not N. */
    public function deleteAll(): void
    {
        $this->storage->deleteAll(self::KEY);
        $this->allCache = null;
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

    /**
     * Case-insensitive substring search across note and category.
     * @return list<Transaction>
     */
    public function search(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return $this->all();
        }
        $q = strtolower($q);
        return array_values(array_filter($this->all(), static function (Transaction $t) use ($q): bool {
            return str_contains(strtolower($t->note), $q)
                || str_contains(strtolower($t->category), $q);
        }));
    }

    /**
     * Filtered + paginated transactions for the transactions list.
     *
     * @param array{type?: string, category?: string, from?: string, to?: string, q?: string, page?: int, perPage?: int} $opts
     * @return array{items: list<Transaction>, total: int, page: int, perPage: int, pages: int}
     */
    public function paged(array $opts = []): array
    {
        $type = $opts['type'] ?? 'all';
        $category = trim($opts['category'] ?? '');
        $from = trim($opts['from'] ?? '');
        $to = trim($opts['to'] ?? '');
        $q = trim($opts['q'] ?? '');

        $rows = $this->all();
        $rows = array_values(array_filter($rows, static function (Transaction $t) use ($type, $category, $from, $to, $q): bool {
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
            if ($q !== '') {
                $needle = strtolower($q);
                if (!str_contains(strtolower($t->note), $needle)
                    && !str_contains(strtolower($t->category), $needle)) {
                    return false;
                }
            }
            return true;
        }));

        $total = count($rows);
        $perPage = max(1, (int) ($opts['perPage'] ?? 25));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) ($opts['page'] ?? 1), $pages));
        $offset = ($page - 1) * $perPage;
        return [
            'items' => array_slice($rows, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => $pages,
        ];
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

    /**
     * Monthly ROI / P&L series computed from (filtered) transactions.
     * Each bucket carries that period's revenue, cost, net, ROI %, the
     * running cumulative net, and the transaction count for the period,
     * so a line chart can show profit building up.
     *
     * @param array{type?: string, category?: string, from?: string, to?: string} $filters
     * @return list<array{period: string, label: string, revenue: float, cost: float, net: float, roiPct: float, cumNet: float, count: int}>
     */
    public function roiSeries(array $filters = []): array
    {
        $buckets = [];
        foreach ($this->filtered($filters) as $t) {
            $period = substr($t->date, 0, 7); // YYYY-MM
            if (!isset($buckets[$period])) {
                $buckets[$period] = ['revenue' => 0.0, 'cost' => 0.0, 'count' => 0];
            }
            if ($t->type === Transaction::TYPE_EXPENSE) {
                $buckets[$period]['cost'] += $t->amount;
            } else {
                $buckets[$period]['revenue'] += $t->amount;
            }
            $buckets[$period]['count'] += 1;
        }
        ksort($buckets);

        $out = [];
        $cum = 0.0;
        foreach ($buckets as $period => $b) {
            $revenue = round($b['revenue'], 2);
            $cost = round($b['cost'], 2);
            $net = round($revenue - $cost, 2);
            $cum = round($cum + $net, 2);
            $roiPct = $cost > 0 ? round($net / $cost * 100, 2) : ($revenue > 0 ? 100.0 : 0.0);
            $ts = strtotime($period . '-01');
            $label = $ts !== false ? date('M Y', $ts) : $period;
            $out[] = [
                'period' => $period,
                'label' => $label,
                'revenue' => $revenue,
                'cost' => $cost,
                'net' => $net,
                'roiPct' => $roiPct,
                'cumNet' => $cum,
                'count' => $b['count'],
            ];
        }
        return $out;
    }

    /**
     * Realized per-item profit & loss, derived from item-linked transactions.
     * A linked sale carries revenue (amount = qty x unitPrice) and a linked
     * expense/restock carries COGS (amount = qty x unitCost), so aggregating
     * both by itemId yields each SKU's realized performance.
     *
     * @return array<string, array{revenue: float, cogs: float, net: float, unitsSold: float, unitsBought: float}>
     */
    public function perItemPnl(): array
    {
        $map = [];
        foreach ($this->all() as $t) {
            if ($t->itemId === '') {
                continue;
            }
            $row = $map[$t->itemId] ?? ['revenue' => 0.0, 'cogs' => 0.0, 'unitsSold' => 0.0, 'unitsBought' => 0.0];
            if ($t->type === Transaction::TYPE_EXPENSE) {
                $row['cogs'] += $t->amount;
                $row['unitsBought'] += $t->qty;
            } else {
                $row['revenue'] += $t->amount;
                $row['unitsSold'] += $t->qty;
            }
            $map[$t->itemId] = $row;
        }
        foreach ($map as &$row) {
            $row['revenue'] = round($row['revenue'], 2);
            $row['cogs'] = round($row['cogs'], 2);
            $row['net'] = round($row['revenue'] - $row['cogs'], 2);
            $row['unitsSold'] = round($row['unitsSold'], 4);
            $row['unitsBought'] = round($row['unitsBought'], 4);
        }
        unset($row);
        return $map;
    }

    /**
     * Aggregate ROI across all (filtered) transactions.
     *
     * @param array{type?: string, category?: string, from?: string, to?: string} $filters
     * @return array{revenue: float, cost: float, net: float, roiPct: float}
     */
    public function roiOverall(array $filters = []): array
    {
        $rev = 0.0;
        $cost = 0.0;
        foreach ($this->filtered($filters) as $t) {
            if ($t->type === Transaction::TYPE_EXPENSE) {
                $cost += $t->amount;
            } else {
                $rev += $t->amount;
            }
        }
        $net = round($rev - $cost, 2);
        $roiPct = $cost > 0 ? round($net / $cost * 100, 2) : ($rev > 0 ? 100.0 : 0.0);
        return [
            'revenue' => round($rev, 2),
            'cost' => round($cost, 2),
            'net' => $net,
            'roiPct' => $roiPct,
        ];
    }
}
