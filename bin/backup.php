#!/usr/bin/env php
<?php
/**
 * CLI: force a fresh JSON snapshot of the Monster store into data/backups/.
 *
 * Usage (from the deploy root, as the app user so it can read data/):
 *   sudo -u frankenphp php bin/backup.php [label]
 *
 * Prints the written backup path on success. Intended to be chained before the
 * remote-push script (scripts/backup-remote.sh) so a cron job always ships a
 * current snapshot even mid-day.
 */

declare(strict_types=1);

// Project root is the parent of this file's directory.
$baseDir = dirname(__DIR__);

// Replicate the front controller's bootstrap (no HTTP/security headers needed).
require $baseDir . '/vendor/autoload.php';

$app = new Monster\App($baseDir);
$label = $argv[1] ?? 'cron';
$path = $app->backup->create($label);
fwrite(STDOUT, $path . PHP_EOL);
