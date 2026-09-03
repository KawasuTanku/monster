<?php
declare(strict_types=1);
/** @var list<\Monster\InventoryItem> $items */
/** @var float $totalCost */
use function Monster\e;
use function Monster\itemLabel;
use function Monster\money;
use function Monster\csrfToken;
?>
<h1>Needs Reorder</h1>
<p class="muted">Items at or below their reorder threshold, with a suggested restock quantity.</p>

<?php if (empty($items)): ?>
    <p class="muted">Nothing to reorder — all stock is above its threshold. 🎉</p>
<?php else: ?>
    <section class="cards">
        <div class="stat danger"><div class="label">Items to reorder</div><div class="value"><?= count($items) ?></div></div>
        <div class="stat"><div class="label">Est. restock cost</div><div class="value">$<?= money($totalCost) ?></div></div>
    </section>

    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Item</th><th class="num">On hand</th><th class="num">Days</th><th class="num">Vel.</th><th class="num">Reorder at</th><th class="num">Order</th><th class="num">Unit cost</th><th class="num">Restock $</th><th>Supplier</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <?php
            $u = $unitsSold[$i->id] ?? 0;
            $daysOfStock = $i->daysOfStock($u, $lookbackDays);
            $velocity = $i->salesVelocity($u, $lookbackDays);
            $suggestedQty = $i->suggestedRestockQty($u, $lookbackDays, $safetyDays, $coverageDays);
            $restockQty = $i->reorderAt > 0 ? $i->reorderQty() : $suggestedQty;
            ?>
            <tr class="low">
                <td><?= e(itemLabel($i)) ?><?= $i->sku !== '' ? ' <span class="muted">(' . e($i->sku) . ')</span>' : '' ?></td>
                <td class="num"><?= e((string) $i->qtyOnHand) ?></td>
                <td class="num"><?= is_finite($daysOfStock) ? $daysOfStock . 'd' : '—' ?></td>
                <td class="num"><?= $velocity > 0 ? number_format($velocity, 2) . '/d' : '—' ?></td>
                <td class="num"><?= $i->reorderAt > 0 ? e((string) $i->reorderAt) : ($i->dynamicReorderPoint($u, $lookbackDays, $safetyDays) > 0 ? e((string) $i->dynamicReorderPoint($u, $lookbackDays, $safetyDays)) . ' (auto)' : '—') ?></td>
                <td class="num"><strong><?= $restockQty > 0 ? e((string) $restockQty) : '—' ?></strong></td>
                <td class="num">$<?= money($i->unitCost) ?></td>
                <td class="num">$<?= $restockQty > 0 ? money($restockQty * $i->unitCost) : '—' ?></td>
                <td class="muted"><?= e($i->supplier) ?></td>
                <td class="row-actions">
                    <a href="/inventory?edit=<?= e($i->id) ?>">edit</a>
                    <form method="post" action="/inventory/restock" class="inline restock">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= e($i->id) ?>">
                        <input type="hidden" name="qty" value="<?= e((string) $restockQty) ?>">
                        <button type="submit" class="link" title="Restock to suggested qty & log cost">restock</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
