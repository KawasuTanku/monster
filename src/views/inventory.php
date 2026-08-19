<?php
declare(strict_types=1);
/** @var list<\Monster\InventoryItem> $items */
/** @var \Monster\InventoryItem|null $edit */
use function Monster\e;
use function Monster\itemLabel;
use function Monster\trashIcon;
use function Monster\money;
use function Monster\csrfToken;
use function Monster\moneyClass;
?>
<h1>Inventory</h1>
<p class="muted">Units are per can. Stock value = on-hand × unit cost.</p>

<section class="cards">
    <div class="stat"><div class="label">SKUs</div><div class="value"><?= count($items) ?></div></div>
    <div class="stat"><div class="label">Units on hand</div><div class="value"><?= array_sum(array_map(static fn($i) => $i->qtyOnHand, $items)) ?></div></div>
    <div class="stat"><div class="label">Stock value</div><div class="value">$<?= money($totalValue) ?></div></div>
    <div class="stat<?= $lowCount > 0 ? ' danger' : '' ?>"><div class="label">Low stock</div><div class="value"><?= $lowCount ?></div></div>
</section>

<form method="post" action="/inventory/save" class="card form">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" value="<?= $edit ? e($edit->id) : '' ?>">
    <h2><?= $edit ? 'Edit item' : 'Add item' ?></h2>
    <div class="row">
        <label>Name
            <input type="text" name="name" required value="<?= $edit ? e($edit->name) : '' ?>" placeholder="e.g. Original">
        </label>
        <label>Variant / flavor
            <input type="text" name="variant" value="<?= $edit ? e($edit->variant) : '' ?>" placeholder="e.g. 12-pack">
        </label>
        <label>SKU
            <input type="text" name="sku" value="<?= $edit ? e($edit->sku) : '' ?>" placeholder="optional code">
        </label>
    </div>
    <div class="row">
        <label>Qty on hand
            <input type="number" min="0" step="1" name="qtyOnHand" required value="<?= $edit ? e((string) $edit->qtyOnHand) : '0' ?>">
        </label>
        <label>Unit cost ($)
            <input type="number" min="0" step="0.01" name="unitCost" required value="<?= $edit ? money($edit->unitCost) : '' ?>">
        </label>
        <label>Unit price ($)
            <input type="number" min="0" step="0.01" name="unitPrice" value="<?= $edit ? money($edit->unitPrice) : '' ?>">
        </label>
        <label>Reorder at
            <input type="number" min="0" step="1" name="reorderAt" value="<?= $edit ? e((string) $edit->reorderAt) : '0' ?>" placeholder="low-stock threshold">
        </label>
    </div>
    <div class="row">
        <label class="wide">Supplier
            <input type="text" name="supplier" value="<?= $edit ? e($edit->supplier) : '' ?>" placeholder="optional">
        </label>
    </div>
    <div class="actions">
        <button type="submit"><?= $edit ? 'Save changes' : 'Add item' ?></button>
        <?php if ($edit): ?><a class="btn" href="/inventory">Cancel</a><?php endif; ?>
    </div>
</form>

<?php if (empty($items)): ?>
    <p class="muted">No inventory tracked yet.</p>
<?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Variant</th><th class="num">Qty</th><th class="num">Cost</th><th class="num">Price</th><th class="num">Stock $</th><th class="num">Revenue</th><th class="num">COGS</th><th class="num">Profit</th><th class="num">Restock Qty</th><th class="num">Restock Cost</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <tr<?= $i->isLow() ? ' class="low"' : '' ?>>
                <td><?= e($i->name) ?><?= $i->sku !== '' ? ' <span class="muted">(' . e($i->sku) . ')</span>' : '' ?></td>
                <td class="muted"><?= e($i->variant) ?></td>
                <td class="num"><?= e((string) $i->qtyOnHand) ?><?= $i->isLow() ? ' ⚠' : '' ?></td>
                <td class="num">$<?= money($i->unitCost) ?></td>
                <td class="num">$<?= money($i->unitPrice) ?></td>
                <td class="num">$<?= money($i->stockValue()) ?></td>
                <?php $p = $pnl[$i->id] ?? null; ?>
                <td class="num"><?= $p ? '$' . money($p['revenue']) : '—' ?></td>
                <td class="num"><?= $p ? '$' . money($p['cogs']) : '—' ?></td>
                <td class="num <?= $p ? moneyClass($p['net']) : '' ?>"><strong><?= $p ? '$' . money($p['net']) : '—' ?></strong></td>
                <?php $rf = 'restock-' . e($i->id); ?>
                <td class="num restock-qty">
                    <input type="number" min="1" step="1" name="qty" form="<?= $rf ?>" value="12" class="qty" title="Restock quantity" aria-label="Restock quantity">
                </td>
                <td class="num restock-cost">
                    <input type="number" min="0" step="0.01" name="cost" form="<?= $rf ?>" value="<?= money($i->unitCost) ?>" class="cost" title="Cost per can for this restock (defaults to current cost)" aria-label="Restock cost per can">
                </td>
                <td class="row-actions">
                    <a href="/inventory?edit=<?= e($i->id) ?>">edit</a>
                    <form method="post" action="/inventory/adjust" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($i->id) ?>">
                        <input type="hidden" name="delta" value="-1">
                        <button type="submit" class="link" title="Sell one">−1</button>
                    </form>
                    <form method="post" action="/inventory/adjust" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($i->id) ?>">
                        <input type="hidden" name="delta" value="1">
                        <button type="submit" class="link" title="Restock one">+1</button>
                    </form>
                    <form id="<?= $rf ?>" method="post" action="/inventory/restock" class="inline restock">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($i->id) ?>">
                        <button type="submit" class="link" title="Restock & log cost">restock</button>
                    </form>
                    <form method="post" action="/inventory/delete" class="inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($i->id) ?>">
                        <button type="submit" class="link danger icon-btn" title="Delete" aria-label="Delete"><?= trashIcon() ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
