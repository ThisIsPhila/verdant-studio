<?php
$plantChoices = is_array($plantChoices ?? null) ? $plantChoices : [];
$selectedPotId = (int) ($product['selected_pot']['product_id'] ?? 0);
?>
<section class="plant-choice" aria-labelledby="plant-choice-heading">
    <h2 id="plant-choice-heading">Choose your plant</h2>
    <div class="product-choice-grid" role="group" aria-label="Plants">
        <?php foreach ($plantChoices as $choice): ?>
            <a class="product-choice" data-product-choice data-choice-kind="plant" data-choice-value="<?= $e($choice['product_id']) ?>" href="/product?id=<?= $e($choice['product_id']) ?>&amp;<?= $selectedPotId ? 'retain_pot_id=' . $e($selectedPotId) : 'pot_id=0' ?>" <?= (int) $choice['product_id'] === (int) $product['product_id'] ? 'aria-current="page"' : '' ?>>
                <span><?= $e($choice['name']) ?></span><small class="product-size-tag"><?= $e($choice['display_size'] ?? $choice['size_label'] ?? '') ?></small>
            </a>
        <?php endforeach ?>
    </div>
</section>
