<?php
declare(strict_types=1);
/**
 * Headless end-to-end smoke test for the Monster P&L tracker.
 * Boots the built-in PHP server, then drives the full flow with curl:
 *   setup account -> login -> add sale + expense -> dashboard -> report -> logout
 * Asserts expected markers appear in the rendered HTML. Exits non-zero on failure.
 */

$root = __DIR__;
$port = 8137;
$host = "127.0.0.1:$port";
$base = "http://$host";
$cookie = tempnam(sys_get_temp_dir(), 'monster_cookie_');

// Use a throwaway data dir so we don't touch the real db.json.
$testData = $root . '/data_test';
@mkdir($testData, 0o750, true);
putenv("MONSTER_DATA_DIR=$testData");

$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
// Point the app's hardcoded data/ at our throwaway dir. Remove any existing
// data/ or symlink first so the symlink is reliably created each run.
if (is_link($root . '/data')) {
    @unlink($root . '/data');
} elseif (is_dir($root . '/data')) {
    array_map('unlink', glob($root . '/data/*') ?: []);
    @rmdir($root . '/data');
}
symlink($testData, $root . '/data');

$proc = proc_open(PHP_BINARY . " -S $host " . escapeshellarg($root . '/web/index.php'), $descriptor, $pipes);
if (!is_resource($proc)) { fwrite(STDERR, "FAIL: could not start server\n"); exit(1); }

// Wait for server to accept connections.
$ok = false;
for ($i = 0; $i < 40; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
    if ($c) { fclose($c); $ok = true; break; }
    usleep(100_000);
}
if (!$ok) { fwrite(STDERR, "FAIL: server did not start\n"); proc_terminate($proc); exit(1); }

function curl(string $url, string $cookie, string $method = 'GET', ?array $post = null, array $headers = []): string
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    $out = curl_exec($ch);
    if ($out === false) { fwrite(STDERR, "curl error: " . curl_error($ch) . "\n"); }
    curl_close($ch);
    return (string) $out;
}

function assertHas(string $haystack, string $needle, string $label): void
{
    if (str_contains($haystack, $needle)) {
        echo "  ok  - $label\n";
    } else {
        fwrite(STDERR, "  FAIL- $label (missing: $needle)\n");
        global $failed; $failed = true;
    }
}

function assertMissing(string $haystack, string $needle, string $label): void
{
    if (str_contains($haystack, $needle)) {
        fwrite(STDERR, "  FAIL- $label (unexpected: $needle)\n");
        global $failed; $failed = true;
    } else {
        echo "  ok  - $label\n";
    }
}

$failed = false;
echo "Running smoke test against $base\n";

// 1) Root redirects to /login when not configured.
$r = curl("$base/", $cookie);
assertHas($r, '/login', 'root serves page (unconfigured)');

// 2) Setup the account.
$r = curl("$base/setup", $cookie, 'POST', ['user' => 'john', 'pass' => 'supersecret']);
assertHas($r, 'Dashboard', 'setup creates account and lands on dashboard');

// 3) Add a sale (money in).
// Pull CSRF out of the transactions page first.
$txnPage = curl("$base/transactions", $cookie);
preg_match('/name="csrf" value="([^"]+)"/', $txnPage, $m);
$csrf = $m[1] ?? '';
assertHas($csrf !== '' ? 'has' : '', 'has', 'csrf token present on transactions page');
curl("$base/transactions/save", $cookie, 'POST', [
    'csrf' => $csrf, 'id' => '', 'type' => 'sale', 'amount' => '250.00',
    'date' => '2026-08-10', 'category' => 'Retail', 'note' => 'farmer market',
]);

// 4) Add an expense.
curl("$base/transactions/save", $cookie, 'POST', [
    'csrf' => $csrf, 'id' => '', 'type' => 'expense', 'amount' => '90.50',
    'date' => '2026-08-12', 'category' => 'Wholesale', 'note' => 'cases',
]);

