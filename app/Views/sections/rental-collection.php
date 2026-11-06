<section class="editorial-section rental-collection-band">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Rental collections</p>
            <h2><?= $e($section['heading']) ?></h2>
            <p><?= $e($section['body']) ?></p>
        </div>
        <?php if ($section['link_path'] !== null): ?>
            <a class="button button--secondary" href="<?= $e($section['link_path']) ?>"><?= $e($section['link_label']) ?></a>
        <?php endif ?>
    </div>
    <div class="product-grid catalogue-product-grid">
        <?php $compactCard = true; ?>
        <?php foreach ($section['rentals'] as $product): ?>
            <?php $product['display_mode'] = 'rent'; ?>
            <?php require __DIR__ . '/../components/product-card.php'; ?>
        <?php endforeach ?>
        <?php unset($compactCard); ?>
    </div>
</section>
