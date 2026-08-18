<?php
declare(strict_types=1);
/** @var array{revenue: float, expenses: float, net: float, by_category: array<string,float>} $summary */
/** @var list<\Monster\Transaction> $txns */
/** @var list<string> $categories */
/** @var array{type?: string, category?: string, from?: string, to?: string} $filters */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
?>
<h1>Report</h1>

<form method="get" action="/report" class="filter-bar">
    <label>Type
        <select name="type">
            <option value="all"<?= ($filters['type'] ?? 'all') === 'all' ? ' selected' : '' ?>>All</option>
            <option value="sale"<?= ($filters['type'] ?? '') === 'sale' ? ' selected' : '' ?>>Sales</option>
            <option value="expense"<?= ($filters['type'] ?? '') === 'expense' ? ' selected' : '' ?>>Expenses</option>
        </select>
    </label>
    <label>Category
        <select name="category">
            <option value=""<?= ($filters['category'] ?? '') === '' ? ' selected' : '' ?>>All</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>"<?= ($filters['category'] ?? '') === $c ? ' selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>From
        <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>">
    </label>
    <label>To
        <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>">
    </label>
    <button type="submit">Filter</button>
    <a class="link" href="/report">Clear</a>
    <a class="link" href="/report/export?type=<?= e($filters['type'] ?? 'all') ?>&amp;category=<?= e($filters['category'] ?? '') ?>&amp;from=<?= e($filters['from'] ?? '') ?>&amp;to=<?= e($filters['to'] ?? '') ?>">Export CSV</a>
</form>

<section class="cards">
    <div class="stat"><div class="label">Revenue</div><div class="value <?= moneyClass($summary['revenue']) ?>">$<?= money($summary['revenue']) ?></div></div>
    <div class="stat"><div class="label">Expenses</div><div class="value <?= moneyClass(-$summary['expenses']) ?>">$<?= money($summary['expenses']) ?></div></div>
    <div class="stat highlight"><div class="label">Net Profit</div><div class="value <?= moneyClass($summary['net']) ?>">$<?= money($summary['net']) ?></div></div>
    <div class="stat"><div class="label">Transactions</div><div class="value"><?= count($txns) ?></div></div>
</section>

<?php if (!empty($txns)): ?>
    <h2>All entries</h2>
    <table class="table">
        <thead><tr><th>Date</th><th>Type</th><th>Category</th><th class="num">Amount</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t): ?>
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
<?php else: ?>
    <p class="muted">No data to report yet.</p>
<?php endif; ?>
