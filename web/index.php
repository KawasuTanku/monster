<?php
declare(strict_types=1);

// Run the app in US Central so transaction dates, daily snapshots, and backups
// are stamped in the owner's local time regardless of the server's default TZ.
date_default_timezone_set('America/Chicago');

use Monster\App;
use Monster\Auth;
use Monster\Transaction;
use Monster\TransactionRepository;
use Monster\InventoryItem;
use Monster\Customer;
use Monster\TabPayment;

// Session persistence. In FrankenPHP worker mode the default (per-process in-memory)
// session handler does NOT survive across requests — a session written by one worker
// is invisible to the next request. Route sessions to a shared, on-disk files handler
// at a writable path so login state is consistent regardless of which worker serves
// a request. Harmless in traditional (per-request) SAPIs too.
if (session_status() === PHP_SESSION_NONE) {
    $sessDir = __DIR__ . '/../data/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0o750, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
        // 'files' is the safe, dependency-free shared handler; Redis/etc. would need ext.
        ini_set('session.save_handler', 'files');
    }
}

// Hardened session cookie defaults are applied per request inside handle_request()
// (not here) because in FrankenPHP worker mode $_SERVER is populated per request;
// setting them once at boot would pin the 'secure' flag to the first request.
use Monster\InventoryRepository;
use function Monster\e;
use function Monster\csrfToken;
use function Monster\csrfValid;
use function Monster\setFlash;
use function Monster\itemLabel;
use function Monster\money;

require __DIR__ . '/../vendor/autoload.php';

$app = new App(__DIR__ . '/..');
$GLOBALS['app'] = $app;

/**
 * Handle a single HTTP request. In traditional (per-request) SAPIs this runs
 * once per hit; in FrankenPHP worker mode the app boots once and this is called
 * once per request, reusing the same $app (and its SQLite PDO connection).
 */
function handle_request(\Monster\App $app)
{
// Hardened session cookie defaults. MUST run before the first session_start()
// within a request (which happens the moment a view calls csrfToken()/takeFlash(),
// often while rendering a form) — otherwise the session is created with PHP's
// insecure defaults. Computing 'secure' from the request mirrors
// Auth::startSession() but runs early so every session benefits. Per-request (not
// boot) so worker mode picks up the correct $_SERVER each hit.
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Hardened security headers on every response (HSTS, nosniff, frame-deny, CSP…).
\Monster\securityHeaders();

// Per-request cache reset. The repositories memoize all() for the request
// lifetime; in worker mode the same instance spans requests, so clear it here
// to avoid serving store state written by a preceding request.
$app->txns->clearCache();
$app->inv->clearCache();
$app->customers->clearCache();
$app->tabs->clearCache();

// Keep a daily snapshot so no cron job is required. This MUST run per request
// (not at worker boot) or it would fire only once per worker process and go
// stale for the life of the process under worker mode. maybeDailySnapshot() is
// idempotent (guarded by an is_file() check) so the cost is a single stat.
$app->backup->maybeDailySnapshot();

// Resolve route from the original request URI (FrankenPHP php_server rewrites here).
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ---- Public routes ----
if ($uri === '/setup' && !$app->auth->isConfigured()) {
    if ($method === 'POST') {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if ($user === '' || strlen($pass) < 8) {
            return view('login', ['title' => 'Set up', 'error' => 'Username required and password must be at least 8 characters.', 'setup' => true]);
        }
        $app->auth->createUser($user, $pass, Auth::ROLE_ADMIN); // first user = admin
        $app->auth->login($user, $pass);
        header('Location: /dashboard');
        return;
    }
    return view('login', ['title' => 'Set up', 'setup' => true]);
}

if ($uri === '/login') {
    if ($app->auth->check()) { header('Location: /dashboard'); return; }
    if ($method === 'POST') {
        $u = trim($_POST['user'] ?? '');
        $locked = $app->auth->isLocked($u);
        if ($locked !== null) {
            $msg = 'Too many failed attempts. Try again after ' . date('H:i', $locked) . '.';
            return view('login', ['title' => 'Sign in', 'error' => $msg, 'setup' => false]);
        }
        if ($app->auth->login($u, $_POST['pass'] ?? '')) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24)); // rotate token on login
            header('Location: /dashboard'); return;
        }
        return view('login', ['title' => 'Sign in', 'error' => 'Invalid credentials.', 'setup' => false]);
    }
    return view('login', ['title' => 'Sign in', 'setup' => false]);
}

