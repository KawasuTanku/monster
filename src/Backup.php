<?php

declare(strict_types=1);

namespace Monster;

/**
 * Backup & restore for the Monster store.
 *
 * Backups are portable JSON snapshots of the whole store (the same shape as the
 * legacy db.json), written to a `backups/` subdirectory (gitignored) and safe to
 * restore onto either the SQLite store or a future storage backend — Backup never
 * touches the storage engine's internal file format.
 *
 * Two kinds are produced:
 *   - manual snapshots:  monster-YYYYMMDD-HHMMSS.json
 *   - daily snapshots:   daily-YYYYMMDD.json (one per calendar day; overwritten
 *                        if another is taken the same day), pruned to KEEP days.
 *
 * A daily snapshot is taken lazily on app boot via maybeDailySnapshot(), so no
 * cron job is required.
 */
final class Backup
{
    private const KEEP = 14; // daily snapshots retained

    private Storage $storage;
    private string $dir;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
        $dataDir = dirname($storage->path());
        $this->dir = $dataDir . '/backups';
    }

    /** Absolute path to the backups directory. */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Ensure the backup directory exists. */
    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o750, true);
        }
    }

    /**
     * Take a full manual backup with an optional label. Returns the new file path.
     * The snapshot is a portable JSON dump of the whole store (same shape as the
     * legacy db.json), safe to restore onto any storage backend.
     * @throws \RuntimeException if the backup cannot be written.
     */
    public function create(string $label = ''): string
    {
        $this->ensureDir();
        $stamp = date('Ymd-His');
        $name = 'monster-' . $stamp . ($label !== '' ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) : '') . '.json';
        $dest = $this->dir . '/' . $name;
        $dump = json_encode($this->storage->exportDump(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($dump === false || file_put_contents($dest, $dump, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write backup file: ' . $dest);
        }
        return $dest;
    }

    /**
     * Idempotent daily snapshot: writes daily-YYYYMMDD.json only if it does not
     * already exist for today, then prunes older daily snapshots to KEEP.
     */
    public function maybeDailySnapshot(): void
    {
        $this->ensureDir();
        $today = date('Ymd');
        $target = $this->dir . '/daily-' . $today . '.json';
        if (!is_file($target)) {
            try {
                $this->create('daily-' . $today);
            } catch (\Throwable) {
                // Non-fatal: a missed daily snapshot should not break boot.
            }
        }
        $this->pruneDaily();
    }

    /** Remove daily snapshots older than KEEP days (by filename date). */
    private function pruneDaily(): void
    {
        $files = glob($this->dir . '/daily-*.json') ?: [];
        $dated = [];
        foreach ($files as $f) {
            if (preg_match('/daily-(\d{8})\.json$/', basename($f), $m)) {
                $dated[(int) $m[1]] = $f;
            }
        }
        ksort($dated);
        $extra = count($dated) - self::KEEP;
        if ($extra > 0) {
            foreach (array_slice(array_keys($dated), 0, $extra) as $k) {
                @unlink($dated[$k]);
            }
        }
    }

    /**
     * List available backups, newest first.
     * @return list<array{name: string, path: string, size: int, mtime: int, kind: string}>
     */
    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $base = basename($f);
            if (str_starts_with($base, 'daily-')) {
                $kind = 'daily';
            } elseif (str_starts_with($base, 'monster-')) {
                $kind = 'manual';
            } else {
                $kind = 'other';
            }
            $out[] = [
                'name' => $base,
                'path' => $f,
                'size' => (int) filesize($f),
                'mtime' => (int) filemtime($f),
                'kind' => $kind,
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** Full path of the most recent backup, or null if none. */
    public function latest(): ?string
    {
        $all = $this->list();
        return $all[0]['path'] ?? null;
    }

    /**
     * Restore the store from a backup file. The backup must live inside our
     * backups directory (so we never write an arbitrary uploaded path). The
     * content is validated as non-empty JSON before committing.
     * @throws \InvalidArgumentException if the source is invalid.
     * @throws \RuntimeException if the write fails.
     */
    public function restore(Storage $storage, string $backupPath): void
    {
        $real = realpath($backupPath);
        $dirReal = realpath($this->dir);
        if ($real === false || $dirReal === false || !str_starts_with($real, $dirReal . '/') || !is_file($real)) {
            throw new \InvalidArgumentException('Backup file not found or outside the backups directory.');
        }
        $raw = file_get_contents($real);
        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException('Backup content is empty.');
        }
        $dump = json_decode($raw, true);
        if (!is_array($dump)) {
            throw new \InvalidArgumentException('Backup content is not valid JSON.');
        }
        // Before clobbering, snapshot the current (about-to-be-replaced) state.
        try { $this->create('pre-restore'); } catch (\Throwable) {}
        $storage->loadDump($dump);
    }

    /**
     * Delete a backup file. The backup must live inside our backups directory
     * (so we never unlink an arbitrary path). Returns true if a file was removed.
     * @throws \InvalidArgumentException if the target is invalid or outside the directory.
     */
    public function delete(string $name): bool
    {
        $real = realpath($this->dir . '/' . basename($name));
        $dirReal = realpath($this->dir);
        if ($real === false || $dirReal === false || !str_starts_with($real, $dirReal . '/') || !is_file($real)) {
            throw new \InvalidArgumentException('Backup file not found or outside the backups directory.');
        }
        return @unlink($real);
    }
}
