<?php
declare(strict_types=1);

use Monster\App;
use Monster\Auth;
use Monster\Transaction;
use Monster\TransactionRepository;
use Monster\InventoryItem;
use Monster\InventoryRepository;
use function Monster\e;
use function Monster\csrfToken;
use function Monster\csrfValid;
use function Monster\setFlash;

require __DIR__ . '/../vendor/autoload.php';

$app = new App(__DIR__ . '/..');
$GLOBALS['app'] = $app;

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
        exit;
    }
    return view('login', ['title' => 'Set up', 'setup' => true]);
}

if ($uri === '/login') {
    if ($app->auth->check()) { header('Location: /dashboard'); exit; }
    if ($method === 'POST') {
        $u = trim($_POST['user'] ?? '');
        $locked = $app->auth->isLocked($u);
        if ($locked !== null) {
            $msg = 'Too many failed attempts. Try again after ' . date('H:i', $locked) . '.';
            return view('login', ['title' => 'Sign in', 'error' => $msg, 'setup' => false]);
        }
        if ($app->auth->login($u, $_POST['pass'] ?? '')) {
            header('Location: /dashboard'); exit;
        }
        return view('login', ['title' => 'Sign in', 'error' => 'Invalid credentials.', 'setup' => false]);
    }
    return view('login', ['title' => 'Sign in', 'setup' => false]);
}

if ($uri === '/logout' && $method === 'POST') {
    $app->auth->logout();
    header('Location: /login'); exit;
}

// ---- Guard everything else ----
if (!$app->auth->check()) {
    if ($uri === '/') { header('Location: /login'); exit; }
    http_response_code(401);
    return view('login', ['title' => 'Sign in', 'setup' => $app->auth->isConfigured() === false]);
}

// ---- Authenticated routes ----
$user = $app->auth->user();
$isAdmin = $app->auth->isAdmin($user);

if ($uri === '/' || $uri === '/dashboard') {
    $summary = $app->txns->summary();
    $recent = $app->txns->all();
    return view('dashboard', ['title' => 'Dashboard', 'user' => $user, 'summary' => $summary, 'recent' => $recent]);
}

if ($uri === '/transactions') {
    $edit = null;
    if (isset($_GET['edit'])) {
        $edit = $app->txns->find($_GET['edit']);
    }
    return view('transactions', ['title' => 'Transactions', 'user' => $user, 'txns' => $app->txns->all(), 'edit' => $edit]);
}

if ($uri === '/transactions/save' && $method === 'POST') {
    if (!csrfValid($_POST['csrf'] ?? null)) { http_response_code(403); return view('transactions', ['title' => 'Transactions', 'user' => $user, 'txns' => $app->txns->all(), 'edit' => null]); }
    $t = new Transaction();
    $t->id = ($_POST['id'] ?? '') ?: bin2hex(random_bytes(12));
    $t->type = in_array($_POST['type'] ?? '', [Transaction::TYPE_SALE, Transaction::TYPE_EXPENSE], true) ? $_POST['type'] : Transaction::TYPE_SALE;
    $t->amount = max(0.0, (float) ($_POST['amount'] ?? 0));
    $t->category = trim($_POST['category'] ?? '');
    $t->note = trim($_POST['note'] ?? '');
    $t->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : date('Y-m-d');
    $t->createdAt = time();
    $app->txns->save($t);
    setFlash('Saved.');
    header('Location: /transactions'); exit;
}

if ($uri === '/transactions/delete' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $app->txns->delete($_POST['id'] ?? '');
        setFlash('Deleted.');
    }
    header('Location: /transactions'); exit;
}

if ($uri === '/report') {
    $filters = [
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
    ];
    $txns = $app->txns->filtered($filters);
    return view('report', [
        'title' => 'Report', 'user' => $user, 'isAdmin' => $isAdmin,
        'summary' => $app->txns->summary(),
        'txns' => $txns,
        'categories' => $app->txns->categories(),
        'filters' => $filters,
        'roiSeries' => $app->txns->roiSeries($filters),
        'roiOverall' => $app->txns->roiOverall($filters),
    ]);
}