if ($uri === '/logout' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $app->auth->logout();
    }
    header('Location: /login'); return;
}

// ---- Guard everything else ----
if (!$app->auth->check()) {
    if ($uri === '/') { header('Location: /login'); return; }
    http_response_code(401);
    return view('login', ['title' => 'Sign in', 'setup' => $app->auth->isConfigured() === false]);
}

// ---- Authenticated routes ----
$user = $app->auth->user();
$isAdmin = $app->auth->isAdmin($user);

if ($uri === '/' || $uri === '/dashboard') {
    $summary = $app->txns->summary();
    $recent = $app->txns->all();
    $receivables = 0.0;
    foreach ($app->customers->all() as $c) {
        $bal = $c->balance($app->tabs, $app->txns);
        if ($bal > 0) {
            $receivables += $bal;
        }
    }
    // Global restock defaults from settings.
    $safetyDays = (int) ($app->storage->getSetting('safetyDays') ?? 7);
    $coverageDays = (int) ($app->storage->getSetting('coverageDays') ?? 30);
    $lookbackDays = (int) ($app->storage->getSetting('lookbackDays') ?? 30);

    // Units sold per item over the lookback window.
    $unitsSoldMap = [];
    $now = time();
    foreach ($app->inv->all() as $item) {
        $since = $now - ($lookbackDays * 86400);
        $unitsSoldMap[$item->id] = $app->txns->unitsSoldSince($item->id, $since);
    }

    // Low stock items (excluding discontinued) for the dashboard warning.
    $lowItems = array_filter($app->inv->all(), fn($i) => !$i->discontinued && $i->needsReorder($unitsSoldMap[$i->id] ?? 0, $lookbackDays, $safetyDays));

    return view('dashboard', [
        'title' => 'Dashboard', 'user' => $user, 'summary' => $summary, 'recent' => $recent, 'stockValue' => $app->inv->totalStockValue(), 'receivables' => round($receivables, 2),
        'lowItems' => $lowItems,
    ]);
}

if ($uri === '/transactions') {
    $edit = null;
    if (isset($_GET['edit'])) {
        $edit = $app->txns->find($_GET['edit']);
    }
    $filters = [
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'q' => trim($_GET['q'] ?? ''),
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ];
    $paged = $app->txns->paged($filters);
    $items = $app->inv->all();
    return view('transactions', [
        'title' => 'Transactions', 'user' => $user,
        'txns' => $paged['items'], 'edit' => $edit, 'items' => $items,
        'filters' => $filters, 'pager' => $paged,
    ]);
}

