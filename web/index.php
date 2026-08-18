<?php
declare(strict_types=1);

use Monster\App;
use Monster\Auth;
use Monster\Transaction;
use Monster\TransactionRepository;
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
        if ($app->auth->login(trim($_POST['user'] ?? ''), $_POST['pass'] ?? '')) {
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
    return view('report', ['title' => 'Report', 'user' => $user, 'isAdmin' => $isAdmin, 'summary' => $app->txns->summary(), 'txns' => $app->txns->all()]);
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
    $GLOBALS['app'] = $GLOBALS['app']; // keep reference for views that read it

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
