<?php
declare(strict_types=1);
/** @var \Monster\Customer $customer */
/** @var list<\Monster\Transaction> $charges */
/** @var list<\Monster\TabPayment> $payments */
/** @var float $balance */
/** @var list<\Monster\InventoryItem> $items */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
use function Monster\csrfToken;
use function Monster\itemLabel;
use function Monster\trashIcon;
?>
<h1><?= e($customer->name) ?></h1>
<?php if ($customer->note !== ''): ?><p class="muted"><?= e($customer->note) ?></p><?php endif; ?>

<section class="cards">
    <div class="stat highlight">
        <div class="label">Outstanding balance</div>
        <div class="value <?= moneyClass(-$balance) ?>">$<?= money($balance) ?></div>
    </div>
    <div class="stat">
        <div class="label">Total charges</div>
        <div class="value">$<?= money(array_sum(array_map(fn($t) => $t->amount, $charges))) ?></div>
    </div>
    <div class="stat">
        <div class="label">Total paid</div>
        <div class="value">$<?= money(array_sum(array_map(fn($p) => $p->amount, $payments))) ?></div>
    </div>
</section>

<div class="grid-2">
<section class="card inline-form" id="charge-form-section">
    <h2>Add charge</h2>
    <form method="post" action="/tabs/<?= e($customer->id) ?>/charge" class="form" id="charge-form">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <div class="row">
            <label>Date
                <input type="date" name="date" required value="<?= date('Y-m-d') ?>">
            </label>
            <label>Amount ($)
                <input type="number" step="0.01" min="0" name="amount" required id="charge-amount" value="">
            </label>
            <label>Category
                <input type="text" name="category" list="tab-cats" value="Retail">
            </label>
        </div>
        <details class="link-item">
            <summary>Link to inventory item</summary>
            <div class="row">
                <label>Item
                    <select name="itemId" id="charge-itemId">
                        <option value="">— none —</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= e($it->id) ?>" data-price="<?= e(money($it->unitPrice)) ?>"><?= e(itemLabel($it)) ?> — $<?= e(money($it->unitPrice)) ?>/can</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Qty (cans)
                    <input type="number" step="1" min="0" name="qty" id="charge-qty" value="1">
                </label>
            </div>
            <p class="muted">Linking auto-fills the amount from price × qty and decrements stock.</p>
        </details>
        <div class="row">
            <label class="wide">Note
                <input type="text" name="note" placeholder="optional">
            </label>
        </div>
        <div class="actions"><button type="submit">Add charge</button></div>
    </form>
</section>

<section class="card inline-form" id="payment-form-section">
    <h2>Record payment</h2>
    <form method="post" action="/tabs/<?= e($customer->id) ?>/payment" class="form" id="payment-form">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <div class="row">
            <label>Date
                <input type="date" name="date" required value="<?= date('Y-m-d') ?>">
            </label>
            <label>Amount ($)
                <input type="number" step="0.01" min="0" name="amount" required>
            </label>
        </div>
        <div class="row">
            <label class="wide">Note
                <input type="text" name="note" placeholder="optional">
            </label>
        </div>
        <div class="actions"><button type="submit">Record payment</button></div>
    </form>
</section>
</div>

<datalist id="tab-cats">
    <option value="Retail">
    <option value="Wholesale">
    <option value="Event">
</datalist>

<script>
(function(){
    var sel = document.getElementById('charge-itemId');
    var qty = document.getElementById('charge-qty');
    var amt = document.getElementById('charge-amount');
    if (!sel || !qty || !amt) return;
    function refill(){
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) return;
        var n = Math.max(0, parseInt(qty.value || '1', 10) || 0);
        var unit = parseFloat(opt.getAttribute('data-price'));
        if (!isNaN(unit)) amt.value = (unit * n).toFixed(2);
    }
    sel.addEventListener('change', refill);
    qty.addEventListener('input', refill);
})();
</script>

<h2>Charge history</h2>
<?php if (empty($charges)): ?>
    <p class="muted">No charges yet.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Date</th><th>Category</th><th class="num">Amount</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($charges as $t): ?>
            <tr>
                <td data-label="Date"><?= e($t->date) ?></td>
                <td data-label="Category"><?= e($t->category) ?></td>
                <td class="num" data-label="Amount">$<?= money($t->amount) ?></td>
                <td class="muted" data-label="Note"><?= e($t->note) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Payment history</h2>
<?php if (empty($payments)): ?>
    <p class="muted">No payments yet.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Date</th><th class="num">Amount</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td data-label="Date"><?= e($p->date) ?></td>
                <td class="num" data-label="Amount">$<?= money($p->amount) ?></td>
                <td class="muted" data-label="Note"><?= e($p->note) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<form method="post" action="/tabs/<?= e($customer->id) ?>/delete" class="inline" onsubmit="return confirm('Delete this customer? Charges remain as financial history.');" style="margin-top:1rem;">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <button type="submit" class="danger">Delete customer</button>
</form>

<button class="fab" data-form="charge-form-section" aria-label="Add charge or payment">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
</button>