if ($uri === '/transactions/save' && $method === 'POST') {
    if (!csrfValid($_POST['csrf'] ?? null)) { http_response_code(403); return view('transactions', ['title' => 'Transactions', 'user' => $user, 'txns' => $app->txns->all(), 'edit' => null, 'items' => $app->inv->all()]); }
    $isEdit = ($_POST['id'] ?? '') !== '';
    $t = $isEdit ? ($app->txns->find($_POST['id']) ?? new Transaction()) : new Transaction();
    $t->id = ($_POST['id'] ?? '') ?: bin2hex(random_bytes(12));
    $t->type = in_array($_POST['type'] ?? '', [Transaction::TYPE_SALE, Transaction::TYPE_EXPENSE], true) ? $_POST['type'] : Transaction::TYPE_SALE;
    $t->amount = max(0.0, (float) ($_POST['amount'] ?? 0));
    $t->category = trim($_POST['category'] ?? '');
    $t->note = trim($_POST['note'] ?? '');
    $t->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : date('Y-m-d');
    if (!$isEdit) {
        $t->createdAt = time();
    }

    // Optional inventory linkage: a new sale linked to an item decrements stock,
    // a new expense linked to an item adds stock (the "restock via transaction" path).
    $linkedItemId = trim($_POST['itemId'] ?? '');
    $linkedQty = max(0.0, (float) ($_POST['qty'] ?? 1));
    if (!$isEdit) {
        $t->itemId = $linkedItemId;
        $t->qty = $linkedQty;
        if ($linkedItemId !== '') {
            $item = $app->inv->find($linkedItemId);
            if ($item !== null) {
                $delta = (int) round($linkedQty);
                if ($t->type === Transaction::TYPE_SALE) {
                    $item->qtyOnHand = max(0, $item->qtyOnHand - $delta);
                } elseif ($t->type === Transaction::TYPE_EXPENSE) {
                    // A linked expense is a restock purchase: the per-can cost
                    // of this batch is the total amount spread over its qty,
                    // blended into unitCost as a weighted average. Compute the
                    // average BEFORE incrementing qtyOnHand so the new units
                    // aren't counted twice.
                    if ($delta > 0) {
                        $batchCost = $linkedQty > 0 ? round($t->amount / $linkedQty, 4) : $t->amount;
                        $item->unitCost = $item->averageCost((float) $delta, $batchCost);
                    }
                    $item->qtyOnHand += $delta;
                }
                if ($delta !== 0) {
                    $app->inv->save($item);
                }
            }
        }
    }

    $app->txns->save($t);
    setFlash('Saved.');
    header('Location: /transactions'); return;
}

if ($uri === '/transactions/delete' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $t = $app->txns->find($_POST['id'] ?? '');
        // Reverse the stock impact when deleting a linked transaction:
        // deleting a linked sale restores the units sold; deleting a linked expense removes the units added.
        if ($t !== null && $t->itemId !== '') {
            $item = $app->inv->find($t->itemId);
            if ($item !== null) {
                $delta = (int) round($t->qty);
                if ($t->type === Transaction::TYPE_SALE) {
                    $item->qtyOnHand += $delta;
                } elseif ($t->type === Transaction::TYPE_EXPENSE) {
                    $item->qtyOnHand = max(0, $item->qtyOnHand - $delta);
                }
                $app->inv->save($item);
            }
        }
        $app->txns->delete($_POST['id'] ?? '');
        setFlash('Deleted.');
    }
    header('Location: /transactions'); return;
}

if ($uri === '/transactions/duplicate' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $src = $app->txns->find($_POST['id'] ?? '');
        if ($src !== null) {
            // Clone as a NEW entry dated today. Inventory linkage is deliberately
            // cleared so a duplicate of a linked sale/expense does not re-touch
            // stock — it's a pure financial copy (ideal for recurring costs).
            $copy = new Transaction();
            $copy->id = bin2hex(random_bytes(12));
            $copy->type = $src->type;
            $copy->amount = $src->amount;
            $copy->category = $src->category;
            $copy->note = $src->note;
            $copy->date = date('Y-m-d');
            $copy->createdAt = time();
            $copy->itemId = '';
            $copy->qty = 1.0;
            $app->txns->save($copy);
            setFlash('Duplicated as a new entry.');
        }
    }
    header('Location: /transactions'); return;
}

if ($uri === '/report') {
    $filters = [
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
    ];
    $txns = $app->txns->filtered($filters);
    $budgets = $app->storage->getSetting('budgets', []);
    // Actual expenses per category over the current filter window.
    $actualByCategory = [];
    foreach ($txns as $t) {
        if ($t->type === Transaction::TYPE_EXPENSE && $t->category !== '') {
            $actualByCategory[$t->category] = ($actualByCategory[$t->category] ?? 0.0) + $t->amount;
        }
    }
    return view('report', [
        'title' => 'Report', 'user' => $user, 'isAdmin' => $isAdmin,
        'summary' => $app->txns->summary(),
        'txns' => $txns,
        'categories' => $app->txns->categories(),
        'filters' => $filters,
        'roiSeries' => $app->txns->roiSeries($filters),
        'roiOverall' => $app->txns->roiOverall($filters),
        'items' => $app->inv->all(),
        'budgets' => $budgets,
        'actualByCategory' => $actualByCategory,
    ]);
}

