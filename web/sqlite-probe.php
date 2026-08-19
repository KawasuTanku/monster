<?php
declare(strict_types=1);

// Feasibility probe: reports whether this FrankenPHP runtime can use SQLite.
// Deploy and hit it live (e.g. https://monster.kawasu.wtf/sqlite-probe.php)
// to confirm before building a real SQLite test page.

function yn(bool $v): string
{
    return $v ? 'YES' : 'no';
}

$phpVersion = phpversion();
$hasPdoSqlite = extension_loaded('pdo_sqlite');
$hasSqlite3 = extension_loaded('sqlite3');
$sapi = php_sapi_name();

// Try to actually open an in-memory SQLite DB if either driver exists.
$canOpen = false;
$openError = '';
if ($hasPdoSqlite) {
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t(v) VALUES ('ok')");
        $row = $pdo->query('SELECT v FROM t WHERE id = 1')->fetchColumn();
        $canOpen = ($row === 'ok');
    } catch (Throwable $e) {
        $openError = $e->getMessage();
    }
} elseif ($hasSqlite3) {
    try {
        $db = new SQLite3(':memory:');
        $db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, v TEXT)');
        $db->exec("INSERT INTO t(v) VALUES ('ok')");
        $row = $db->querySingle('SELECT v FROM t WHERE id = 1');
        $canOpen = ($row === 'ok');
    } catch (Throwable $e) {
        $openError = $e->getMessage();
    }
}

$verdict = ($hasPdoSqlite || $hasSqlite3) && $canOpen;

header('Content-Type: text/plain; charset=utf-8');
?>
SQLite feasibility probe
========================
PHP version     : <?= $phpVersion . "\n" ?>
SAPI            : <?= $sapi . "\n" ?>
pdo_sqlite      : <?= yn($hasPdoSqlite) . "\n" ?>
sqlite3         : <?= yn($hasSqlite3) . "\n" ?>
open in-memory  : <?= yn($canOpen) . "\n" ?>
<?php if ($openError !== ''): ?>
open error      : <?= $openError . "\n" ?>
<?php endif; ?>
------------------------
VERDICT         : <?= $verdict ? 'SQLite is USABLE on this runtime' : 'SQLite NOT available on this runtime' . "\n" ?>
<?php if (!$verdict): ?>
Note: the app's Storage.php is JSON-only because of the assumption that
FrankenPHP lacks SQLite. If this probe says NO, SQLite would require a
FrankenPHP build with the sqlite extension or a different approach.
<?php endif; ?>
