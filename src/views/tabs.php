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

<section class="card">
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
                <td>
                    <a href="/tabs/<?= e($c->id) ?>"><?= e($c->name) ?></a>
                    <?php if ($c->note !== ''): ?><span class="muted"> · <?= e($c->note) ?></span><?php endif; ?>
                </td>
                <td class="num <?= moneyClass(-$bal) ?>"><strong>$<?= money($bal) ?></strong></td>
                <td class="row-actions">
                    <a href="/tabs/<?= e($c->id) ?>">view</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
