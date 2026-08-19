<?php
declare(strict_types=1);
/** @var string $user */
/** @var bool $configured */
/** @var bool $isAdmin */
/** @var array<string,float> $budgets */
/** @var list<string> $categories */
use function Monster\e;
use function Monster\money;
use function Monster\csrfToken;
?><h1>Settings</h1>

<section class="card">
    <h2>Account</h2>
    <p class="muted">Signed in as <strong><?= e($user) ?></strong>.</p>
    <form method="post" action="/settings/password" class="form">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <label>New password
            <input type="password" name="pass" autocomplete="new-password" minlength="8" required>
        </label>
        <button type="submit">Change password</button>
    </form>
</section>

<?php if ($configured): ?>
<section class="card danger-zone">
    <h2>Data</h2>
    <p class="muted">Storage file: <code><?= e($GLOBALS['app']->storage->path()) ?></code></p>
    <form method="post" action="/settings/reset" class="form" onsubmit="return confirm('Delete ALL transactions? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button type="submit" class="danger">Delete all transactions</button>
    </form>
</section>
<?php endif; ?>

<?php if ($isAdmin): ?>
<section class="card">
    <h2>Monthly budgets</h2>
    <p class="muted">Set a monthly spend budget per category. The report compares actual expenses against these. Leave an amount blank to drop that row.</p>
    <form method="post" action="/settings/budgets" class="form">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <?php foreach (($categories ?? []) as $c): ?>
            <div class="row budget-row">
                <input type="text" name="cat[]" value="<?= e($c) ?>" aria-label="Category" readonly>
                <input type="number" step="0.01" min="0" name="amt[]" value="<?= e(isset($budgets[$c]) ? money($budgets[$c]) : '') ?>" placeholder="0.00" aria-label="Budget for <?= e($c) ?>">
            </div>
        <?php endforeach; ?>
        <div class="row budget-row">
            <input type="text" name="cat[]" placeholder="New category" aria-label="New category">
            <input type="number" step="0.01" min="0" name="amt[]" placeholder="0.00" aria-label="Budget for new category">
        </div>
        <div class="actions"><button type="submit">Save budgets</button></div>
    </form>
</section>
<?php endif; ?>