if ($uri === '/report/reorder' && $method === 'GET') {
    // Items needing reorder: either manual reorderAt triggered OR velocity-based.
    $safetyDays = (int) ($app->storage->getSetting('safetyDays') ?? 7);
    $coverageDays = (int) ($app->storage->getSetting('coverageDays') ?? 30);
    $lookbackDays = (int) ($app->storage->getSetting('lookbackDays') ?? 30);
    $now = time();

    $reorderItems = [];
    $unitsSold = [];
    foreach ($app->inv->all() as $item) {
        if ($item->discontinued) {
            continue;
        }
        $since = $now - ($lookbackDays * 86400);
        $u = $app->txns->unitsSoldSince($item->id, $since);
        $unitsSold[$item->id] = $u;
        if ($item->needsReorder($u, $lookbackDays, $safetyDays)) {
            $reorderItems[] = $item;
        }
    }

    return view('reorder', [
        'title' => 'Needs Reorder', 'user' => $user, 'isAdmin' => $isAdmin,
        'items' => $reorderItems,
        'totalCost' => round(array_sum(array_map(static fn(InventoryItem $i): float => $i->reorderQty() * $i->unitCost, $reorderItems)), 2),
        'unitsSold' => $unitsSold,
        'safetyDays' => $safetyDays,
        'coverageDays' => $coverageDays,
        'lookbackDays' => $lookbackDays,
    ]);
}

if ($uri === '/report/export' && $method === 'GET') {
    $filters = [
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'q' => trim($_GET['q'] ?? ''),
    ];
    $txns = $app->txns->filtered($filters);
    // Stream a CSV of the currently filtered set.
    $filename = 'monster-transactions-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        // UTF-8 BOM so Excel reads special characters correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Type', 'Category', 'Amount', 'Signed', 'Note']);
        foreach ($txns as $t) {
            fputcsv($out, [
                $t->date,
                $t->type,
                $t->category,
                number_format($t->amount, 2, '.', ''),
                number_format($t->signed(), 2, '.', ''),
                $t->note,
            ]);
        }
        fclose($out);
    }
    return;
}

if ($uri === '/report/export-pdf' && $method === 'GET') {
    $filters = [
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
    ];
    $txns = $app->txns->filtered($filters);
    $budgets = $app->storage->getSetting('budgets', []);
    $actualByCategory = [];
    foreach ($txns as $t) {
        if ($t->type === Transaction::TYPE_EXPENSE && $t->category !== '') {
            $actualByCategory[$t->category] = ($actualByCategory[$t->category] ?? 0.0) + $t->amount;
        }
    }
    $pdf = \Monster\ReportPdf::build(
        $app->txns->summary(),
        $app->txns->roiSeries($filters),
        $app->txns->roiOverall($filters),
        $txns,
        $budgets,
        $actualByCategory,
        $filters,
        $user
    );
    $filename = 'monster-report-' . date('Ymd') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    return;
}

if ($uri === '/settings') {
    return view('settings', [
        'title' => 'Settings', 'user' => $user, 'isAdmin' => $isAdmin,
        'configured' => $app->auth->isConfigured(),
        'budgets' => $app->storage->getSetting('budgets', []),
        'categories' => $app->txns->categories(),
        'restockDefaults' => [
            'safetyDays' => (int) ($app->storage->getSetting('safetyDays') ?? 7),
            'coverageDays' => (int) ($app->storage->getSetting('coverageDays') ?? 30),
            'lookbackDays' => (int) ($app->storage->getSetting('lookbackDays') ?? 30),
        ],
    ]);
}

if ($uri === '/settings/password' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null) && strlen($_POST['pass'] ?? '') >= 8) {
        $app->auth->setPassword($user, $_POST['pass']);
        setFlash('Password changed.');
    }
    header('Location: /settings'); return;
}

if ($uri === '/settings/reset' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $app->txns->deleteAll();
        setFlash('All transactions deleted.');
    }
    header('Location: /settings'); return;
}