// 4b) REGRESSION: search filters the transactions list (Tier 2 step 1).
$searchHit = curl("$base/transactions?q=" . urlencode('farmer'), $cookie);
assertHas($searchHit, 'farmer market', 'search finds matching note');
assertMissing($searchHit, 'cases', 'search excludes non-matching note');
$searchMiss = curl("$base/transactions?q=" . urlencode('zzzznomatch'), $cookie);
assertHas($searchMiss, 'No transactions recorded yet', 'empty search result shows empty state');

// 5) Dashboard shows correct net ($250.00 - $90.50 = $159.50).
$dash = curl("$base/dashboard", $cookie);
assertHas($dash, '$250.00', 'dashboard shows revenue 250.00');
assertHas($dash, '$90.50', 'dashboard shows expenses 90.50');
assertHas($dash, '$159.50', 'dashboard shows net 159.50');

// 6) Report page lists both entries.
$rep = curl("$base/report", $cookie);
assertHas($rep, 'farmer market', 'report lists sale note');
assertHas($rep, 'cases', 'report lists expense note');
assertHas($rep, '2', 'report transaction count');

// 6a) REGRESSION: report filtering by type + date range works.
$repExp = curl("$base/report?type=expense", $cookie);
assertHas($repExp, 'cases', 'expense filter keeps expense');
assertMissing($repExp, 'farmer market', 'expense filter drops sale');
$repRange = curl("$base/report?from=2026-08-11&to=2026-08-13", $cookie);
assertHas($repRange, 'cases', 'date range keeps 08-12 expense');
assertMissing($repRange, 'farmer market', 'date range drops 08-10 sale');

// 6b) REGRESSION: CSV export returns a parseable CSV with the right rows.
$csvAll = curl("$base/report/export", $cookie);
assertHas($csvAll, 'Date,Type,Category,Amount,Signed,Note', 'export has CSV header');
assertHas($csvAll, 'farmer market', 'export includes sale row');
assertHas($csvAll, 'cases', 'export includes expense row');
// Filtered export returns only the matching rows.
$csvExp = curl("$base/report/export?type=expense", $cookie);
assertHas($csvExp, 'cases', 'filtered export keeps expense');
assertMissing($csvExp, 'farmer market', 'filtered export drops sale');

// 6c) PHASE B: ROI chart + metrics appear on the report page.
// Known data: sale 250.00 (08-10) - expense 90.50 (08-12) = net 159.50, ROI = 159.50/90.50 = 176.24%.
$repRoi = curl("$base/report", $cookie);
assertHas($repRoi, 'Cumulative Net Profit', 'report shows ROI chart section');
assertHas($repRoi, 'class="roi-chart"', 'report renders inline SVG roi-chart');
assertHas($repRoi, 'Monthly breakdown', 'report shows monthly ROI breakdown');
assertHas($repRoi, '176.24%', 'overall ROI computed = 159.50 / 90.50 = 176.24%');
assertHas($repRoi, '$159.50', 'monthly cum net ends at 159.50');
assertHas($repRoi, 'Aug 2026', 'monthly breakdown labels period (Aug 2026)');

// 6d) PHASE B: ROI respects the report filters (type=expense => revenue 0, ROI -100%).
$repRoiExp = curl("$base/report?type=expense", $cookie);
assertHas($repRoiExp, '-100.00%', 'expense-only filter yields -100.00% ROI (no revenue, full cost lost)');

// 7) Persistence: re-read the store directly to confirm it is on disk.
$sqliteFile = $root . '/data/db.sqlite';
$pdo = new \PDO('sqlite:' . $sqliteFile);
$txnCount = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
assertHas($txnCount === 2 ? 'ok' : '', 'ok', 'two transactions persisted to store');

