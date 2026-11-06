<?php $selectedPotId = (int) ($product['selected_pot']['product_id'] ?? 0); ?>
<section class="pot-choice" aria-labelledby="pot-choice-heading">
    <h2 id="pot-choice-heading">Choose your pot</h2>
    <div class="product-choice-grid" role="group" aria-label="Pots">
        <?php foreach ($compatiblePots as $pot): ?>
            <a class="product-choice" data-product-choice data-choice-kind="pot" data-choice-value="<?= $e($pot['product_id']) ?>" href="/product?id=<?= $e($product['product_id']) ?>&amp;pot_id=<?= $e($pot['product_id']) ?>" <?= (int) $pot['product_id'] === $selectedPotId ? 'aria-current="page"' : '' ?>><span><?= $e($pot['name']) ?></span><small class="product-size-tag"><?= $e($pot['variant_label'] ?? $pot['size_label'] ?? '') ?></small></a>
        <?php endforeach ?>
    </div>
</section>
