<section class="editorial-section">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Shop our collections</p>
            <h2><?= $e($section['heading']) ?></h2>
            <p><?= $e($section['body']) ?></p>
        </div>
        <nav class="category-tabs" aria-label="Featured product categories">
            <?php foreach (['All' => '/catalogue', 'Plants' => '/catalogue?kind=plant', 'Flowers' => '/catalogue?kind=flower', 'Pots' => '/catalogue?kind=pot', 'Care & tools' => '/catalogue?kind=tool'] as $categoryLabel => $categoryHref): ?>
                <a href="<?= $e($categoryHref) ?>"><?= $e($categoryLabel) ?></a>
            <?php endforeach ?>
        </nav>
    </div>
    <div class="product-grid product-grid--featured catalogue-product-grid">
        <?php $compactCard = true; ?>
        <?php foreach ($section['products'] as $product): ?>
            <?php require __DIR__ . '/../components/product-card.php'; ?>
        <?php endforeach ?>
        <?php unset($compactCard); ?>
    </div>
</section>
