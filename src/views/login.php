<?php
declare(strict_types=1);
/** @var string $error */
/** @var bool $setup  (true when no account exists yet) */
use function Monster\e;
use function Monster\csrfToken;
?>
<?php if ($setup): ?>
    <h1>Set up your account</h1>
    <p class="muted">No owner account exists yet. Create one to start tracking.</p>
<?php else: ?>
    <h1>Sign in</h1>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="error"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" action="<?= $setup ? '/setup' : '/login' ?>" class="card auth">
    <label>Username
        <input type="text" name="user" autocomplete="username" required value="<?= e($_POST['user'] ?? '') ?>">
    </label>
    <label>Password
        <input type="password" name="pass" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required>
    </label>
    <button type="submit"><?= $setup ? 'Create account' : 'Sign in' ?></button>
</form>
