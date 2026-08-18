<?php
declare(strict_types=1);
/** @var array{revenue: float, expenses: float, net: float, by_category: array<string,float>} $summary */
/** @var list<\Monster\Transaction> $txns */
/** @var list<string> $categories */
/** @var array{type?: string, category?: string, from?: string, to?: string} $filters */
/** @var list<array{period: string, label: string, revenue: float, cost: float, net: float, roiPct: float, cumNet: float}> $roiSeries */
/** @var array{revenue: float, cost: float, net: float, roiPct: float} $roiOverall */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
use function Monster\roiChartSvg;
?>
<h1>Report</h1>

<form method="get" action="/report" class="filter-bar">
    <label>Type
        <select name="type">
            <option value="all"<?= ($filters['type'] ?? 'all') === 'all' ? ' selected' : '' ?>>All</option>
            <option value="sale"<?= ($filters['type'] ?? '') === 'sale' ? 'selected' : '' ?>>Sales</option>
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
    <div class="stat"><div class="label">ROI</div><div class="value <?= moneyClass($roiOverall['roiPct']) ?>"><?= money($roiOverall['roiPct']) ?>%</div></div>
    <div class="stat"><div class="label">Transactions</div><div class="value"><?= count($txns) ?></div></div>
</section>

<section class="card">
    <h2>Cumulative Net Profit</h2>
    <?php $chart = roiChartSvg($roiSeries); ?>
    <?php if ($chart !== ''): ?>
        <?= $chart ?>
    <?php else: ?>
        <p class="muted">No data to chart yet.</p>
    <?php endif; ?>
</section>

<?php if (!empty($roiSeries)): ?>
    <h2>Monthly breakdown</h2>
    <table class="table">
        <thead><tr><th>Month</th><th class="num">Revenue</th><th class="num">Cost</th><th class="num">Net</th><th class="num">ROI %</th><th class="num">Cum. Net</th></tr></thead>
        <tbody>
        <?php foreach ($roiSeries as $r): ?>
            <tr>
                <td><?= e($r['label']) ?></td>
                <td class="num <?= moneyClass($r['revenue']) ?>">$<?= money($r['revenue']) ?></td>
                <td class="num <?= moneyClass(-$r['cost']) ?>">$<?= money($r['cost']) ?></td>
                <td class="num <?= moneyClass($r['net']) ?>">$<?= money($r['net']) ?></td>
                <td class="num <?= moneyClass($r['roiPct']) ?>"><?= money($r['roiPct']) ?>%</td>
                <td class="num <?= moneyClass($r['cumNet']) ?>">$<?= money($r['cumNet']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

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