if ($uri === '/settings/budgets' && $method === 'POST') {
    if (!$isAdmin) { http_response_code(403); header('Location: /settings'); return; }
    if (csrfValid($_POST['csrf'] ?? null)) {
        // Build a map of category => monthly budget from the submitted rows.
        $map = [];
        $cats = $_POST['cat'] ?? [];
        $amts = $_POST['amt'] ?? [];
        foreach ($cats as $i => $c) {
            $c = trim($c);
            $a = (float) ($amts[$i] ?? 0);
            if ($c !== '' && $a > 0) {
                $map[$c] = round($a, 2);
            }
        }
        $app->storage->setSetting('budgets', $map);
        setFlash('Budgets saved.');
    }
    header('Location: /settings'); return;
}

if ($uri === '/settings/restock-defaults' && $method === 'POST') {
    if (!$isAdmin) { http_response_code(403); header('Location: /settings'); return; }
    if (csrfValid($_POST['csrf'] ?? null)) {
        $safetyDays = max(1, (int) ($_POST['safetyDays'] ?? 7));
        $coverageDays = max(1, (int) ($_POST['coverageDays'] ?? 30));
        $lookbackDays = max(1, (int) ($_POST['lookbackDays'] ?? 30));
        $app->storage->setSetting('safetyDays', $safetyDays);
        $app->storage->setSetting('coverageDays', $coverageDays);
        $app->storage->setSetting('lookbackDays', $lookbackDays);
        setFlash('Restock defaults saved.');
    }
    header('Location: /settings'); return;
}

// ---- Admin-only: user management ----
if (str_starts_with($uri, '/users')) {
    if (!$isAdmin) {
        http_response_code(403);
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Forbidden</title></head>"
            . "<body><h1>403 Forbidden</h1><p>Admin access required.</p></body></html>";
        return;
    }
    if ($uri === '/users' && $method === 'GET') {
        return view('users', [
            'title' => 'Users', 'user' => $user, 'isAdmin' => $isAdmin,
            'users' => $app->auth->users(), 'me' => $user,
        ]);
    }
    if ($uri === '/users/create' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null)) {
            try {
                $app->auth->createUser(
                    $_POST['user'] ?? '',
                    $_POST['pass'] ?? '',
                    ($_POST['role'] ?? Auth::ROLE_MEMBER) === Auth::ROLE_ADMIN ? Auth::ROLE_ADMIN : Auth::ROLE_MEMBER
                );
                setFlash('User created.');
            } catch (\InvalidArgumentException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /users'); return;
    }
    if ($uri === '/users/role' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null)) {
            try {
                $app->auth->setRole($_POST['user'] ?? '', $_POST['role'] ?? Auth::ROLE_MEMBER);
                setFlash('Role updated.');
            } catch (\InvalidArgumentException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /users'); return;
    }
    if ($uri === '/users/delete' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null) && ($_POST['user'] ?? '') !== $user) {
            try {
                $app->auth->deleteUser($_POST['user'] ?? '');
                setFlash('User deleted.');
            } catch (\InvalidArgumentException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /users'); return;
    }
    if ($uri === '/users/reset' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null) && ($_POST['user'] ?? '') !== $user) {
            try {
                $app->auth->adminResetPassword($_POST['user'] ?? '', $_POST['pass'] ?? '');
                setFlash('Password reset for ' . $_POST['user'] . '.');
            } catch (\InvalidArgumentException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /users'); return;
    }
}