if ($uri === '/report/export' && $method === 'GET') {
    $filters = [
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
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
    exit;
}

if ($uri === '/settings') {
    return view('settings', ['title' => 'Settings', 'user' => $user, 'isAdmin' => $isAdmin, 'configured' => $app->auth->isConfigured()]);
}

if ($uri === '/settings/password' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null) && strlen($_POST['pass'] ?? '') >= 8) {
        $app->auth->setPassword($user, $_POST['pass']);
        setFlash('Password changed.');
    }
    header('Location: /settings'); exit;
}

if ($uri === '/settings/reset' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        foreach ($app->txns->all() as $t) { $app->txns->delete($t->id); }
        setFlash('All transactions deleted.');
    }
    header('Location: /settings'); exit;
}

// ---- Admin-only: user management ----
if (str_starts_with($uri, '/users')) {
    if (!$isAdmin) {
        http_response_code(403);
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Forbidden</title></head>"
            . "<body><h1>403 Forbidden</h1><p>Admin access required.</p></body></html>";
        exit;
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
        header('Location: /users'); exit;
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
        header('Location: /users'); exit;
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
        header('Location: /users'); exit;
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
        header('Location: /users'); exit;
    }
}

// ---- Admin-only: backups ----
if (str_starts_with($uri, '/backup')) {
    if (!$isAdmin) {
        http_response_code(403);
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Forbidden</title></head>"
            . "<body><h1>403 Forbidden</h1><p>Admin access required.</p></body></html>";
        exit;
    }
    if ($uri === '/backup' && $method === 'GET') {
        return view('backup', [
            'title' => 'Backups', 'user' => $user, 'isAdmin' => $isAdmin,
            'backups' => $app->backup->list(),
            'storagePath' => $app->storage->path(),
        ]);
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
            exit;
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
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
        header('Location: /backup'); exit;
    }
    if ($uri === '/backup/restore' && $method === 'POST') {
        if (csrfValid($_POST['csrf'] ?? null)) {
            $src = $_POST['file'] ?? '';
            try {
                $app->backup->restore($app->backup->dir() . '/' . basename($src));
                setFlash('Restored from ' . basename($src) . '.');
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                setFlash($e->getMessage());
            }
        }
        header('Location: /backup'); exit;
    }
}

// ---- Inventory (all authenticated users) ----
if ($uri === '/inventory') {
    $edit = null;
    if (isset($_GET['edit'])) {
        $edit = $app->inv->find($_GET['edit']);
    }
    return view('inventory', [
        'title' => 'Inventory', 'user' => $user, 'isAdmin' => $isAdmin,
        'items' => $app->inv->all(),
        'edit' => $edit,
        'totalValue' => $app->inv->totalStockValue(),
        'lowCount' => count($app->inv->lowStock()),
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
        $item->qtyOnHand = (int) ($_POST['qtyOnHand'] ?? 0);
        $item->unitCost = (float) ($_POST['unitCost'] ?? 0);
        $item->unitPrice = (float) ($_POST['unitPrice'] ?? 0);
        $item->reorderAt = (int) ($_POST['reorderAt'] ?? 0);
        $item->supplier = trim($_POST['supplier'] ?? '');
        if ($item->name !== '') {
            $app->inv->save($item);
            setFlash('Saved.');
        }
    }
    header('Location: /inventory'); exit;
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
    header('Location: /inventory'); exit;
}

if ($uri === '/inventory/delete' && $method === 'POST') {
    if (csrfValid($_POST['csrf'] ?? null)) {
        $app->inv->delete($_POST['id'] ?? '');
    }
    header('Location: /inventory'); exit;
}

http_response_code(404);
return view('login', ['title' => 'Not found', 'user' => $user]);

/**
 * Render a view inside the layout and terminate.
 * @param array<string, mixed> $vars
 */
function view(string $name, array $vars): void
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
    exit;
}
