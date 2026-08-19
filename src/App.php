<?php

declare(strict_types=1);

namespace Monster;

/**
 * Application wiring. Centralizes config resolution so the front controller and
 * CLI setup share the same paths. Data lives in data/db.sqlite next to web/ so it
 * is NOT web-served (outside the document root). A legacy db.json is imported
 * automatically on first boot (see Storage).
 */
final class App
{
    public Storage $storage;
    public Auth $auth;
    public TransactionRepository $txns;
    public InventoryRepository $inv;
    public Backup $backup;

    public function __construct(string $baseDir)
    {
        $dataDir = rtrim($baseDir, '/') . '/data';
        if (!is_dir($dataDir)) {
            // @ suppresses a benign race when multiple worker processes boot at
            // once and both try to create it (the loser just sees it already exist).
            @mkdir($dataDir, 0o750, true);
        }
        $this->storage = new Storage($dataDir . '/db.sqlite');
        $this->auth = new Auth($this->storage);
        $this->txns = new TransactionRepository($this->storage);
        $this->inv = new InventoryRepository($this->storage);
        $this->backup = new Backup($this->storage);
    }
}