// ---- Admin-only: backups ----
if (str_starts_with($uri, '/backup')) {
    if (!$isAdmin) {
        http_response_code(403);
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Forbidden</title></head>"
            . "<body><h1>403 Forbidden</h1><p>Admin access required.</p></body></html>";
        return;
    }
    if ($uri === '/backup' && $method === 'GET') {
        return view('backup', [
            'title' => 'Backups', 'user' => $user, 'isAdmin' => $isAdmin,
            'backups' => $app->backup->list(),
            'storagePath' => $app->storage->path(),
        ]);
    }
    if ($uri === '/backup/upload' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null) && isset($_FILES['backup']) && is_uploaded_file($_FILES['backup']['tmp_name'] ?? '')) {
            $tmp = $_FILES['backup']['tmp_name'];
            $raw = file_get_contents($tmp);
            if ($raw !== false) {
                try {
                    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($data) && isset($data['transactions']) && isset($data['inventory'])) {
                        // Snapshot current before importing.
                        $app->backup->create();
                        $app->storage->loadDump($data);
                        setFlash('Backup imported successfully.');
                    } else {
                        setFlash('Invalid backup file: missing expected data.');
                    }
                } catch (\JsonException) {
                    setFlash('Invalid backup file: not valid JSON.');
                }
            }
        }
        header('Location: /backup'); return;
    }
    if ($uri === '/backup/download' && $method === 'GET') {
        // Download the most recent backup (or a specific one via ?file=).
        $file = $_GET['file'] ?? null;
        $path = $file !== null
            ? $app->backup->dir() . '/' . basename($file)
            : $app->backup->latest();
        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'No backup available.';
            return;
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        return;
    }
    if ($uri === '/backup/create' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null)) {
            try {
                $p = $app->backup->create();
                setFlash('Backup created: ' . basename($p));
            } catch (\RuntimeException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /backup'); return;
    }
    if ($uri === '/backup/restore' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null)) {
            $src = $_POST['file'] ?? '';
            try {
                $app->backup->restore($app->storage, $app->backup->dir() . '/' . basename($src));
                setFlash('Restored from ' . basename($src) . '.');
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /backup'); return;
    }
    if ($uri === '/backup/delete' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null)) {
            $name = basename($_POST['file'] ?? '');
            try {
                $app->backup->delete($name);
                setFlash('Deleted backup ' . $name . '.');
            } catch (\InvalidArgumentException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /backup'); return;
    }
}

// ---- Tabs (all authenticated users) ----
if ($uri === '/tabs' && $method === 'GET') {
    $customerBalances = [];
    foreach ($app->customers->all() as $c) {
        $customerBalances[$c->id] = $c->balance($app->tabs, $app->txns);
    }
    return view('tabs', [
        'title' => 'Tabs', 'user' => $user, 'isAdmin' => $isAdmin,
        'customers' => $app->customers->all(),
        'balances' => $customerBalances,
    ]);
}

if ($uri === '/tabs/new' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $c = new Customer();
            $c->id = 'cust_' . bin2hex(random_bytes(8));
            $c->name = $name;
            $c->note = trim($_POST['note'] ?? '');
            $c->createdAt = time();
            $app->customers->save($c);
            setFlash('Customer added.');
        }
    }
    header('Location: /tabs'); return;
}

if (preg_match('#^/tabs/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'GET') {
    $customer = $app->customers->find($m[1]);
    if ($customer === null) {
        http_response_code(404);
        return view('tabs', ['title' => 'Not found', 'user' => $user, 'isAdmin' => $isAdmin, 'customers' => $app->customers->all(), 'balances' => []]);
    }
    $charges = $app->txns->forCustomer($customer->id);
    $payments = $app->tabs->forCustomer($customer->id);
    return view('tab-detail', [
        'title' => $customer->name, 'user' => $user, 'isAdmin' => $isAdmin,
        'customer' => $customer,
        'charges' => $charges,
        'payments' => $payments,
        'balance' => $customer->balance($app->tabs, $app->txns),
        'items' => $app->inv->all(),
    ]);
}

if (preg_match('#^/tabs/([a-zA-Z0-9_-]+)/charge$#', $uri, $m) && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $customer = $app->customers->find($m[1]);
        if ($customer !== null) {
            $linkedItemId = trim($_POST['itemId'] ?? '');
            $linkedQty = max(0.0, (float) ($_POST['qty'] ?? 1));
            $amount = max(0.0, (float) ($_POST['amount'] ?? 0));
            $t = new Transaction();
            $t->id = bin2hex(random_bytes(12));
            $t->type = Transaction::TYPE_SALE;
            $t->amount = $amount;
            $t->category = trim($_POST['category'] ?? 'Retail');
            $t->note = trim($_POST['note'] ?? '');
            $t->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : date('Y-m-d');
            $t->createdAt = time();
            $t->customerId = $customer->id;
            $t->itemId = $linkedItemId;
            $t->qty = $linkedQty;
            if ($linkedItemId !== '') {
                $item = $app->inv->find($linkedItemId);
                if ($item !== null) {
                    $delta = (int) round($linkedQty);
                    $item->qtyOnHand = max(0, $item->qtyOnHand - $delta);
                    if ($delta !== 0) {
                        $app->inv->save($item);
                    }
                }
            }
            $app->txns->save($t);
            setFlash('Charge added.');
        }
    }
    header('Location: /tabs/' . $m[1]); return;
}

