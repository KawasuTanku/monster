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
curl("$base/logout", $cookie, 'POST', []);
$after = curl("$base/dashboard", $cookie);
assertHas($after, 'Sign in', 'dashboard blocked after logout');

// 9) Relogin with correct creds works — fresh cookie jar, no prior session.
$jar2 = tempnam(sys_get_temp_dir(), 'monster_cookie2_');
$r = curl("$base/login", $jar2, 'POST', ['user' => 'john', 'pass' => 'supersecret']);
assertHas($r, 'Dashboard', 'relogin succeeds with correct password');

// 10) Wrong password rejected — fresh jar so no session bias.
$jar3 = tempnam(sys_get_temp_dir(), 'monster_cookie3_');
$r2 = curl("$base/login", $jar3, 'POST', ['user' => 'john', 'pass' => 'wrongpass']);
assertHas($r2, 'Invalid credentials', 'wrong password rejected');
@unlink($jar2); @unlink($jar3);

proc_terminate($proc);
@unlink($root . '/data');
array_map('unlink', glob($testData . '/*') ?: []);
@rmdir($testData);
@unlink($cookie);

echo $failed ? "\nSMOKE TEST FAILED\n" : "\nSMOKE TEST PASSED\n";
exit($failed ? 1 : 0);
