<?php
require_once __DIR__ . '/_helpers.php';
$_viewArrangement = $component ?? [];
$_viewArrangementHref = verdant_view_local_path($_viewArrangement['href'] ?? '/compositions', '/compositions');
$_viewArrangementImage = verdant_view_asset_path($_viewArrangement['image']['src'] ?? null);
?>
<article class="arrangement-card<?= empty($_viewArrangement['items']) ? '' : ' arrangement-card--detailed' ?>">
    <div class="arrangement-card__story">
        <?php if ($_viewArrangementImage !== null): ?>
            <a class="arrangement-card__image" href="<?= $e($_viewArrangementHref) ?>">
                <img src="<?= $e($_viewArrangementImage) ?>" alt="<?= $e($_viewArrangement['image']['alt'] ?? '') ?>" width="640" height="480" loading="lazy">
            </a>
        <?php else: ?>
            <div class="arrangement-card__placeholder" aria-hidden="true"></div>
        <?php endif ?>
        <div class="arrangement-card__caption">
            <h3><a href="<?= $e($_viewArrangementHref) ?>"><?= $e($_viewArrangement['title'] ?? '') ?></a></h3>
            <p><?= $e($_viewArrangement['description'] ?? '') ?></p>
        </div>
    </div>
    <div class="arrangement-card__copy">
        <?php if (!empty($_viewArrangement['items'])): ?>
            <ul class="arrangement-card__components">
                <?php foreach ($_viewArrangement['items'] as $itemLabel): ?>
                    <li><span class="arrangement-card__item-media" aria-hidden="true"></span><span><?= $e($itemLabel) ?></span></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
        <?php if (isset($_viewArrangement['price_minor'])): ?>
            <p class="arrangement-card__price">From <?= $e($money($_viewArrangement['price_minor'])) ?></p>
        <?php else: ?>
            <p class="arrangement-card__price">Made to your selection</p>
        <?php endif ?>
        <a class="button" href="<?= $e($_viewArrangementHref) ?>"><?= $e($_viewArrangement['action_label'] ?? 'Discover arrangement') ?> <span aria-hidden="true">→</span></a>
    </div>
</article>