if (preg_match('#^/tabs/([a-zA-Z0-9_-]+)/payment$#', $uri, $m) && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $customer = $app->customers->find($m[1]);
        if ($customer !== null) {
            $amount = max(0.0, (float) ($_POST['amount'] ?? 0));
            if ($amount > 0) {
                $p = new TabPayment();
                $p->id = 'pay_' . bin2hex(random_bytes(8));
                $p->customerId = $customer->id;
                $p->amount = $amount;
                $p->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : date('Y-m-d');
                $p->note = trim($_POST['note'] ?? '');
                $p->createdAt = time();
                $app->tabs->save($p);
                setFlash('Payment received.');
            }
        }
    }
    header('Location: /tabs/' . $m[1]); return;
}

if (preg_match('#^/tabs/([a-zA-Z0-9_-]+)/delete$#', $uri, $m) && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $customer = $app->customers->find($m[1]);
        if ($customer !== null) {
            $balance = $customer->balance($app->tabs, $app->txns);
            if (abs($balance) > 0.005) {
                setFlash('Cannot delete: customer still has a balance of $' . money($balance) . '.');
            } else {
                // Delete their payments (charges remain as financial history).
                foreach ($app->tabs->forCustomer($customer->id) as $pay) {
                    $app->tabs->delete($pay->id);
                }
                $app->customers->delete($customer->id);
                setFlash('Customer deleted.');
            }
        }
    }
    header('Location: /tabs'); return;
}

// ---- Inventory (all authenticated users) ----
if ($uri === '/inventory') {
    $edit = null;
    if (isset($_GET['edit'])) {
        $edit = $app->inv->find($_GET['edit']);
    }
    // Global restock defaults from settings.
    $safetyDays = (int) ($app->storage->getSetting('safetyDays') ?? 7);
    $coverageDays = (int) ($app->storage->getSetting('coverageDays') ?? 30);
    $lookbackDays = (int) ($app->storage->getSetting('lookbackDays') ?? 30);

    // Units sold per item over the lookback window.
    $unitsSoldMap = [];
    $now = time();
    foreach ($app->inv->all() as $item) {
        $since = $now - ($lookbackDays * 86400);
        $unitsSoldMap[$item->id] = $app->txns->unitsSoldSince($item->id, $since);
    }

    return view('inventory', [
        'title' => 'Inventory', 'user' => $user, 'isAdmin' => $isAdmin,
        'items' => $app->inv->all(),
        'edit' => $edit,
        'totalValue' => $app->inv->totalStockValue(),
        'lowCount' => count($app->inv->lowStock()),
        'pnl' => $app->txns->perItemPnl(),
        'unitsSold' => $unitsSoldMap,
        'safetyDays' => $safetyDays,
        'coverageDays' => $coverageDays,
        'lookbackDays' => $lookbackDays,
    ]);
}

