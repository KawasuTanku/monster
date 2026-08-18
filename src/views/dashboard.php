<?php
declare(strict_types=1);
/** @var array{revenue: float, expenses: float, net: float, by_category: array<string,float>} $summary */
/** @var list<\Monster\Transaction> $recent */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
?>
<h1>Dashboard</h1>
<section class="cards">
    <div class="stat">
        <div class="label">Revenue</div>
        <div class="value <?= moneyClass($summary['revenue']) ?>">$<?= money($summary['revenue']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Expenses</div>
        <div class="value <?= moneyClass(-$summary['expenses']) ?>">$<?= money($summary['expenses']) ?></div>
    </div>
    <div class="stat highlight">
        <div class="label">Net Profit</div>
        <div class="value <?= moneyClass($summary['net']) ?>">$<?= money($summary['net']) ?></div>
    </div>
</section>

<h2>By category</h2>
<?php if (empty($summary['by_category'])): ?>
    <p class="muted">No transactions yet.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Category</th><th class="num">Net</th></tr></thead>
        <tbody>
        <?php foreach ($summary['by_category'] as $cat => $val): ?>
            <tr><td><?= e($cat ?: '(uncategorized)') ?></td>
                <td class="num <?= moneyClass($val) ?>">$<?= money($val) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Recent activity</h2>
<?php if (empty($recent)): ?>
    <p class="muted">Nothing recorded yet. <a href="/transactions">Add your first entry →</a></p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Date</th><th>Type</th><th>Category</th><th class="num">Amount</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($recent, 0, 8) as $t): ?>
            <tr>
                <td><?= e($t->date) ?></td>
                <td><?= e($t->type) ?></td>
                <td><?= e($t->category) ?></td>
                <td class="num <?= moneyClass($t->signed()) ?>">$<?= money($t->amount) ?></td>
                <td class="muted"><?= e($t->note) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