// 8) Logout then protected route should not leak dashboard.
// Use a throwaway jar so the primary admin jar ($cookie) stays authenticated
// for the subsequent multi-user tests.
$jarLogout = tempnam(sys_get_temp_dir(), 'monster_logout_');
curl("$base/setup", $jarLogout, 'POST', ['user' => 'john', 'pass' => 'supersecret']);
curl("$base/logout", $jarLogout, 'POST', []);
$after = curl("$base/dashboard", $jarLogout);
assertHas($after, 'Sign in', 'dashboard blocked after logout');
@unlink($jarLogout);

// 9) Relogin with correct creds works — fresh cookie jar, no prior session.
$jar2 = tempnam(sys_get_temp_dir(), 'monster_cookie2_');
$r = curl("$base/login", $jar2, 'POST', ['user' => 'john', 'pass' => 'supersecret']);
assertHas($r, 'Dashboard', 'relogin succeeds with correct password');

// 10) Wrong password rejected — fresh jar so no session bias.
$jar3 = tempnam(sys_get_temp_dir(), 'monster_cookie3_');
$r2 = curl("$base/login", $jar3, 'POST', ['user' => 'john', 'pass' => 'wrongpass']);
assertHas($r2, 'Invalid credentials', 'wrong password rejected');
@unlink($jar2); @unlink($jar3);

// 11) Admin can open the Users management page and sees john listed.
$usersPage = curl("$base/users", $cookie);
assertHas($usersPage, 'Users', 'admin can reach /users');
assertHas($usersPage, 'john', 'users page lists the admin');

// 12) Admin creates a member (alice).
$csrfU = '';
if (preg_match('/name="csrf" value="([^"]+)"/', $usersPage, $m)) { $csrfU = $m[1]; }
curl("$base/users/create", $cookie, 'POST', ['csrf' => $csrfU, 'user' => 'alice', 'pass' => 'alicepass1', 'role' => 'member']);
$usersAfter = curl("$base/users", $cookie);
assertHas($usersAfter, 'alice', 'users page lists newly created member alice');

// 12a) REGRESSION: admin can reset another user's password.
// Use a throwaway user (bob) so we don't disturb alice, who is exercised later.
$csrfB = '';
if (preg_match('/name="csrf" value="([^"]+)"/', $usersAfter, $m)) { $csrfB = $m[1]; }
curl("$base/users/create", $cookie, 'POST', ['csrf' => $csrfB, 'user' => 'bob', 'pass' => 'bobpass123', 'role' => 'member']);
// Reset bob's password to a new value.
curl("$base/users/reset", $cookie, 'POST', ['csrf' => $csrfB, 'user' => 'bob', 'pass' => 'bobNEWpass9']);
// Bob must now log in with the NEW password (fresh jar).
$jarB = tempnam(sys_get_temp_dir(), 'monster_bob_');
$rB = curl("$base/login", $jarB, 'POST', ['user' => 'bob', 'pass' => 'bobNEWpass9']);
assertHas($rB, 'Dashboard', 'bob logs in with admin-reset password');
@unlink($jarB);
// The OLD password must be rejected — checked with a SEPARATE fresh jar so we
// don't accidentally carry a live session (which would redirect /login -> Dashboard).
$jarBold = tempnam(sys_get_temp_dir(), 'monster_bold_');
$rBold = curl("$base/login", $jarBold, 'POST', ['user' => 'bob', 'pass' => 'bobpass123']);
assertHas($rBold, 'Invalid credentials', 'bob old password rejected after reset');
@unlink($jarBold);

// 12b) REGRESSION: admin's dashboard must show the Users nav link (isAdmin injected).
$dashAdmin = curl("$base/dashboard", $cookie);
assertHas($dashAdmin, 'href="/users"', 'dashboard shows Users nav link for admin');

// 12c) REGRESSION: clicking an edit link must open the edit form, not a login page.
// Grab a transaction id from the transactions page, then hit its edit URL.
$txnList = curl("$base/transactions", $cookie);
preg_match('/href="\/transactions\?edit=([^"]+)"/', $txnList, $mt);
$editId = $mt[1] ?? '';
assertHas($editId !== '' ? 'ok' : '', 'ok', 'transactions page has edit links');
$editPage = curl("$base/transactions?edit=" . urlencode($editId), $cookie);
assertHas($editPage, 'Save changes', 'edit link opens the edit form (not login)');
assertMissing($editPage, 'Sign in', 'edit page is not a login redirect');

