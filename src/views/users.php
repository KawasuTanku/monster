<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $users */
/** @var string $me */
use function Monster\e;
use function Monster\csrfToken;
?>
<h1>Users</h1>
<p class="muted">Admins can create users, change roles, and remove access. The last admin can never be demoted or deleted.</p>

<form method="post" action="/users/create" class="card form">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <div class="row">
        <label>New username
            <input type="text" name="user" autocomplete="username" required>
        </label>
        <label>Password (min 8 chars)
            <input type="password" name="pass" autocomplete="new-password" minlength="8" required>
        </label>
        <label>Role
            <select name="role">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
            </select>
        </label>
    </div>
    <button type="submit">Add user</button>
</form>

<table class="table">
    <thead><tr><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= e($u['username']) ?><?= $u['username'] === $me ? ' <span class="muted">(you)</span>' : '' ?></td>
            <td>
                <form method="post" action="/users/role" class="inline">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="user" value="<?= e($u['username']) ?>">
                    <select name="role" onchange="this.form.submit()">
                        <option value="member"<?= ($u['role'] ?? '') === 'member' ? ' selected' : '' ?>>Member</option>
                        <option value="admin"<?= ($u['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Admin</option>
                    </select>
                </form>
            </td>
            <td class="muted"><?= e(date('Y-m-d', (int) ($u['createdAt'] ?? 0))) ?></td>
            <td class="row-actions">
                <?php if ($u['username'] !== $me): ?>
                    <form method="post" action="/users/delete" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="user" value="<?= e($u['username']) ?>">
                        <button type="submit" class="link danger" onclick="return confirm('Remove user <?= e($u['username']) ?>?')">remove</button>
                    </form>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
