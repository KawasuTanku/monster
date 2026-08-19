<?php
declare(strict_types=1);
/** @var list<\Monster\Transaction> $txns */
/** @var \Monster\Transaction|null $edit */
/** @var list<\Monster\InventoryItem> $items */
/** @var array{type?: string, category?: string, from?: string, to?: string, q?: string, page?: int} $filters */
/** @var array{items: list<\Monster\Transaction>, total: int, page: int, perPage: int, pages: int} $pager */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
use function Monster\csrfToken;
use function Monster\itemLabel;
use function Monster\trashIcon;
?>
<h1>Transactions</h1>

<form method="post" action="/transactions/save" class="card form" id="txn-form">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" value="<?= $edit ? e($edit->id) : '' ?>">
    <div class="row">
        <label>Type
            <select name="type">
                <option value="sale"<?= $edit && $edit->type==='sale' ? ' selected' : '' ?>>Sale (money in)</option>
                <option value="expense"<?= $edit && $edit->type==='expense' ? ' selected' : '' ?>>Expense (money out)</option>
            </select>
        </label>
        <label>Amount ($)
            <input type="number" step="0.01" min="0" name="amount" required value="<?= $edit ? money($edit->amount) : '' ?>">
        </label>
        <label>Date
            <input type="date" name="date" required value="<?= $edit ? e($edit->date) : date('Y-m-d') ?>">
        </label>
    </div>
    <div class="row">
        <label>Category
            <input type="text" name="category" list="cats" value="<?= $edit ? e($edit->category) : '' ?>" placeholder="e.g. Wholesale, Shipping, Event">
        </label>
        <label class="wide">Note
            <input type="text" name="note" value="<?= $edit ? e($edit->note) : '' ?>" placeholder="optional">
        </label>
    </div>
    <details class="link-item">
        <summary>Link to inventory item</summary>
        <div class="row">
            <label>Item
                <select name="itemId" id="itemId">
                    <option value="">— none —</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= e($it->id) ?>" data-price="<?= e(money($it->unitPrice)) ?>" data-cost="<?= e(money($it->unitCost)) ?>"<?= $edit && $edit->itemId === $it->id ? ' selected' : '' ?>><?= e(itemLabel($it)) ?> — $<?= e(money($it->unitPrice)) ?>/can</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Qty (cans)
                <input type="number" step="1" min="0" name="qty" id="qty" value="<?= $edit ? e((string) $edit->qty) : '1' ?>">
            </label>
        </div>
        <p class="muted">Linking auto-fills the amount: a sale uses price × qty (and decrements stock), an expense uses cost × qty (and adds stock) — both in one entry.</p>
    </details>
    <div class="actions">
        <button type="submit"><?= $edit ? 'Save changes' : 'Add transaction' ?></button>
        <?php if ($edit): ?><a class="btn" href="/transactions">Cancel</a><?php endif; ?>
    </div>
</form>
<datalist id="cats">
    <option value="Wholesale">
    <option value="Shipping">
    <option value="Fees">
    <option value="Event">
    <option value="Supplies">
    <option value="Retail">
</datalist>

<script>
(function(){
    var sel = document.getElementById('itemId');
    var qty = document.getElementById('qty');
    var form = document.getElementById('txn-form');
    if (!sel || !qty || !form) return;
    function refill(){
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) return;
        var n = Math.max(0, parseInt(qty.value || '1', 10) || 0);
        var amt = form.querySelector('input[name="amount"]');
        var type = form.querySelector('select[name="type"]');
        var cat = form.querySelector('input[name="category"]');
        var isExpense = type && type.value === 'expense';
        var unit = isExpense ? parseFloat(opt.getAttribute('data-cost')) : parseFloat(opt.getAttribute('data-price'));
        if (!isNaN(unit) && amt) {
            amt.value = (unit * n).toFixed(2);
        }
        if (cat && cat.value.trim() === '') cat.value = isExpense ? 'Wholesale' : 'Retail';
    }
    sel.addEventListener('change', refill);
    qty.addEventListener('input', refill);
})();
</script>

<?php
$base = '/transactions?' . http_build_query(array_filter([
    'type' => $filters['type'] ?? 'all',
    'category' => $filters['category'] ?? '',
    'from' => $filters['from'] ?? '',
    'to' => $filters['to'] ?? '',
], static fn($v) => $v !== '' && $v !== 'all'));
?>
<form method="get" action="/transactions" class="filter-bar">
    <input type="text" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search note or category…" aria-label="Search transactions">
    <input type="hidden" name="type" value="<?= e($filters['type'] ?? 'all') ?>">
    <input type="hidden" name="category" value="<?= e($filters['category'] ?? '') ?>">
    <input type="hidden" name="from" value="<?= e($filters['from'] ?? '') ?>">
    <input type="hidden" name="to" value="<?= e($filters['to'] ?? '') ?>">
    <button type="submit">Search</button>
    <?php if (($filters['q'] ?? '') !== ''): ?><a class="link" href="<?= e($base) ?>">Clear</a><?php endif; ?>
</form>

<?php if (empty($txns)): ?>
    <p class="muted">No transactions recorded yet.</p>
<?php else: ?>
    <?php $itemMap = []; foreach ($items as $it) { $itemMap[$it->id] = $it; } ?>
    <table class="table">
        <thead><tr><th>Date</th><th>Type</th><th>Category</th><th class="num">Amount</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t): ?>
            <tr>
                <td><?= e($t->date) ?></td>
                <td><?= e($t->type) ?></td>
                <td><?= e($t->category) ?></td>
                <td class="num <?= moneyClass($t->signed()) ?>">$<?= money($t->amount) ?></td>
                <td class="muted"><?= e($t->note) ?><?= $t->itemId !== '' ? ' · ' . e(itemLabel($itemMap[$t->itemId] ?? null)) : '' ?></td>
                <td class="row-actions">
                    <form method="post" action="/transactions/duplicate" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($t->id) ?>">
                        <button type="submit" class="link" title="Duplicate as new entry" aria-label="Duplicate">dup</button>
                    </form>
                    <a href="/transactions?edit=<?= e($t->id) ?>">edit</a>
                    <form method="post" action="/transactions/delete" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($t->id) ?>">
                        <button type="submit" class="link danger icon-btn" title="Delete" aria-label="Delete"><?= trashIcon() ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php
// Pagination footer: build page links that preserve the current filters + search.
$qs = static function (int $p) use ($filters): string {
    $params = array_filter([
        'type' => $filters['type'] ?? 'all',
        'category' => $filters['category'] ?? '',
        'from' => $filters['from'] ?? '',
        'to' => $filters['to'] ?? '',
        'q' => $filters['q'] ?? '',
        'page' => $p > 1 ? $p : null,
    ], static fn($v) => $v !== '' && $v !== null && $v !== 'all');
    $q = http_build_query($params);
    return $q === '' ? '/transactions' : '/transactions?' . $q;
};
?>
<div class="pager">
    <span class="muted"><?= e((string) $pager['total']) ?> entr<?= $pager['total'] === 1 ? 'y' : 'ies' ?><?= $pager['pages'] > 1 ? ' · page ' . $pager['page'] . ' of ' . $pager['pages'] : '' ?></span>
    <?php if ($pager['pages'] > 1): ?>
        <span class="pager-nav">
            <?php if ($pager['page'] > 1): ?><a class="link" href="<?= e($qs($pager['page'] - 1)) ?>">← Prev</a><?php endif; ?>
            <?php if ($pager['page'] < $pager['pages']): ?><a class="link" href="<?= e($qs($pager['page'] + 1)) ?>">Next →</a><?php endif; ?>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>
