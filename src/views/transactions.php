<?php
declare(strict_types=1);
/** @var list<\Monster\Transaction> $txns */
/** @var \Monster\Transaction|null $edit */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
use function Monster\csrfToken;
?>
<h1>Transactions</h1>

<form method="post" action="/transactions/save" class="card form">
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

<?php if (empty($txns)): ?>
    <p class="muted">No transactions recorded yet.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Date</th><th>Type</th><th>Category</th><th class="num">Amount</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t): ?>
            <tr>
                <td><?= e($t->date) ?></td>
                <td><?= e($t->type) ?></td>
                <td><?= e($t->category) ?></td>
                <td class="num <?= moneyClass($t->signed()) ?>">$<?= money($t->amount) ?></td>
                <td class="muted"><?= e($t->note) ?></td>
                <td class="row-actions">
                    <a href="/transactions/edit?id=<?= e($t->id) ?>">edit</a>
                    <form method="post" action="/transactions/delete" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($t->id) ?>">
                        <button type="submit" class="link danger">del</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
