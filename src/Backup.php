<?php

declare(strict_types=1);

namespace Monster;

/**
 * Backup & restore for the single-file JSON store.
 *
 * Backups live alongside db.json in a `backups/` subdirectory (gitignored).
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

    private string $dataFile;
    private string $dir;

    public function __construct(Storage $storage)
    {
        $this->dataFile = $storage->path();
        $this->dir = dirname($this->dataFile) . '/backups';
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
     * @throws \RuntimeException if the source file is missing or unreadable.
     */
    public function create(string $label = ''): string
    {
        if (!is_file($this->dataFile)) {
            throw new \RuntimeException('Nothing to back up: ' . $this->dataFile . ' does not exist.');
        }
        $raw = file_get_contents($this->dataFile);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read source store for backup.');
        }
        $this->ensureDir();
        $stamp = date('Ymd-His');
        $name = 'monster-' . $stamp . ($label !== '' ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) : '') . '.json';
        $dest = $this->dir . '/' . $name;
        if (file_put_contents($dest, $raw, LOCK_EX) === false) {
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
        if (!is_file($target) && is_file($this->dataFile)) {
            $raw = @file_get_contents($this->dataFile);
            if ($raw !== false) {
                @file_put_contents($target, $raw, LOCK_EX);
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
     * Restore db.json from a backup file. The backup must live inside our
     * backups directory (so we never write an arbitrary uploaded path). The
     * content is validated as non-empty JSON before committing.
     * @throws \InvalidArgumentException if the source is invalid.
     * @throws \RuntimeException if the write fails.
     */
    public function restore(string $backupPath): void
    {
        $real = realpath($backupPath);
        $dirReal = realpath($this->dir);
        if ($real === false || $dirReal === false || !str_starts_with($real, $dirReal . '/') || !is_file($real)) {
            throw new \InvalidArgumentException('Backup file not found or outside the backups directory.');
        }
        $raw = file_get_contents($real);
        if ($raw === false || $raw === '' || json_decode($raw, true) === null) {
            throw new \InvalidArgumentException('Backup content is not valid JSON.');
        }
        // Before clobbering, snapshot the current (about-to-be-replaced) state.
        if (is_file($this->dataFile)) {
            try { $this->create('pre-restore'); } catch (\Throwable) {}
        }
        $tmp = $this->dataFile . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $raw, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to stage restore.');
        }
        if (!rename($tmp, $this->dataFile)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to commit restore.');
        }
    }
}
