<?php

declare(strict_types=1);

namespace Monster;

/**
 * SQLite-backed storage for Monster.
 *
 * This class is the ONLY thing that knows data lives on disk, so the rest of the
 * app is insulated from the storage format. It exposes the same collection-style
 * API the app was written against (getList/find/put/delete over collections, plus
 * a settings namespace), implemented on top of a single SQLite file.
 *
 * Why SQLite instead of the old JSON file: the FrankenPHP runtime (PHP 8.5 /
 * FrankenPHP v1.12+) ships both pdo_sqlite and sqlite3, so a real database is
 * available without extra setup. SQLite gives us transactional writes, real
 * aggregation, and JOINs that the JSON layer used to fake in PHP.
 *
 * Migration: if a legacy db.json exists next to the target sqlite file, it is
 * imported automatically on first construction (idempotent — a filled DB is
 * never re-imported).
 */
final class Storage
{
    private \PDO $pdo;
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
        $this->pdo = new \PDO('sqlite:' . $file);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->ensureSchema();
    }

    /** Path to the backing file (used by Backup and to report storage location). */
    public function path(): string
    {
        return $this->file;
    }

    /** Expose the underlying PDO for the repositories' SQL queries. */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS transactions (
    id          TEXT PRIMARY KEY,
    type        TEXT NOT NULL,
    amount      REAL NOT NULL,
    category    TEXT NOT NULL DEFAULT '',
    note        TEXT NOT NULL DEFAULT '',
    date        TEXT NOT NULL,
    createdAt   INTEGER NOT NULL,
    itemId      TEXT NOT NULL DEFAULT '',
    qty         REAL NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS inventory (
    id          TEXT PRIMARY KEY,
    sku         TEXT NOT NULL DEFAULT '',
    name        TEXT NOT NULL DEFAULT '',
    variant     TEXT NOT NULL DEFAULT '',
    qtyOnHand   INTEGER NOT NULL DEFAULT 0,
    unitCost    REAL NOT NULL DEFAULT 0,
    unitPrice   REAL NOT NULL DEFAULT 0,
    reorderAt   INTEGER NOT NULL DEFAULT 0,
    supplier    TEXT NOT NULL DEFAULT '',
    createdAt   INTEGER NOT NULL DEFAULT 0,
    updatedAt   INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS users (
    id           TEXT PRIMARY KEY,
    username     TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role         TEXT NOT NULL,
    createdAt    INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
SQL);

        // Import a legacy JSON store if present and the DB is empty.
        $legacyJson = dirname($this->file) . '/db.json';
        if (is_file($legacyJson) && $this->isEmpty()) {
            $this->importLegacyJson($legacyJson);
        }
    }

    /**
     * Serialize the whole store as a plain array (the same shape as the legacy
     * db.json). Used by Backup to produce portable, human-readable snapshots.
     * @return array{transactions: list<array<string, mixed>>, inventory: list<array<string, mixed>>, users: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public function exportDump(): array
    {
        $settings = [];
        foreach ($this->pdo->query('SELECT key, value FROM settings')->fetchAll() as $r) {
            $settings[$r['key']] = json_decode($r['value'], true);
        }
        return [
            'transactions' => $this->getList('transactions'),
            'inventory' => $this->getList('inventory'),
            'users' => $this->getList('users'),
            'settings' => $settings,
        ];
    }

    /**
     * Replace the entire store with the given dump (same shape as exportDump() /
     * a legacy db.json). Runs in a single transaction so a half-written restore
     * can never leave the store corrupted.
     * @param array<string, mixed> $dump
     */
    public function loadDump(array $dump): void
    {
        $txCols = ['id', 'type', 'amount', 'category', 'note', 'date', 'createdAt', 'itemId', 'qty'];
        $invCols = ['id', 'sku', 'name', 'variant', 'qtyOnHand', 'unitCost', 'unitPrice', 'reorderAt', 'supplier', 'createdAt', 'updatedAt'];
        $userCols = ['id', 'username', 'password_hash', 'role', 'createdAt'];

        $this->pdo->exec('BEGIN');
        try {
            $this->pdo->exec('DELETE FROM transactions');
            $this->pdo->exec('DELETE FROM inventory');
            $this->pdo->exec('DELETE FROM users');
            $this->pdo->exec('DELETE FROM settings');
            $this->insertRows('transactions', $txCols, $dump['transactions'] ?? []);
            $this->insertRows('inventory', $invCols, $dump['inventory'] ?? []);
            $this->insertRows('users', $userCols, $dump['users'] ?? []);
            foreach (($dump['settings'] ?? []) as $k => $v) {
                $this->setSetting((string) $k, $v);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    private function isEmpty(): bool
    {
        $count = (int) $this->pdo->query(
            'SELECT (SELECT COUNT(*) FROM transactions) + (SELECT COUNT(*) FROM inventory) + (SELECT COUNT(*) FROM users) + (SELECT COUNT(*) FROM settings)'
        )->fetchColumn();
        return $count === 0;
    }

    /**
     * Import a db.json written by the old JSON Storage format.
     * Collections (transactions/inventory/users) become rows; top-level
     * `settings` keys (user / password_hash / throttle) become settings rows.
     */
    private function importLegacyJson(string $legacyJson): void
    {
        $raw = file_get_contents($legacyJson);
        if ($raw === false) {
            return;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($data)) {
            return;
        }

        $txCols = ['id', 'type', 'amount', 'category', 'note', 'date', 'createdAt', 'itemId', 'qty'];
        $invCols = ['id', 'sku', 'name', 'variant', 'qtyOnHand', 'unitCost', 'unitPrice', 'reorderAt', 'supplier', 'createdAt', 'updatedAt'];
        $userCols = ['id', 'username', 'password_hash', 'role', 'createdAt'];

        $this->insertRows('transactions', $txCols, $data['transactions'] ?? []);
        $this->insertRows('inventory', $invCols, $data['inventory'] ?? []);
        $this->insertRows('users', $userCols, $data['users'] ?? []);

        foreach (($data['settings'] ?? []) as $k => $v) {
            $this->setSetting((string) $k, $v);
        }

        // Rename the consumed JSON so a re-run never double-imports it.
        @rename($legacyJson, $legacyJson . '.imported');
    }

    /**
     * @param list<string> $cols
     * @param mixed $rows
     */
    private function insertRows(string $table, array $cols, $rows): void
    {
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $colList = implode(', ', $cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO $table ($colList) VALUES ($placeholders)");
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($cols as $c) {
                $values[] = $row[$c] ?? null;
            }
            $stmt->execute($values);
        }
    }

    /**
     * Read a collection by key (e.g. "transactions"). Always returns a list.
     * @return list<array<string, mixed>>
     */
    public function getList(string $key): array
    {
        $table = $this->tableFor($key);
        $rows = $this->pdo->query("SELECT * FROM $table ORDER BY rowid")->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->normalize($row);
        }
        return $out;
    }

    /**
     * Read a single record by id within a collection.
     * @return array<string, mixed>|null
     */
    public function find(string $key, string $id): ?array
    {
        $table = $this->tableFor($key);
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->normalize($row);
    }

    /**
     * Insert or update a record by id. The full row is (re)written so columns
     * not present in the record keep their previous value rather than nulling —
     * matching the JSON put() contract where the whole record is replaced.
     * @param array<string, mixed> $record Must contain an "id" key.
     */
    public function put(string $key, array $record): void
    {
        if (!isset($record['id'])) {
            throw new \InvalidArgumentException('Record must contain an "id" key');
        }
        $table = $this->tableFor($key);
        $existing = $this->find($key, (string) $record['id']) ?? [];
        $merged = array_merge($existing, $record);

        $cols = array_keys($merged);
        $colList = implode(', ', $cols);
        $placeholderList = implode(', ', array_fill(0, count($cols), '?'));
        $updateList = implode(', ', array_map(static fn($c) => "$c = excluded.$c", $cols));
        $sql = "INSERT INTO $table ($colList) VALUES ($placeholderList)
                ON CONFLICT(id) DO UPDATE SET $updateList";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($merged));
    }

    /**
     * Delete a record by id. Returns true if something was removed.
     */
    public function delete(string $key, string $id): bool
    {
        $table = $this->tableFor($key);
        $stmt = $this->pdo->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete every record in a collection (used by bulk reset). A single
     * statement, not one DELETE per row.
     */
    public function deleteAll(string $key): void
    {
        $table = $this->tableFor($key);
        $this->pdo->exec("DELETE FROM $table");
    }

    /** Read a scalar setting (stored JSON-encoded so any type round-trips). */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row === false || $row['value'] === null) {
            return $default;
        }
        return json_decode($row['value'], true);
    }

    /** Write a scalar setting. */
    public function setSetting(string $key, mixed $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, json_encode($value)]);
    }

    /** Map a collection key to its SQLite table. */
    private function tableFor(string $key): string
    {
        return match ($key) {
            'transactions', 'inventory', 'users' => $key,
            default => throw new \InvalidArgumentException("Unknown collection: $key"),
        };
    }

    /** Cast SQLite numeric columns back to the int/float the app expects. */
    private function normalize(array $row): array
    {
        foreach (['amount', 'unitCost', 'unitPrice', 'qty'] as $f) {
            if (isset($row[$f]) && is_numeric($row[$f])) {
                $row[$f] = (float) $row[$f];
            }
        }
        foreach (['createdAt', 'updatedAt', 'qtyOnHand', 'reorderAt'] as $i) {
            if (isset($row[$i]) && is_numeric($row[$i])) {
                $row[$i] = (int) $row[$i];
            }
        }
        return $row;
    }
}