// 12c-2) REGRESSION: duplicate a transaction -> new entry dated today, count +1.
$beforePage = curl("$base/transactions", $cookie);
$beforeCount = substr_count($beforePage, 'row-actions');
$csrfDup = '';
if (preg_match('/name="csrf" value="([^"]+)"/', $beforePage, $mD)) { $csrfDup = $mD[1]; }
$dupResp = curl("$base/transactions/duplicate", $cookie, 'POST', ['csrf' => $csrfDup, 'id' => $editId]);
assertHas($dupResp, 'Duplicated as a new entry', 'duplicate creates a new entry');
$afterPage = curl("$base/transactions", $cookie);
$afterCount = substr_count($afterPage, 'row-actions');
assertHas($afterCount > $beforeCount ? 'ok' : '', 'ok', 'transaction count increased after duplicate');
$root2 = $root;
$newTotal = (int) (new \PDO('sqlite:' . $root2 . '/data/db.sqlite'))->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
assertHas($newTotal >= 3 ? 'ok' : '', 'ok', 'duplicate persisted to store (count=' . $newTotal . ')');

// 12c-3) REGRESSION: admin can set monthly budgets and the report shows budget vs actual.
$settingsPage = curl("$base/settings", $cookie);
assertHas($settingsPage, 'Monthly budgets', 'admin sees budgets editor');
preg_match('/name="csrf" value="([^"]+)"/', $settingsPage, $mBud);
$csrfBud = $mBud[1] ?? '';
// Save a budget for the "Wholesale" category (one of the seeded categories).
curl("$base/settings/budgets", $cookie, 'POST', [
    'csrf' => $csrfBud,
    'cat' => ['Wholesale', ''],
    'amt' => ['100.00', ''],
]);
$budRep = curl("$base/report", $cookie);
assertHas($budRep, 'Budget vs actual', 'report shows budget vs actual section');
assertHas($budRep, 'Wholesale', 'report budget section lists the budgeted category');
assertHas($budRep, '$100.00', 'report shows the saved Wholesale budget of 100.00');
assertHas(preg_match('/under|over/', $budRep) ? 'ok' : '', 'ok', 'report budget variance column renders (under/over)');

// 12d) REGRESSION: admin can back up, download, and restore.
$backupPage = curl("$base/backup", $cookie);
assertHas($backupPage, 'Backups', 'admin can reach /backup');
assertHas($backupPage, 'href="/dashboard"', 'backups page links back to dashboard');
$csrfBk = '';
if (preg_match('/name="csrf" value="([^"]+)"/', $backupPage, $m)) { $csrfBk = $m[1]; }
// Create a manual backup.
curl("$base/backup/create", $cookie, 'POST', ['csrf' => $csrfBk]);
$backupPage2 = curl("$base/backup", $cookie);
assertHas($backupPage2, 'monster-', 'manual backup listed after create');
// Download it (follow nothing; just confirm we get JSON back).
$dl = curl("$base/backup/download", $cookie);
assertHas($dl, 'transactions', 'backup download returns store JSON');
// Restore is exercised by posting the first listed backup file name.
if (preg_match('/href="\/backup\/download\?file=([^"]+)"/', $backupPage2, $m2)) {
    $fname = rawurldecode($m2[1]);
    // The restore route 302-redirects to /backup and flashes "Restored from …".
    // Capture the followed (final) response, which is where the flash renders —
    // a separate GET after this would find the flash already consumed.
    $after = curl("$base/backup/restore", $cookie, 'POST', ['csrf' => $csrfBk, 'file' => $fname]);
    assertHas($after, 'Restored from', 'restore reports success');
}
// 12d-2) REGRESSION: admin can delete a backup.
// Create a backup, take the NEWEST one from the list (first = newest mtime),
// delete it, confirm it's gone. (The daily snapshot is oldest, so avoid end().)
curl("$base/backup/create", $cookie, 'POST', ['csrf' => $csrfBk]);
$listNow = curl("$base/backup", $cookie);
preg_match_all('/href="\/backup\/download\?file=([^"]+)"/', $listNow, $mNow);
$names = $mNow[1] ?? [];
$toDelete = rawurldecode(reset($names) ?: '');
if ($toDelete !== '') {
    $delResp = curl("$base/backup/delete", $cookie, 'POST', ['csrf' => $csrfBk, 'file' => $toDelete]);
    assertHas($delResp, 'Deleted backup', 'admin can delete a backup');
    $afterDelete = curl("$base/backup", $cookie);
    assertMissing($afterDelete, rawurlencode($toDelete), 'deleted backup no longer listed');
}

