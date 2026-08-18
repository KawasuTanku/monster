<?php

declare(strict_types=1);

namespace Monster;

/**
 * Tiny dependency-free JSON-file storage with atomic writes and read-modify-write
 * safety. Intentionally minimal: a small side business does not need a full RDBMS,
 * and this keeps the app deployable on the FrankenPHP runtime (no PDO/sqlite).
 *
 * The storage layer is the ONLY thing that knows data lives on disk, so swapping
 * to a real database later is a localized change.
 */
final class Storage
{
    private string $file;
    private bool $loaded = false;
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /** Path to the backing file (used by the app to report storage location). */
    public function path(): string
    {
        return $this->file;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->loaded) {
            return $this->data;
        }
        $this->loaded = true;
        if (!is_file($this->file)) {
            $this->data = [];
            return $this->data;
        }
        $raw = file_get_contents($this->file);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read storage file: {$this->file}");
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->data = is_array($decoded) ? $decoded : [];
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
        $tmp = $this->file . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $bytes = file_put_contents(
            $tmp,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        if ($bytes === false) {
            throw new \RuntimeException("Unable to write storage tmp file: {$tmp}");
        }
        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to commit storage file: {$this->file}");
        }
        $this->loaded = true;
        $this->data = $data;
    }

    /**
     * Read a collection by key (e.g. "transactions"). Always returns a list.
     * @return list<array<string, mixed>>
     */
    public function getList(string $key): array
    {
        $data = $this->load();
        $list = $data[$key] ?? [];
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Read a single record by id within a collection.
     * @return array<string, mixed>|null
     */
    public function find(string $key, string $id): ?array
    {
        foreach ($this->getList($key) as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Insert or update a record by id, preserving insertion order.
     * @param array<string, mixed> $record Must contain an "id" key.
     */
    public function put(string $key, array $record): void
    {
        if (!isset($record['id'])) {
            throw new \InvalidArgumentException('Record must contain an "id" key');
        }
        $data = $this->load();
        $list = is_array($data[$key] ?? null) ? $data[$key] : [];
        $replaced = false;
        foreach ($list as &$item) {
            if (is_array($item) && ($item['id'] ?? null) === $record['id']) {
                $item = $record;
                $replaced = true;
                break;
            }
        }
        unset($item);
        if (!$replaced) {
            $list[] = $record;
        }
        $data[$key] = $list;
        $this->save($data);
    }

    /**
     * Delete a record by id. Returns true if something was removed.
     */
    public function delete(string $key, string $id): bool
    {
        $data = $this->load();
        $list = is_array($data[$key] ?? null) ? $data[$key] : [];
        $next = [];
        $removed = false;
        foreach ($list as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                $removed = true;
                continue;
            }
            $next[] = $item;
        }
        if ($removed) {
            $data[$key] = $next;
            $this->save($data);
        }
        return $removed;
    }

    /** Read a scalar setting. */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $data = $this->load();
        return $data['settings'][$key] ?? $default;
    }

    /** Write a scalar setting. */
    public function setSetting(string $key, mixed $value): void
    {
        $data = $this->load();
        $data['settings'][$key] = $value;
        $this->save($data);
    }
}
