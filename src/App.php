<?php

declare(strict_types=1);

namespace Monster;

/**
 * Application wiring. Centralizes config resolution so the front controller and
 * CLI setup share the same paths. Data lives in data/db.json next to web/ so it
 * is NOT web-served (outside the document root).
 */
final class App
{
    public Storage $storage;
    public Auth $auth;
    public TransactionRepository $txns;

    public function __construct(string $baseDir)
    {
        $dataDir = rtrim($baseDir, '/') . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0o750, true);
        }
        $this->storage = new Storage($dataDir . '/db.json');
        $this->auth = new Auth($this->storage);
        $this->txns = new TransactionRepository($this->storage);
    }
}