// 12e) REGRESSION: inventory add / adjust / low-stock / restock->COGS / linked sale / delete.
$invPage = curl("$base/inventory", $cookie);
assertHas($invPage, 'Inventory', 'admin can reach /inventory');
$csrfInv = '';
if (preg_match('/name="csrf" value="([^"]+)"/', $invPage, $m)) { $csrfInv = $m[1]; }
// Add an item with a low reorder threshold.
$saveResp = curl("$base/inventory/save", $cookie, 'POST', [
    'csrf' => $csrfInv, 'id' => '', 'name' => 'Original', 'variant' => '12-pack',
    'sku' => 'ED-ORG', 'qtyOnHand' => '3', 'unitCost' => '14.00', 'unitPrice' => '24.00',
    'reorderAt' => '5', 'supplier' => 'Acme',
]);
$invAfter = curl("$base/inventory", $cookie);
// Assert a real table row exists (match the SKU cell, not the form placeholder).
assertHas($invAfter, '(ED-ORG)', 'inventory item listed after add');
assertHas($invAfter, '$42.00', 'stock value = 3 x 14 = 42');
// Grab the item id for later restock + linked-sale tests.
preg_match('/href="\/inventory\?edit=([^"]+)"/', $invAfter, $mi);
$invId = $mi[1] ?? '';
// Drop below threshold via -1 adjust, expect low-stock flag.
curl("$base/inventory/adjust", $cookie, 'POST', ['csrf' => $csrfInv, 'id' => $invId, 'delta' => '-1']);
$invLow = curl("$base/inventory", $cookie);
assertHas($invLow, 'Low stock', 'low-stock stat increments when qty drops below reorderAt');

// 12f) Restock 12 cans -> stock rises and a COGS expense (12 x $14 = $168) is logged.
curl("$base/inventory/restock", $cookie, 'POST', ['csrf' => $csrfInv, 'id' => $invId, 'qty' => '12']);
$invRestock = curl("$base/inventory", $cookie);
assertHas($invRestock, '$196.00', 'stock value = 14 x 14 = 196 after restock of 12');
$repRestock = curl("$base/report", $cookie);
assertHas($repRestock, 'Restock 12', 'restock auto-logged a COGS expense (note mentions Restock 12)');
assertHas($repRestock, '$168.00', 'restock COGS = 12 x 14 = 168 logged');

// 12g) Linked sale of 2 cans -> revenue logged and stock decremented by 2.
$txnPage = curl("$base/transactions", $cookie);
preg_match('/name="csrf" value="([^"]+)"/', $txnPage, $mT);
$csrfT = $mT[1] ?? '';
curl("$base/transactions/save", $cookie, 'POST', [
    'csrf' => $csrfT, 'id' => '', 'type' => 'sale', 'amount' => '48.00',
    'date' => '2026-08-14', 'category' => 'Retail', 'note' => 'linked sale',
    'itemId' => $invId, 'qty' => '2',
]);
$invSale = curl("$base/inventory", $cookie);
assertHas($invSale, '$168.00', 'stock value = 12 x 14 = 168 after linked sale of 2');