if ($uri === '/inventory/save' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $id = trim($_POST['id'] ?? '');
        $item = $id !== '' ? ($app->inv->find($id) ?? new InventoryItem()) : new InventoryItem();
        if ($id === '') {
            $item->id = 'inv_' . bin2hex(random_bytes(8));
            $item->createdAt = time();
        }
        $item->sku = trim($_POST['sku'] ?? '');
        $item->name = trim($_POST['name'] ?? '');
        $item->variant = trim($_POST['variant'] ?? '');
        $item->qtyOnHand = max(0, (int) ($_POST['qtyOnHand'] ?? 0));
        $item->unitCost = (float) ($_POST['unitCost'] ?? 0);
        $item->unitPrice = (float) ($_POST['unitPrice'] ?? 0);
        $item->reorderAt = (int) ($_POST['reorderAt'] ?? 0);
        $item->discontinued = isset($_POST['discontinued']) && $_POST['discontinued'] === '1';
        $item->supplier = trim($_POST['supplier'] ?? '');
        if ($item->name !== '') {
            $app->inv->save($item);
            setFlash('Saved.');
        }
    }
    header('Location: /inventory'); return;
}

if ($uri === '/inventory/adjust' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $item = $app->inv->find($_POST['id'] ?? '');
        $delta = (int) ($_POST['delta'] ?? 0);
        if ($item !== null && $delta !== 0) {
            $item->qtyOnHand = max(0, $item->qtyOnHand + $delta);
            $app->inv->save($item);
        }
    }
    header('Location: /inventory'); return;
}

if ($uri === '/inventory/restock' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $item = $app->inv->find($_POST['id'] ?? '');
        $qty = max(0, (int) ($_POST['qty'] ?? 0));
        if ($item !== null && $qty > 0) {
            // Per-can cost for THIS batch; defaults to the item's current
            // unitCost so a plain restock keeps the existing cost when no
            // price is supplied. Weighted-average the new lot into unitCost.
            $batchCost = (float) ($_POST['cost'] ?? $item->unitCost);
            $item->unitCost = $item->averageCost((float) $qty, $batchCost);
            $item->qtyOnHand += $qty;
            $app->inv->save($item);
            // Auto-post the cost of goods as a COGS expense linked to this item.
            $cost = round($batchCost * $qty, 2);
            $t = new Transaction();
            $t->id = bin2hex(random_bytes(12));
            $t->type = Transaction::TYPE_EXPENSE;
            $t->amount = $cost;
            $t->category = 'Wholesale';
            $t->note = 'Restock ' . $qty . ' × ' . itemLabel($item);
            $t->date = date('Y-m-d');
            $t->createdAt = time();
            $t->itemId = $item->id;
            $t->qty = (float) $qty;
            $app->txns->save($t);
            setFlash('Restocked ' . $qty . ' — cost of $' . money($cost) . ' logged.');
        }
    }
    header('Location: /inventory'); return;
}

if ($uri === '/inventory/delete' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $app->inv->delete($_POST['id'] ?? '');
    }
    header('Location: /inventory'); return;
}

http_response_code(404);
return view('login', ['title' => 'Not found', 'user' => $user]);
}

/**
 * FrankenPHP worker-mode dispatch. The app boots once (see the top-level $app)
 * and the same instance — including its SQLite connection and repository caches
 * — is reused across requests. In any other SAPI we just handle the one request.
 *
 * v1.12+ API: frankenphp_handle_request($callback) returns false when the server
 * is stopping, giving the worker a clean exit. The callable runs once per request
 * with $_SERVER/$_GET/etc. reset to that request's values.
 */
if (function_exists('frankenphp_handle_request')) {
    frankenphp_handle_request(static function () use ($app): void {
        handle_request($app);
    });
} else {
    handle_request($app);
}

/**
 * Render a view inside the layout and terminate.
 * @param array<string, mixed> $vars
 */
function view(string $name, array $vars)
{
    $vars['user'] ??= null;
    // Inject isAdmin automatically so the layout's Users link works everywhere.
    if (!array_key_exists('isAdmin', $vars) && isset($GLOBALS['app']) && $vars['user'] !== null) {
        $vars['isAdmin'] = $GLOBALS['app']->auth->isAdmin($vars['user']);
    }
    $vars['isAdmin'] ??= false;

    // Capture the inner view body.
    ob_start();
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../src/views/' . $name . '.php';
    $body = ob_get_clean();

    // Capture the layout wrapping the body.
    ob_start();
    extract($vars + ['body' => $body], EXTR_SKIP);
    require __DIR__ . '/../src/views/layout.php';
    echo ob_get_clean();
    return;
}
