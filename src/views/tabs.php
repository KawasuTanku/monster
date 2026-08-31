<?php
declare(strict_types=1);
/** @var list<\Monster\Customer> $customers */
/** @var array<string, float> $balances */
use function Monster\e;
use function Monster\money;
use function Monster\moneyClass;
use function Monster\csrfToken;
use function Monster\trashIcon;
?>
<h1>Tabs</h1>
<p class="muted">Customers who buy now, pay later. Charges count as revenue when the sale happens; payments settle the balance.</p>

<section class="card inline-form" id="cust-form">
    <h2>New customer</h2>
    <form method="post" action="/tabs/new" class="form">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <div class="row">
            <label>Name
                <input type="text" name="name" required placeholder="e.g. Kawasu">
            </label>
            <label class="wide">Note
                <input type="text" name="note" placeholder="optional">
            </label>
        </div>
        <div class="actions"><button type="submit">Add customer</button></div>
    </form>
</section>

<?php if (empty($customers)): ?>
    <p class="muted">No customers yet.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Customer</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <?php $bal = $balances[$c->id] ?? 0.0; ?>
            <tr>
                <td data-label="Customer">
                    <a href="/tabs/<?= e($c->id) ?>"><?= e($c->name) ?></a>
                    <?php if ($c->note !== ''): ?><span class="muted"> · <?= e($c->note) ?></span><?php endif; ?>
                </td>
                <td class="num" data-label="Balance"><strong class="<?= moneyClass(-$bal) ?>">$<?= money($bal) ?></strong></td>
                <td class="row-actions">
                    <a href="/tabs/<?= e($c->id) ?>">view</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<button class="fab" data-form="cust-form" aria-label="Add customer">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
</button>
