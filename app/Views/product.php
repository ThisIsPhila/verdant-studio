<?php
$visionPage = true;
$visionComponentStyles = ['product-detail'];
$variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
$compatiblePots = is_array($product['compatible_pots'] ?? null) ? $product['compatible_pots'] : [];
$plantChoices = is_array($plantChoices ?? null) ? $plantChoices : [];
$kind = (string) ($product['product_kind'] ?? 'other');
$mediaGallery = is_array($mediaGallery ?? null) ? $mediaGallery : null;
$productGalleryAssetsExternal = true;
$hasManagedMedia = in_array($mediaGallery['mode'] ?? '', ['scene', 'static', 'standalone-pot'], true);
$canBuy = !empty($product['selection_can_buy']);
$canRent = !empty($product['selection_can_rent']);
$selectedPot = is_array($product['selected_pot'] ?? null) ? $product['selected_pot'] : null;
$selectedPotId = (int) ($selectedPot['product_id'] ?? 0);
$careFields = ['light_level' => 'Light', 'watering_level' => 'Watering', 'toxicity' => 'Safety'];
require_once __DIR__ . '/components/_helpers.php';
?>
<nav class="breadcrumbs vision-product-breadcrumbs" aria-label="Breadcrumb">
    <a href="/catalogue">Catalogue</a>
    <?php if (($product['category_name'] ?? '') !== ''): ?>
        <span aria-hidden="true"> / </span>
        <a href="/catalogue?category_id=<?= $e(rawurlencode((string) $product['category_id'])) ?>"><?= $e($product['category_name']) ?></a>
    <?php endif ?>
    <span aria-hidden="true"> / </span><span aria-current="page"><?= $e($product['name']) ?></span>
</nav>

