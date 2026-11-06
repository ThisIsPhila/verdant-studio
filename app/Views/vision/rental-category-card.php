<?php
$_rentalCategory = $component ?? [];
$_rentalImage = verdant_view_asset_path($_rentalCategory['image'] ?? null);
$_rentalHref = verdant_view_local_path($_rentalCategory['href'] ?? '', '/catalogue?mode=rent');
?>
<article class="vision-rental-category<?= !empty($_rentalCategory['selected']) ? ' is-selected' : '' ?>">
    <a class="vision-rental-category__media vision-rental-category__media--<?= $e($_rentalCategory['fit'] ?? 'contain') ?>" href="<?= $e($_rentalHref) ?>" aria-label="Browse <?= $e($_rentalCategory['name'] ?? 'rentals') ?>">
        <?php if ($_rentalImage !== null): ?><img src="<?= $e($_rentalImage) ?>" alt="" loading="lazy"><?php endif ?>
    </a>
    <div class="vision-rental-category__body">
        <p class="eyebrow">Rental edit</p>
        <h3><a href="<?= $e($_rentalHref) ?>"><?= $e($_rentalCategory['name'] ?? '') ?></a></h3>
        <p><?= $e($_rentalCategory['description'] ?? '') ?></p>
        <a class="vision-rental-category__action" href="<?= $e($_rentalHref) ?>">Browse <?= $e($_rentalCategory['name'] ?? 'rentals') ?> <span aria-hidden="true">→</span></a>
    </div>
</article>
