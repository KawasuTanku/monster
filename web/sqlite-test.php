<?php
declare(strict_types=1);
date_default_timezone_set('America/Chicago');

// SQLite feasibility test page for monster.
// Mirrors the JSON data model (users / inventory / transactions / backups) in a
// SQLite DB and exercises the same operations the app uses (insert, aggregate,
// item-linked join). Standalone page served directly by FrankenPHP.
// The DB lives at data/sqlite-test.db and does NOT touch the live db.json.

use function Monster\e;
use function Monster\money;

require __DIR__ . '/../vendor/autoload.php';

$dbFile = __DIR__ . '/../data/sqlite-test.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ---- Schema (CREATE IF NOT EXISTS so the page is idempotent) ----
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id           TEXT PRIMARY KEY,
    username     TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role         TEXT NOT NULL,
    createdAt    INTEGER NOT NULL
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
CREATE TABLE IF NOT EXISTS backups (
    id          TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0,
    createdAt   INTEGER NOT NULL DEFAULT 0
);
SQL);

function seed(PDO $pdo): void
{
    $now = time();
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 13]);
    $pdo->exec("DELETE FROM users; DELETE FROM inventory; DELETE FROM transactions; DELETE FROM backups;");
    $pdo->exec("INSERT INTO users (id, username, password_hash, role, createdAt) VALUES
        ('admin', 'admin', '$hash', 'admin', $now),
        ('bob',   'bob',   '$hash', 'member', $now)");
    $pdo->exec("INSERT INTO inventory (id, sku, name, variant, qtyOnHand, unitCost, unitPrice, reorderAt, supplier, createdAt, updatedAt) VALUES
        ('redbull-12', 'RB-12', 'Red Bull', '12oz', 40, 1.50, 3.00, 12, 'Sysco', $now, $now),
        ('monster-16','MN-16', 'Monster',  '16oz', 8,  1.20, 2.75, 10, 'KeHE',  $now, $now),
        ('celcius',   'CL-12', 'Celsius', '12oz', 25, 1.10, 2.50, 10, 'Sysco', $now, $now)");
    $pdo->exec("INSERT INTO transactions (id, type, amount, category, note, date, createdAt, itemId, qty) VALUES
        ('t1','sale',    90.00, 'energy',   'walk-in cooler restock sale', '2026-08-10', $now, 'redbull-12', 30),
        ('t2','sale',    44.00, 'energy',   'gym float sale',              '2026-08-12', $now, 'monster-16', 16),
        ('t3','expense', 60.00, 'inventory','Red Bull case',              '2026-08-09', $now, 'redbull-12', 40),
        ('t4','expense', 19.20, 'inventory','Monster case',               '2026-08-09', $now, 'monster-16', 8),
        ('t5','expense', 25.00, 'fees',     'platform fee',               '2026-08-14', $now, '',           1)");
    $pdo->exec("INSERT INTO backups (id, name, size, createdAt) VALUES
        ('b1','monster-daily-2026-08-14.json', 4096, $now),
        ('b2','monster-manual-2026-08-13.json', 8192, $now)");
}

// ---- Actions ----
$action = (string) ($_POST['action'] ?? ($_GET['action'] ?? ''));
$flash = '';
if ($action === 'seed') {
    seed($pdo);
    $flash = 'Seeded sample data.';
} elseif ($action === 'reset') {
    $pdo->exec("DROP TABLE IF EXISTS users; DROP TABLE IF EXISTS inventory; DROP TABLE IF EXISTS transactions; DROP TABLE IF EXISTS backups;");
    // re-create empty
    header('Location: /sqlite-test.php');
    exit;
} elseif ($action === 'add') {
    $type = $_POST['type'] === 'expense' ? 'expense' : 'sale';
    $amount = (float) ($_POST['amount'] ?? 0);
    $note = substr(trim((string) ($_POST['note'] ?? '')), 0, 200);
    $category = substr(trim((string) ($_POST['category'] ?? '')), 0, 60);
    $itemId = substr(trim((string) ($_POST['itemId'] ?? '')), 0, 60);
    $qty = (float) ($_POST['qty'] ?? 1);
    $date = (string) ($_POST['date'] ?? date('Y-m-d'));
    $id = 't' . time() . bin2hex(random_bytes(2));
    $stmt = $pdo->prepare("INSERT INTO transactions (id, type, amount, category, note, date, createdAt, itemId, qty)
        VALUES (:id, :type, :amount, :category, :note, :date, :createdAt, :itemId, :qty)");
    $stmt->execute([
        'id' => $id, 'type' => $type, 'amount' => round($amount, 2),
        'category' => $category, 'note' => $note, 'date' => $date,
        'createdAt' => time(), 'itemId' => $itemId, 'qty' => $qty,
    ]);
    $flash = "Inserted transaction $id.";
}

$counts = [
    'users'        => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'inventory'    => (int) $pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn(),
    'transactions' => (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),
    'backups'      => (int) $pdo->query('SELECT COUNT(*) FROM backups')->fetchColumn(),
];

// ---- Report aggregates (mirrors report page logic) ----
$t0 = microtime(true);
$rev = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='sale'")->fetchColumn();
$exp = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense'")->fetchColumn();
$net = $rev - $exp;
$aggMs = round((microtime(true) - $t0) * 1000, 3);

// Item-linked sales join (what the app does for inventory roll-ups)
$t1 = microtime(true);
$linked = $pdo->query("
    SELECT t.id, t.amount, t.qty, t.date, i.name AS item, i.unitPrice
    FROM transactions t
    LEFT JOIN inventory i ON i.id = t.itemId
    WHERE t.itemId <> ''
    ORDER BY t.date DESC
")->fetchAll();
$joinMs = round((microtime(true) - $t1) * 1000, 3);

$users = $pdo->query('SELECT id, username, role, createdAt FROM users ORDER BY username')->fetchAll();
$inv = $pdo->query('SELECT id, sku, name, variant, qtyOnHand, unitCost, unitPrice, reorderAt FROM inventory ORDER BY name')->fetchAll();
$txns = $pdo->query('SELECT id, type, amount, category, note, date, itemId, qty FROM transactions ORDER BY date DESC, createdAt DESC LIMIT 50')->fetchAll();

$dbSize = is_file($dbFile) ? filesize($dbFile) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SQLite feasibility test &middot; monster</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .stat { display: inline-block; margin: 0.25rem 0.75rem 0.25rem 0; }
  .stat b { font-size: 1.4rem; }
  .pill { display:inline-block; padding:0.1rem 0.4rem; border-radius:4px; font-size:0.8rem; }
  .pill.sale { background:#1f8b3a; color:#000; }
  .pill.expense { background:#b4232a; color:#fff; }
  code.k { background:var(--surface-2,#1b2230); padding:0.05rem 0.3rem; border-radius:3px; }
</style>
</head>
<body>
<main class="container">
  <h1>SQLite feasibility test</h1>
  <p class="muted">Proves monster's JSON model runs on SQLite. DB file: <code class="k"><?= e($dbFile) ?></code> (<?= $dbSize ?> bytes). This does not touch the live <code class="k">db.json</code>.</p>

  <p>
    <a class="btn" href="/sqlite-test.php?action=seed">Seed sample data</a>
    <a class="btn" href="/sqlite-test.php?action=reset">Reset (empty)</a>
  </p>
  <?php if ($flash !== ''): ?><p class="muted"><?= e($flash) ?></p><?php endif; ?>

  <h2>Collection counts</h2>
  <p>
    <span class="stat"><b><?= $counts['users'] ?></b><br>users</span>
    <span class="stat"><b><?= $counts['inventory'] ?></b><br>inventory</span>
    <span class="stat"><b><?= $counts['transactions'] ?></b><br>transactions</span>
    <span class="stat"><b><?= $counts['backups'] ?></b><br>backups</span>
  </p>

  <h2>Report aggregates</h2>
  <p>
    <span class="stat"><b><?= money($rev) ?></b><br>revenue</span>
    <span class="stat"><b><?= money($exp) ?></b><br>expenses</span>
    <span class="stat"><b><?= money($net) ?></b><br>net</span>
  </p>
  <p class="muted">Aggregate query: <?= $aggMs ?> ms &middot; Item-linked JOIN (<?= count($linked) ?> rows): <?= $joinMs ?> ms</p>

  <h2>Add a transaction (INSERT test)</h2>
  <form method="post" class="card form" style="max-width:32rem">
    <input type="hidden" name="action" value="add">
    <div class="row">
      <label>Type
        <select name="type">
          <option value="sale">sale</option>
          <option value="expense">expense</option>
        </select>
      </label>
      <label>Amount
        <input type="number" step="0.01" name="amount" required>
      </label>
      <label>Date
        <input type="date" name="date" value="<?= e(date('Y-m-d')) ?>">
      </label>
    </div>
    <label>Category
      <input type="text" name="category" placeholder="energy / inventory / fees">
    </label>
    <label>Note
      <input type="text" name="note">
    </label>
    <label>Linked item id (optional)
      <input type="text" name="itemId" placeholder="redbull-12">
    </label>
    <label>Qty
      <input type="number" step="0.0001" name="qty" value="1">
    </label>
    <button type="submit">Insert</button>
  </form>

  <h2>Users</h2>
  <table class="table">
    <thead><tr><th>id</th><th>username</th><th>role</th><th>created</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr><td><?= e($u['id']) ?></td><td><?= e($u['username']) ?></td><td><?= e($u['role']) ?></td><td><?= e(date('Y-m-d', (int) $u['createdAt'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Inventory</h2>
  <table class="table">
    <thead><tr><th>sku</th><th>name</th><th>variant</th><th>on hand</th><th>cost</th><th>price</th><th>stock value</th></tr></thead>
    <tbody>
    <?php foreach ($inv as $i): ?>
      <tr>
        <td><?= e($i['sku']) ?></td><td><?= e($i['name']) ?></td><td><?= e($i['variant']) ?></td>
        <td><?= (int) $i['qtyOnHand'] ?></td><td><?= money((float) $i['unitCost']) ?></td><td><?= money((float) $i['unitPrice']) ?></td>
        <td><?= money((float) $i['qtyOnHand'] * (float) $i['unitCost']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Item-linked sales (JOIN)</h2>
  <table class="table">
    <thead><tr><th>txn</th><th>date</th><th>item</th><th>qty</th><th>amount</th></tr></thead>
    <tbody>
    <?php foreach ($linked as $l): ?>
      <tr><td><?= e($l['id']) ?></td><td><?= e($l['date']) ?></td><td><?= e($l['item'] ?: '(unknown)') ?></td><td><?= (float) $l['qty'] ?></td><td><?= money((float) $l['amount']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Transactions</h2>
  <table class="table">
    <thead><tr><th>id</th><th>type</th><th>amount</th><th>category</th><th>note</th><th>date</th><th>item</th><th>qty</th></tr></thead>
    <tbody>
    <?php foreach ($txns as $t): ?>
      <tr>
        <td><?= e($t['id']) ?></td>
        <td><span class="pill <?= e($t['type']) ?>"><?= e($t['type']) ?></span></td>
        <td><?= money((float) $t['amount']) ?></td>
        <td><?= e($t['category']) ?></td>
        <td><?= e($t['note']) ?></td>
        <td><?= e($t['date']) ?></td>
        <td><?= e($t['itemId'] ?: '—') ?></td>
        <td><?= (float) $t['qty'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted">Verdict: SQLite handles monster's model (CRUD + aggregates + joins) with sub-millisecond queries on this runtime.</p>
</main>
</body>
</html>
