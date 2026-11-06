<?php
$gallery = is_array($mediaGallery ?? null) ? $mediaGallery : [];
$mode = in_array($gallery['mode'] ?? '', ['scene', 'static', 'standalone-pot'], true) ? $gallery['mode'] : 'fallback';
$imageData = is_array($gallery['image'] ?? null) ? $gallery['image'] : [];
$imageSrc = $image($imageData['src'] ?? null);
$imageAlt = is_string($imageData['alt'] ?? null) ? $imageData['alt'] : 'Botanical product image';
$sceneAlt = is_string($gallery['scene_alt'] ?? null) ? $gallery['scene_alt'] : 'Illustrative botanical product scene';
$description = is_string($gallery['description'] ?? null) ? $gallery['description'] : 'Product image';
$galleryId = 'product-gallery-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($product['product_id'] ?? 'item'));
$hasScaleReference = $mode === 'scene' && is_array($gallery['scale_reference'] ?? null);
$galleryJson = json_encode($gallery, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<section class="product-gallery" id="<?= $e($galleryId) ?>" data-media-gallery aria-label="Product images">
    <?php if ($mode === 'static'): ?>
        <figure class="product-gallery__frame product-gallery__frame--static">
            <img src="<?= $e($imageSrc) ?>" alt="<?= $e($imageAlt) ?>" width="800" height="1000" fetchpriority="high">

        </figure>
    <?php elseif ($mode === 'standalone-pot'): ?>
        <figure class="product-gallery__frame product-gallery__frame--static media-standalone-pot" data-media-standalone-root>
            <img class="product-gallery__fallback" data-media-standalone-fallback src="<?= $e($imageSrc) ?>" alt="<?= $e($imageAlt) ?>" width="800" height="1000" fetchpriority="high">
            <canvas class="media-scene-canvas" data-media-standalone width="800" height="1000" role="img" aria-label="<?= $e($sceneAlt) ?>" hidden></canvas>
            <script type="application/json" data-media-gallery-data><?= $galleryJson ?></script>
        </figure>
        <p class="product-gallery__status" data-media-standalone-status role="status" aria-live="polite">Loading the standalone planter image. The standard product placeholder remains visible.</p>
    <?php elseif ($mode === 'scene'): ?>
        <div class="product-gallery__view-controls" role="group" aria-label="Product image views" <?= $hasScaleReference ? '' : 'hidden' ?>>
            <button type="button" class="button button--secondary" data-media-view="main" aria-pressed="true" aria-controls="<?= $e($galleryId) ?>-main" <?= $hasScaleReference ? '' : 'hidden' ?>>Product view</button>
            <?php if ($hasScaleReference): ?>
                <button type="button" class="button button--secondary" data-media-view="scale" aria-pressed="false" aria-controls="<?= $e($galleryId) ?>-scale">Compare with a person</button>
            <?php endif ?>
        </div>
        <figure class="product-gallery__frame media-scene-viewport">
            <img class="product-gallery__fallback" data-media-fallback src="<?= $e($imageSrc) ?>" alt="<?= $e($imageAlt) ?>" width="800" height="1000" fetchpriority="high">
            <canvas class="media-scene-canvas" data-media-main id="<?= $e($galleryId) ?>-main" width="800" height="1000" aria-label="<?= $e($sceneAlt) ?>" hidden></canvas>
            <?php if ($hasScaleReference): ?>
                <canvas class="media-scene-canvas" data-media-scale id="<?= $e($galleryId) ?>-scale" width="800" height="1000" aria-label="Product shown beside a 170 centimetre human silhouette" hidden></canvas>
            <?php endif ?>

        </figure>
        <p class="product-gallery__status" data-media-status role="status" aria-live="polite">Loading the interactive product view; the standard catalogue image stays visible.</p>
        <script type="application/json" data-media-gallery-data><?= $galleryJson ?></script>
        <?php if (empty($productGalleryAssetsExternal)): ?>
        <script src="/assets/js/media/renderer.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/media/renderer.js')) ?>" defer></script>
        <script src="/assets/js/media/gallery.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/media/gallery.js')) ?>" defer></script>
        <?php endif ?>
    <?php else: ?>
        <figure class="product-gallery__frame product-gallery__frame--static">
            <img src="<?= $e($imageSrc) ?>" alt="<?= $e($imageAlt) ?>" width="800" height="1000" fetchpriority="high">

        </figure>
    <?php endif ?>
</section>
