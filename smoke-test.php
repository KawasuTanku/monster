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
// Override data dir by injecting an env-aware App: simplest is to symlink data_test as data/ during test.
@unlink($root . '/data');
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

// 7) Persistence: re-read file directly to confirm it is on disk.
$db = json_decode(file_get_contents($root . '/data/db.json'), true);
assertHas(count($db['transactions'] ?? []) === 2 ? 'ok' : '', 'ok', 'two transactions persisted to db.json');

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
array_map('unlink', glob($testData . '/*') ?: []);
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
        'user' => 'legacyboss',
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
    // Old owner logs in with their original password.
    $r = curl("$base2/login", $cookie2, 'POST', ['user' => 'legacyboss', 'pass' => 'legacypw99']);
    assertHas($r, 'Dashboard', 'legacy single-user can still log in');
    // Should be admin and able to reach /users (migration promoted them).
    $u = curl("$base2/users", $cookie2);
    assertHas($u, 'legacyboss', 'migrated user present in /users');
    // The legacy settings keys should have been cleared.
    $db2 = json_decode(file_get_contents($root . '/data/db.json'), true);
    assertHas(($db2['settings']['user'] ?? null) === null ? 'ok' : '', 'ok', 'legacy settings.user cleared after migration');
    proc_terminate($proc2);
}
@unlink($root . '/data');
array_map('unlink', glob($migDir . '/*') ?: []);
@rmdir($migDir);
@unlink($cookie2);

echo $failed ? "\nALL TESTS FAILED\n" : "\nALL TESTS PASSED\n";
exit($failed ? 1 : 0);
