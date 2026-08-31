<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $body  (rendered HTML fragment) */
/** @var ?string $user */
/** @var bool $isAdmin */
use function Monster\e;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Monster">
    <meta name="theme-color" content="#0e1116">
    <meta name="format-detection" content="telephone=no">
    <title><?= e($title) ?> · M P&amp;L</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="/assets/mobile.css">
    <script src="/assets/mobile.js" defer></script>
</head>
<body>
    <div id="sheet-backdrop" class="sheet-backdrop"></div>
    <div id="sheet" class="sheet">
        <div class="sheet-handle"></div>
        <div id="sheet-content"></div>
    </div>
<header class="topbar">
    <div class="brand">🧪 Monster <span class="sub">P&amp;L</span></div>
    <?php if ($user !== null): ?>
        <nav class="nav">
            <a href="/dashboard">Dashboard</a>
            <a href="/transactions">Transactions</a>
            <a href="/tabs">Tabs</a>
            <a href="/report">Report</a>
            <a href="/inventory">Inventory</a>
            <a href="/report/reorder">Reorder</a>
            <?php if (!empty($isAdmin)): ?><a href="/users">Users</a><?php endif; ?>
            <?php if (!empty($isAdmin)): ?><a href="/backup">Backups</a><?php endif; ?>
            <a href="/settings">Settings</a>
            <span class="user"><?= e($user) ?></span>
            <form method="post" action="/logout" class="logout">
                <button type="submit">Log out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<nav class="tabbar">
    <a href="/dashboard" data-route="dashboard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Home
    </a>
    <a href="/transactions" data-route="transactions">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/></svg>
        Transactions
    </a>
    <a href="/tabs" data-route="tabs">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h11M16 12h6M19 15h-3"/></svg>
        Tabs
    </a>
    <a href="/inventory" data-route="inventory">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        Inventory
    </a>
    <a href="/report" data-route="report">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
        Report
    </a>
</nav>
<main class="container">
    <?php if (($flash = \Monster\takeFlash()) !== null): ?>
        <div class="flash"><?= e($flash) ?></div>
    <?php endif; ?>
    <?php if (($lowNotice = \Monster\lowStockNotice()) !== null): ?>
        <div class="flash notice-low"><?= e($lowNotice) ?></div>
    <?php endif; ?>
    <?= $body ?>
</main>
<footer class="foot">monster.kawasu.wtf · energy-drink side hustle tracker</footer>
</body>
</html>