// 12h) Linked EXPENSE adds stock (the "restock via transaction" path): record a
// cost-type transaction linked to the item; stock should rise by qty, and the
// amount should be cost x qty. A linked expense reuses the restock->COGS model.
curl("$base/transactions/save", $cookie, 'POST', [
    'csrf' => $csrfT, 'id' => '', 'type' => 'expense', 'amount' => '140.00',
    'date' => '2026-08-14', 'category' => 'Wholesale', 'note' => 'linked restock',
    'itemId' => $invId, 'qty' => '10',
]);
$invExp = curl("$base/inventory", $cookie);
assertHas($invExp, '$308.00', 'stock value = 22 x 14 = 308 after linked expense of 10');
$repExp = curl("$base/report", $cookie);
assertHas($repExp, 'linked restock', 'linked expense appears in report');
assertHas($repExp, '$140.00', 'linked expense amount = 10 x 14 = 140 logged');

// Delete the linked expense -> stock should drop back by 10 (22 -> 12).
// Resolve the transaction id from the store directly (immune to list ordering /
// date-sorting), rather than scraping the HTML table.
$pdoMig = new \PDO('sqlite:' . $root . '/data_test/db.sqlite');
$expId = (string) ($pdoMig->query("SELECT id FROM transactions WHERE note = 'linked restock' AND itemId <> '' LIMIT 1")->fetchColumn() ?? '');
if ($expId !== '') {
    curl("$base/transactions/delete", $cookie, 'POST', ['csrf' => $csrfT, 'id' => $expId]);
}
$invExpDel = curl("$base/inventory", $cookie);
assertHas($invExpDel, '$168.00', 'stock value back to 12 x 14 = 168 after deleting linked expense');

// Delete it.
curl("$base/inventory/delete", $cookie, 'POST', ['csrf' => $csrfInv, 'id' => $invId]);
$invDel = curl("$base/inventory", $cookie);
assertMissing($invDel, '(ED-ORG)', 'inventory item removed after delete');

// 13) Member alice can log in and record a transaction.
$jarA = tempnam(sys_get_temp_dir(), 'monster_alice_');
$rA = curl("$base/login", $jarA, 'POST', ['user' => 'alice', 'pass' => 'alicepass1']);
assertHas($rA, 'Dashboard', 'member alice can log in');

// Pull CSRF from alice's transactions page and add a sale.
$txnA = curl("$base/transactions", $jarA);
preg_match('/name="csrf" value="([^"]+)"/', $txnA, $mA);
$csrfA = $mA[1] ?? '';
curl("$base/transactions/save", $jarA, 'POST', [
    'csrf' => $csrfA, 'id' => '', 'type' => 'sale', 'amount' => '10.00',
    'date' => '2026-08-15', 'category' => 'Retail', 'note' => 'alice sale',
]);
$repA = curl("$base/report", $jarA);
assertHas($repA, 'alice sale', 'member alice transaction visible on report');

// 14) Member alice is blocked from /users (admin-only).
$rBlock = curl("$base/users", $jarA);
assertHas($rBlock, 'Forbidden', 'member blocked from /users');
@unlink($jarA);

// 15) REGRESSION: brute-force protection locks an account after MAX_ATTEMPTS.
// Use a fresh jar so we don't disturb the admin session used above.
$jarL = tempnam(sys_get_temp_dir(), 'monster_lock_');
$lockMsgSeen = false;
for ($i = 0; $i < 7; $i++) {
    $r = curl("$base/login", $jarL, 'POST', ['user' => 'john', 'pass' => 'wrong' . $i]);
    if (str_contains($r, 'Too many failed attempts')) {
        $lockMsgSeen = true;
        break;
    }
}
assertHas($lockMsgSeen ? 'ok' : '', 'ok', 'account locks after repeated failures');
// While locked, the correct password must also be rejected.
$lockedStill = curl("$base/login", $jarL, 'POST', ['user' => 'john', 'pass' => 'supersecret']);
assertHas($lockedStill, 'Too many failed attempts', 'correct password rejected while locked');
@unlink($jarL);

