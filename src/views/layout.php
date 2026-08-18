<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $body  (rendered HTML fragment) */
/** @var ?string $user */
use function Monster\e;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Monster</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">🧪 Monster <span class="sub">P&amp;L</span></div>
    <?php if ($user !== null): ?>
        <nav class="nav">
            <a href="/dashboard">Dashboard</a>
            <a href="/transactions">Transactions</a>
            <a href="/report">Report</a>
            <a href="/settings">Settings</a>
            <span class="user"><?= e($user) ?></span>
            <form method="post" action="/logout" class="logout">
                <button type="submit">Log out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
    <?php if (($flash = \Monster\takeFlash()) !== null): ?>
        <div class="flash"><?= e($flash) ?></div>
    <?php endif; ?>
    <?= $body ?>
</main>
<footer class="foot">monster.kawasu.wtf · energy-drink side hustle tracker</footer>
</body>
</html>
