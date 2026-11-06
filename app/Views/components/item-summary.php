<?php
/**
 * Read-only line item summary.
 * Input: $component = ['title'=>string|null, 'items'=>[['label','detail','quantity','amount']], 'total_label'=>string|null,'total'=>string|null].
 * Amounts and totals are display strings prepared by the controller/service; this component performs no price math.
 */
$_viewItemSummary = $component ?? [];
?>
<section class="item-summary" aria-label="<?= $e($_viewItemSummary['title'] ?? 'Order summary') ?>">
    <?php if (!empty($_viewItemSummary['title'])): ?><h2><?= $e($_viewItemSummary['title']) ?></h2><?php endif ?>
    <ul>
        <?php foreach (($_viewItemSummary['items'] ?? []) as $_viewSummaryItem): ?>
            <li><div><strong><?= $e($_viewSummaryItem['label'] ?? '') ?></strong><?php if (!empty($_viewSummaryItem['detail'])): ?><span><?= $e($_viewSummaryItem['detail']) ?></span><?php endif ?></div><div class="summary-amount"><?php if (isset($_viewSummaryItem['quantity'])): ?><span>× <?= $e($_viewSummaryItem['quantity']) ?></span><?php endif ?><?php if (isset($_viewSummaryItem['amount'])): ?><strong><?= $e($_viewSummaryItem['amount']) ?></strong><?php endif ?></div></li>
        <?php endforeach ?>
    </ul>
    <?php if (isset($_viewItemSummary['total'])): ?><div class="summary-total"><span><?= $e($_viewItemSummary['total_label'] ?? 'Total') ?></span><strong><?= $e($_viewItemSummary['total']) ?></strong></div><?php endif ?>
</section>
