<?php
require_once __DIR__ . '/_helpers.php';
$collectionCard = $component ?? [];
$collectionHref = verdant_view_local_path($collectionCard['href'] ?? '/collections', '/collections');
$collectionImage = verdant_view_asset_path($collectionCard['image']['src'] ?? null);
?>
<article class="collection-card"<?= !empty($collectionCard['pending']) ? ' data-pending-space' : '' ?>>
    <?php if ($collectionImage !== null): ?>
        <a class="collection-card__image" href="<?= $e($collectionHref) ?>">
            <img src="<?= $e($collectionImage) ?>" alt="<?= $e($collectionCard['image']['alt'] ?? '') ?>" width="640" height="480" loading="lazy">
        </a>
    <?php else: ?>
        <div class="collection-card__placeholder" aria-hidden="true">Verdant · spaces</div>
    <?php endif ?>
    <div class="collection-card__copy">
        <?php if (!empty($collectionCard['eyebrow'])): ?>
            <p class="eyebrow"><?= $e($collectionCard['eyebrow']) ?></p>
        <?php endif ?>
        <h3><?php if (empty($collectionCard['pending'])): ?><a href="<?= $e($collectionHref) ?>"><?php endif ?><?= $e($collectionCard['title'] ?? '') ?><?php if (empty($collectionCard['pending'])): ?></a><?php endif ?></h3>
        <p><?= $e($collectionCard['description'] ?? '') ?></p>
        <?php if (!empty($collectionCard['pending'])): ?>
            <span class="text-link">Coming soon</span>
        <?php else: ?>
            <a class="text-link" href="<?= $e($collectionHref) ?>"><?= $e($collectionCard['link_label'] ?? 'Explore collection') ?> <span aria-hidden="true">↗</span></a>
        <?php endif ?>
    </div>
</article>
