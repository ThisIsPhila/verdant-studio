<?php
$_homesSet = $component ?? [];
$_homesSetImage = verdant_view_asset_path($arrangementImage ?? null) ?? '/assets/vision/images/homes.jpg';
$_homesSetDescription = trim((string) ($_homesSet['explanation'] ?? ''));
$_homesSetItems = array_merge(
    array_map(static fn (array $item): array => $item + ['mode_label' => 'Purchased components'], $_homesSet['purchase_components'] ?? []),
    array_map(static fn (array $item): array => $item + ['mode_label' => 'Choose buy or rent'], $_homesSet['choice_components'] ?? []),
    array_map(static fn (array $item): array => $item + ['mode_label' => 'Rented components'], $_homesSet['rental_components'] ?? []),
);
$_homesSetHref = '/configure?code=' . rawurlencode((string) ($_homesSet['definition_code'] ?? ''));
?>
<article class="vision-homes-arrangement-card">
    <div class="vision-homes-arrangement-card__top">
        <div class="vision-homes-arrangement-card__media"><img src="<?= $e($_homesSetImage) ?>" alt="<?= $e($arrangementImageAlt ?? 'Botanical arrangement inspiration') ?>" loading="lazy"></div>
        <div class="vision-homes-arrangement-card__includes">
            <p class="eyebrow">Includes</p>
            <?php if ($_homesSetItems !== []): ?>
                <ul>
                    <?php foreach ($_homesSetItems as $_homesItem): ?>
                        <?php
                        $_homesItemLabel = trim((string) ($_homesItem['label'] ?? 'Arrangement component'));
                        $_homesItemImage = null;
                        foreach ($products ?? [] as $_homesCandidate) {
                            if (strcasecmp(trim((string) ($_homesCandidate['name'] ?? '')), $_homesItemLabel) === 0) {
                                $_homesItemImage = \Verdant\Storefront\VisionLandingPresenter::image($_homesCandidate)
                                    ?? verdant_view_asset_path($image($_homesCandidate['image_path'] ?? null));
                                break;
                            }
                        }
                        ?>
                        <li><span class="vision-homes-arrangement-thumb" aria-hidden="true"><?php if ($_homesItemImage !== null): ?><img src="<?= $e($_homesItemImage) ?>" alt="" loading="lazy"><?php else: ?><span>✳</span><?php endif ?></span><span><?= $e($_homesItemLabel) ?><small class="vision-homes-component-mode"><?= $e($_homesItem['mode_label']) ?></small><?= !empty($_homesItem['required']) ? '' : ' · optional' ?></span></li>
                    <?php endforeach ?>
                </ul>
            <?php else: ?><p class="vision-homes-arrangement-empty">Component choices are confirmed in configuration.</p><?php endif ?>
            <p class="vision-homes-arrangement-price">Current price checked in configuration</p>
        </div>
    </div>
    <div class="vision-homes-arrangement-card__body">
        <p class="eyebrow"><?= $e($label($_homesSet['arrangement_kind'] ?? 'Arrangement')) ?> · <?= $e(($_homesSet['strategy'] ?? '') === 'finished_stock' ? 'Stocked arrangement · check current availability' : 'Inspiration · configure to check availability') ?></p>
        <h3><?= $e($_homesSet['name'] ?? 'Arrangement') ?></h3>
        <p class="vision-homes-arrangement-card__description"><?= $e($_homesSetDescription !== '' ? $_homesSetDescription : 'Choose from current eligible components and confirm availability.') ?></p>
        <a class="btn-solid" href="<?= $e($_homesSetHref) ?>">Configure and check availability <span aria-hidden="true">→</span></a>
        <p class="vision-homes-arrangement-card__note">Browsing creates no stock hold.</p>
    </div>
</article>
