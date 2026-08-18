<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $backups */
/** @var string $storagePath */
use function Monster\e;
use function Monster\csrfToken;
use function Monster\money;
?>
<h1>Backups</h1>
<p class="muted">Full snapshots of <code><?= e($storagePath) ?></code>. A daily snapshot is taken automatically (kept 14 days). Backups are stored outside the web root and are not served directly.</p>

<form method="post" action="/backup/create" class="inline">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <button type="submit">Download current backup</button>
</form>

<table class="table">
    <thead><tr><th>File</th><th>When</th><th>Size</th><th></th></tr></thead>
    <tbody>
    <?php if (count($backups) === 0): ?>
        <tr><td colspan="4" class="muted">No backups yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($backups as $b): ?>
        <tr>
            <td><?= e($b['name']) ?></td>
            <td class="muted"><?= e(date('Y-m-d H:i', $b['mtime'])) ?></td>
            <td class="muted"><?= e(round($b['size'] / 1024, 1)) ?> KB</td>
            <td class="row-actions">
                <a class="link" href="/backup/download?file=<?= e(rawurlencode($b['name'])) ?>">download</a>
                <form method="post" action="/backup/restore" class="inline">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="file" value="<?= e($b['name']) ?>">
                    <button type="submit" class="link" onclick="return confirm('Restore from <?= e($b['name']) ?>? Current data will be snapshotted first.')">restore</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