<?php
$galleryCatalogueImage = \Verdant\Storefront\VisionLandingPresenter::image($product);
$galleryHasTabs = $hasManagedMedia && $galleryCatalogueImage !== null;
$galleryConfiguredLabel = $kind === 'plant' ? 'Plant with a pot' : 'Styled view';
$galleryIsPot = $kind === 'accessory' && ($product['category_name'] ?? '') === 'Planters & pots';
$galleryPhotoClass = $galleryIsPot ? ' class="vision-photo--mat"' : '';
$galleryModeBadge = $canBuy ? ($canRent ? 'Buy or rent' : 'Buy') : ($canRent ? 'Rent' : '');
$gallerySize = (string) ($product['display_size'] ?? '');
$galleryShowSize = $gallerySize !== '' && !in_array(strtolower($gallerySize), ['unknown', 'standard'], true);
?>
<article data-product-detail data-selected-pot-id="<?= $e($selectedPotId) ?>" class="vision-product-detail vision-container" aria-labelledby="product-title">
    <div class="vision-product-gallery">
        <?php if ($galleryHasTabs): ?>
            <div data-gallery-panel="catalogue">
                <img src="<?= $e($galleryCatalogueImage) ?>" alt="<?= $e($product['name'] ?? 'Botanical product') ?>" width="800" height="1000" fetchpriority="high"<?= $galleryPhotoClass ?>>
            </div>
            <div data-gallery-panel="configured" hidden>
                <?php require __DIR__ . '/components/product-gallery.php'; ?>
            </div>
            <div class="vision-gallery-thumbs" role="group" aria-label="Product image views">
                <button type="button" data-gallery-view="catalogue" aria-pressed="true" aria-label="Catalogue image" title="Catalogue image"><img src="<?= $e($galleryCatalogueImage) ?>" alt="" width="144" height="180" loading="lazy"<?= $galleryPhotoClass ?>></button>
                <button type="button" data-gallery-view="configured" aria-pressed="false" aria-label="<?= $e($galleryConfiguredLabel) ?>" title="<?= $e($galleryConfiguredLabel) ?>"><canvas data-gallery-configured-thumb width="144" height="180" aria-hidden="true"></canvas></button>
            </div>
        <?php elseif ($hasManagedMedia): ?>
            <?php require __DIR__ . '/components/product-gallery.php'; ?>
        <?php elseif ($galleryCatalogueImage !== null): ?>
            <img src="<?= $e($galleryCatalogueImage) ?>" alt="<?= $e($product['name'] ?? 'Botanical product') ?>" width="800" height="1000" fetchpriority="high"<?= $galleryPhotoClass ?>>
        <?php else: ?>
            <img src="<?= $e($image($product['image_path'] ?? null)) ?>" alt="<?= $e($product['name'] ?? 'Botanical product') ?>" width="720" height="720">
        <?php endif ?>
    </div>

    <div class="vision-product-copy">
        <p class="vision-product-eyebrow"><?= $e($label($kind)) ?><?php if (($product['category_name'] ?? '') !== ''): ?> · <?= $e($product['category_name']) ?><?php endif ?></p>
        <h1 id="product-title"><?= $e($product['name']) ?></h1>
        <div class="vision-product-pills" aria-label="Product attributes">
            <span><?= $e($label($kind)) ?></span>
            <?php if ($galleryShowSize): ?><span><?= $e($gallerySize) ?></span><?php endif ?>
            <?php if ($galleryModeBadge !== ''): ?><span><?= $e($galleryModeBadge) ?></span><?php endif ?>
        </div>
        <?php if (($product['display_description'] ?? '') !== ''): ?>
            <p class="vision-product-description"><?= $e($product['display_description']) ?></p>
        <?php endif ?>
        <p class="vision-product-configure">
            <a class="btn-outline" href="/configure?plant=<?= $e(rawurlencode((string) ($product['product_id'] ?? ''))) ?>">Configure this plant</a>
        </p>

        <?php if (count($variants) > 1): ?>
            <section class="vision-product-choices" aria-labelledby="variant-choice-heading">
                <h2 id="variant-choice-heading">Choose your product</h2>
                <div class="vision-product-choice-list" role="group" aria-label="Product options">
                    <?php foreach ($variants as $variant): ?>
                        <?php $variantSize = (string) ($variant['variant_label'] ?? ''); ?>
                        <a class="vision-product-choice" data-product-choice data-choice-kind="variant" data-choice-value="<?= $e($variant['product_id']) ?>" href="/product?id=<?= $e(rawurlencode((string) $variant['product_id'])) ?>" <?= (int) $variant['product_id'] === (int) $product['product_id'] ? 'aria-current="page"' : '' ?>>
                            <span><?= $e($variant['name']) ?></span>
                            <?php if ($variantSize !== ''): ?><small class="vision-product-size"><?= $e($variantSize) ?></small><?php endif ?>
                        </a>
                    <?php endforeach ?>
                </div>
            </section>
        <?php endif ?>

        <?php if ($kind === 'plant'): ?>
            <section class="vision-product-choices vision-pot-choices" aria-labelledby="pot-choice-heading">
                <div class="vision-product-choice-heading">
                    <h2 id="pot-choice-heading">Choose your pot</h2>
                    <span>Required with this plant</span>
                </div>
                <?php if ($compatiblePots !== []): ?>
                    <div class="vision-pot-choice-list" role="group" aria-label="Compatible pots">
                        <?php foreach ($compatiblePots as $pot): ?>
                            <?php
                            $potSize = (string) ($pot['variant_label'] ?? $pot['size_label'] ?? '');
                            ?>
                            <a class="vision-pot-choice" data-product-choice data-choice-kind="pot" data-choice-value="<?= $e($pot['product_id']) ?>" href="/product?id=<?= $e(rawurlencode((string) $product['product_id'])) ?>&amp;pot_id=<?= $e(rawurlencode((string) $pot['product_id'])) ?>" <?= (int) $pot['product_id'] === $selectedPotId ? 'aria-current="page"' : '' ?>>
                                <?php $thumbnailSourceProduct = $product; $product = $pot; $thumbnailPreferPhoto = true; ?>
                                <span class="vision-pot-choice-image"><?php require __DIR__ . '/components/product-thumbnail.php'; ?></span>
                                <?php $product = $thumbnailSourceProduct; unset($thumbnailSourceProduct, $thumbnailPreferPhoto); ?>
                                <span class="vision-pot-choice-copy"><span><?= $e($pot['name']) ?></span><?php if ($potSize !== '' && strtolower($potSize) !== 'unknown'): ?><small><?= $e($potSize) ?> pot</small><?php endif ?></span>
                                <?php if ($potSize !== '' && strtolower($potSize) !== 'unknown'): ?><small class="vision-product-size"><?= $e($potSize) ?></small><?php endif ?>
                            </a>
                        <?php endforeach ?>
                    </div>
                    <?php if ($selectedPot !== null): ?><p class="vision-pot-note">Compatible with this plant. Your selected pot is included in the total.</p><?php endif ?>
                <?php else: ?>
                    <p class="vision-pot-note" role="status">No compatible pot is currently available for this plant.</p>
                <?php endif ?>
            </section>
        <?php endif ?>

        <p class="product-selection-status visually-hidden" data-product-selection-status role="status" aria-live="polite"></p>
        <p class="vision-product-selection-summary" data-selection-summary><?= $e($product['name']) ?><?php if ($selectedPot !== null): ?> <span aria-hidden="true">+</span> <?= $e($selectedPot['name']) ?><?php endif ?></p>

        <section class="vision-product-price-block" aria-label="Current product prices">
            <?php if ($canBuy): ?><p class="vision-product-price"><strong><?= $e($money($product['selection_retail_price_minor'])) ?></strong><span> to own<?= $kind === 'plant' && $selectedPot !== null ? ' · includes selected pot' : '' ?></span></p><?php endif ?>
            <?php if ($canRent): ?><p class="vision-product-price"><strong><?= $e($money($product['selection_rental_rate_minor'])) ?></strong><span> per day<?= $kind === 'plant' && $selectedPot !== null ? ' · plant and pot' : '' ?></span></p><?php endif ?>
        </section>

        <section class="vision-product-purchase" aria-labelledby="purchase-heading">
            <h2 id="purchase-heading">Buy or rent</h2>
            <div class="vision-product-mode-badges" aria-label="Available purchase options">
                <?php if ($canBuy): ?><span>Buy · <?= $e($money($product['selection_retail_price_minor'])) ?></span><?php endif ?>
                <?php if ($canRent): ?><span>Rent · dates needed</span><?php endif ?>
            </div>
            <div class="vision-product-purchase-options">
                <?php if ($canBuy): ?>
                    <form class="vision-product-cart-form" action="/cart/add" method="post">
                        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                        <input type="hidden" name="product_id" value="<?= $e($product['product_id']) ?>">
                        <?php if ($selectedPot !== null): ?><input type="hidden" name="pot_id" value="<?= $e($selectedPot['product_id']) ?>"><?php endif ?>
                        <label class="vision-product-quantity" for="quantity"><span>Quantity</span><input id="quantity" type="number" name="quantity" min="1" max="99" value="1" required></label>
                        <button class="btn-solid vision-product-buy-button" type="submit">Add to basket <span aria-hidden="true">→</span></button>
                        <p class="vision-product-note">Availability is checked when your basket is prepared.</p>
                    </form>
                <?php endif ?>
                <?php if ($canRent): ?>
                    <?php $rentHref = $selectedPot !== null
                        ? '/configure?' . http_build_query(['code' => 'starter-potted-plant', 'plant_id' => $product['product_id'], 'pot_id' => $selectedPot['product_id'], 'mode' => 'rent'])
                        : '/rental?product_id=' . rawurlencode((string) $product['product_id']); ?>
                    <a class="btn-outline vision-product-rent-button" href="<?= $e($rentHref) ?>">Choose rental dates <span aria-hidden="true">→</span></a>
                    <p class="vision-product-note">Rental availability depends on your selected dates and quantity.</p>
                <?php endif ?>
                <?php if (!$canBuy && !$canRent): ?>
                    <p class="vision-product-unavailable" role="status">This product is currently unavailable to buy or rent. Return to the catalogue to browse other options.</p>
                <?php endif ?>
            </div>
        </section>

        <?php if ($kind === 'plant' && ($product['details_status'] ?? '') !== 'demonstration'): ?>
            <details class="vision-product-disclosure">
                <summary><span><strong>Plant details</strong><small>Verified product details</small></span><span class="vision-disclosure-icon" aria-hidden="true">+</span></summary>
                <dl class="vision-product-facts">
                    <?php if (($product['display_size'] ?? '') !== ''): ?><div><dt>Size</dt><dd><?= $e($product['display_size']) ?></dd></div><?php endif ?>
                    <?php foreach ($careFields as $key => $caption): ?>
                        <?php $careValue = is_string($product[$key] ?? null) && $product[$key] !== '' ? $product[$key] : 'unknown'; ?>
                        <?php if ($careValue !== 'unknown'): ?><div><dt><?= $e($caption) ?></dt><dd><?= $e($label($careValue)) ?></dd></div><?php endif ?>
                    <?php endforeach ?>
                </dl>
                <?php if (empty($product['light_level']) && empty($product['watering_level']) && empty($product['toxicity'])): ?><p class="vision-product-unverified">Care details have not been verified for this product.</p><?php endif ?>
            </details>
        <?php elseif ($kind !== 'plant' && (($product['size_label'] ?? '') !== '' || ($product['colour_label'] ?? '') !== '' || ($product['selling_unit'] ?? '') !== '')): ?>
            <details class="vision-product-disclosure">
                <summary><span><strong>Product details</strong><small>Size and product specifications</small></span><span class="vision-disclosure-icon" aria-hidden="true">+</span></summary>
                <dl class="vision-product-facts">
                    <?php foreach (['size_label' => 'Size', 'colour_label' => 'Colour', 'selling_unit' => 'Selling unit'] as $key => $caption): ?>
                        <?php if (($product[$key] ?? '') !== ''): ?><div><dt><?= $e($caption) ?></dt><dd><?= $e($product[$key]) ?></dd></div><?php endif ?>
                    <?php endforeach ?>
                </dl>
            </details>
        <?php endif ?>
        <p class="vision-product-back"><a href="/catalogue">← Back to the collection</a></p>
    </div>
</article>

<script src="/assets/js/media/renderer.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/media/renderer.js')) ?>" defer></script>
<script src="/assets/js/media/gallery.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/media/gallery.js')) ?>" defer></script>
<script src="/assets/js/product-selection.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/product-selection.js')) ?>" defer></script>