proc_terminate($proc);
@unlink($root . '/data');
foreach (glob($testData . '/*') ?: [] as $f) {
    if (is_file($f)) { @unlink($f); }
}
@rmdir($testData . '/backups');
@rmdir($testData);
@unlink($cookie);

echo $failed ? "\nSMOKE TEST FAILED\n" : "\nSMOKE TEST PASSED\n";

// ---------------------------------------------------------------------------
// Legacy migration sub-test: a db.json from the single-user era (settings.user /
// settings.password_hash) must still authenticate the original owner, and that
// owner must now be an admin. Boot a fresh server pointed at a seeded db.json.
// ---------------------------------------------------------------------------
$migDir = $root . '/data_mig';
@mkdir($migDir, 0o750, true);
$legacy = [
    'settings' => [
        'user' => 'LegacyBoss',
        'password_hash' => password_hash('legacypw99', PASSWORD_BCRYPT, ['cost' => 13]),
    ],
    'transactions' => [],
];
file_put_contents($migDir . '/db.json', json_encode($legacy, JSON_PRETTY_PRINT));
@unlink($root . '/data');
symlink($migDir, $root . '/data');

$port2 = 8138;
$host2 = "127.0.0.1:$port2";
$base2 = "http://$host2";
$cookie2 = tempnam(sys_get_temp_dir(), 'monster_mig_');
$proc2 = proc_open(PHP_BINARY . " -S $host2 " . escapeshellarg($root . '/web/index.php'), $descriptor, $pipes2);
$ok2 = false;
for ($i = 0; $i < 40; $i++) {
    $c = @fsockopen('127.0.0.1', $port2, $errno, $errstr, 0.3);
    if ($c) { fclose($c); $ok2 = true; break; }
    usleep(100_000);
}
if (!$ok2) { fwrite(STDERR, "FAIL: migration server did not start\n"); $failed = true; }
else {
    echo "\nRunning legacy migration sub-test\n";
    // Old owner logs in with their original password, typed in LOWERCASE
    // (regression: case-insensitive username matching).
    $r = curl("$base2/login", $cookie2, 'POST', ['user' => 'legacyboss', 'pass' => 'legacypw99']);
    assertHas($r, 'Dashboard', 'legacy single-user can still log in (lowercase vs mixed-case stored)');
    // Should be admin and able to reach /users (migration promoted them).
    $u = curl("$base2/users", $cookie2);
    assertHas($u, 'legacyboss', 'migrated user present in /users (normalized to lowercase)');
    // The legacy settings keys should have been cleared (migrated into the users
    // collection). A cleared setting is stored as NULL (or the JSON string
    // 'null'), so either means the migration succeeded.
    $pdoLegacy = new \PDO('sqlite:' . $migDir . '/db.sqlite');
    $rawLegacyUser = $pdoLegacy->query("SELECT value FROM settings WHERE key = 'user'")->fetchColumn();
    $legacyUser = $rawLegacyUser === false ? null : json_decode((string) $rawLegacyUser, true);
    assertHas($legacyUser === null ? 'ok' : '', 'ok', 'legacy settings.user cleared after migration');
    proc_terminate($proc2);
}
// Clean up the data/ symlink (or directory) so a subsequent run starts fresh.
if (is_link($root . '/data')) {
    @unlink($root . '/data');
} elseif (is_dir($root . '/data')) {
    array_map('unlink', glob($root . '/data/*') ?: []);
    @rmdir($root . '/data');
}
foreach (glob($migDir . '/*') ?: [] as $f) {
    if (is_file($f)) { @unlink($f); }
}
@rmdir($migDir . '/backups');
@rmdir($migDir);
@unlink($cookie2);

echo $failed ? "\nALL TESTS FAILED\n" : "\nALL TESTS PASSED\n";
exit($failed ? 1 : 0);
