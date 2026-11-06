<?php
$configHref = '/configure?code=' . rawurlencode($arrangement['definition_code']);
$floralOffer = str_contains($arrangement['arrangement_kind'], 'floral');
$offerSceneImages = [
    'set-calm-corner' => '/assets/vision/images/homes.jpg',
    'set-entryway-greens' => '/assets/vision/images/r-cluster.jpg',
    'set-living-room-layers' => '/assets/vision/images/homes.jpg',
];
$offerImage = $offerSceneImages[$arrangement['definition_code']] ?? ($floralOffer ? '/assets/vision/images/p-white-bouquet.jpg' : '/assets/vision/images/r-cluster.jpg');
if ($arrangement['strategy'] === 'finished_stock') {
    $offerFinishedOption = is_array($arrangement['components'][0]['options'][0] ?? null) ? $arrangement['components'][0]['options'][0] : null;
    $offerFinishedPhoto = is_array($offerFinishedOption) ? \Verdant\Storefront\VisionLandingPresenter::image(['name' => $offerFinishedOption['name'] ?? '']) : null;
    $offerImage = $offerFinishedPhoto ?? '/assets/images/catalogue/placeholders/plant-arrangement.svg';
}
?>
<article class="arrangement-offer">
    <div class="arrangement-offer-top">
        <div class="arrangement-offer-media"><img src="<?= $e($offerImage) ?>" alt="" width="640" height="800" loading="lazy"></div>
        <div class="arrangement-offer-includes">
            <p class="eyebrow">Includes</p>
            <ul>
            <?php foreach ($arrangement['components'] as $componentInfo): ?>
                <?php
                $includeQuantity = max(1, (int) ($componentInfo['quantity'] ?? 1));
                $includeFirstOption = is_array($componentInfo['options'] ?? null) ? ($componentInfo['options'][0] ?? null) : null;
                $includeThumb = is_array($includeFirstOption) ? \Verdant\Storefront\VisionLandingPresenter::image(['name' => $includeFirstOption['name'] ?? '']) : null;
                ?>
                <li><?php if ($includeThumb !== null): ?><img class="arrangement-include-thumb" src="<?= $e($includeThumb) ?>" alt="" width="48" height="48" loading="lazy"><?php else: ?><span aria-hidden="true">✧</span><?php endif ?><span><?= $includeQuantity > 1 ? $e($includeQuantity) . ' × ' : '' ?><?= $e($componentInfo['label']) ?><?= ($componentInfo['required'] || stripos($componentInfo['label'], 'optional') !== false) ? '' : ' · optional' ?></span></li>
            <?php endforeach ?>
            </ul>
            <div class="arrangement-offer-price">
                <?php if ($arrangement['estimated_buy_floor_minor'] !== null): ?><p>From <?= $e($money($arrangement['estimated_buy_floor_minor'])) ?></p>
                <?php elseif ($arrangement['has_mixed_modes']): ?>
                    <?php if ($arrangement['mixed_buy_subtotal_minor'] !== null): ?><p>Buy from <?= $e($money($arrangement['mixed_buy_subtotal_minor'])) ?></p><?php endif ?>
                <?php endif ?>
                <?php if ($arrangement['estimated_rent_floor_minor'] !== null): ?><small>Rent from <?= $e($money($arrangement['estimated_rent_floor_minor'])) ?>/day</small>
                <?php elseif ($arrangement['has_mixed_modes'] && $arrangement['mixed_rent_subtotal_minor'] !== null): ?><small>Rent from <?= $e($money($arrangement['mixed_rent_subtotal_minor'])) ?>/day</small><?php endif ?>
            </div>
        </div>
    </div>
    <div class="arrangement-offer-body">
        <h3><?= $e($arrangement['name']) ?></h3>
        <p><?= $arrangement['strategy'] === 'finished_stock' ? 'A complete botanical piece for your space.' : ($floralOffer ? 'Choose your flowers and planter to make it yours.' : 'Choose your plants and compatible pots to make it yours.') ?></p>
        <details class="arrangement-offer-options">
            <summary>View component choices</summary>
            <?php foreach ($arrangement['components'] as $componentInfo): ?>
                <section><h4><?= $e($componentInfo['label']) ?><?= $componentInfo['required'] ? ' · required' : ' · optional' ?></h4>
                <?php if (!$componentInfo['options']): ?><p>No current eligible option.</p><?php else: ?><ul>
                <?php foreach ($componentInfo['options'] as $option): ?><li><?= $e($option['name']) ?><?= $option['size_label'] !== null ? ' · ' . $e($option['size_label']) : '' ?><?php if ($option['buy_price_minor'] !== null): ?><small>Buy <?= $e($money($option['buy_price_minor'])) ?></small><?php endif ?><?php if ($option['rent_rate_minor'] !== null): ?><small>Rent <?= $e($money($option['rent_rate_minor'])) ?>/day</small><?php endif ?></li><?php endforeach ?>
                </ul><?php endif ?></section>
            <?php endforeach ?>
            <p class="arrangement-estimate-note">Starting estimates, not a quote. Options, quantities and dates determine the final total.</p>
            <?php if ($arrangement['has_mixed_modes']): ?>
                <?php if ($arrangement['mixed_buy_subtotal_minor'] !== null): ?><p>Required purchase components: <?= $e($money($arrangement['mixed_buy_subtotal_minor'])) ?></p><?php endif ?>
                <?php if ($arrangement['mixed_rent_subtotal_minor'] !== null): ?><p>Required rental components: <?= $e($money($arrangement['mixed_rent_subtotal_minor'])) ?>/day</p><?php endif ?>
            <?php endif ?>
        </details>
        <a class="button button--primary" href="<?= $e($configHref) ?>">Explore arrangement →</a>
    </div>
</article>
