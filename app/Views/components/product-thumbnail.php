<?php
$thumbnail = is_array($product['thumbnail'] ?? null) ? $product['thumbnail'] : [];
$thumbnailMode = $thumbnail['mode'] ?? 'fallback';
$thumbnailPhoto = \Verdant\Storefront\VisionLandingPresenter::image($product);
$thumbnailFallback = $thumbnailPhoto ?? $image($product['image_path'] ?? null);
if (!empty($thumbnailPreferPhoto) && $thumbnailPhoto !== null) {
    $thumbnailMode = 'photo';
}
?>
<?php if ($thumbnailMode === 'photo'): ?>
    <img src="<?= $e($thumbnailPhoto) ?>" alt="<?= $e($product['name'] ?? 'Botanical product') ?>" loading="lazy">
<?php elseif (in_array($thumbnailMode, ['scene', 'pot'], true)): $mediaCardsUsed = true; ?>
    <span class="product-thumbnail" data-product-thumbnail data-thumbnail-mode="<?= $e($thumbnailMode) ?>">
        <img data-thumbnail-fallback src="<?= $e($thumbnailFallback) ?>" alt="<?= $e($product['name']) ?>" width="400" height="500" loading="lazy">
        <canvas data-thumbnail-canvas width="800" height="1000" role="img" aria-label="<?= $e($thumbnail['scene_alt']) ?>" hidden></canvas>
        <script type="application/json" data-thumbnail-scene><?= json_encode($thumbnail['scene'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </span>
<?php else: ?>
    <img src="<?= $e($thumbnailFallback) ?>" alt="<?= $e($product['name'] ?? 'Botanical product') ?>" width="400" height="340" loading="lazy">
<?php endif ?>
