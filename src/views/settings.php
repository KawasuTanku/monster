<?php
declare(strict_types=1);
/** @var string $user */
/** @var bool $configured */
use function Monster\e;
use function Monster\csrfToken;
?>
<h1>Settings</h1>

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
