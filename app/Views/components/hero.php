<?php
require_once __DIR__ . '/_helpers.php';
$hero = $component ?? [];
$heroImage = verdant_view_asset_path($hero['image']['src'] ?? null);
$heroActions = $hero['actions'] ?? (empty($hero['action']) ? [] : [$hero['action']]);
?>
<section class="editorial-hero<?= !empty($hero['image']['crop']) ? ' editorial-hero--scene' : '' ?>">
    <div class="editorial-hero__copy">
        <?php if (!empty($hero['eyebrow'])): ?>
            <p class="eyebrow"><?= $e($hero['eyebrow']) ?></p>
        <?php endif ?>
        <h1><?php if (!empty($hero['title_lines'])): ?>
            <?php foreach ($hero['title_lines'] as $heroLine): ?>
                <span class="editorial-hero__title-line"><?= $e($heroLine) ?></span>
            <?php endforeach ?>
        <?php else: ?><?= $e($hero['title'] ?? '') ?><?php endif ?></h1>
        <?php if (!empty($hero['tagline'])): ?>
            <p class="editorial-hero__tagline"><?= $e($hero['tagline']) ?></p>

        <?php endif ?>
        <?php if (!empty($hero['body'])): ?>
            <p class="editorial-hero__body"><?= $e($hero['body']) ?></p>
        <?php endif ?>
        <?php if ($heroActions !== []): ?>
            <div class="editorial-hero__actions">
                <?php foreach ($heroActions as $heroAction): ?>
                    <?php $component = $heroAction; require __DIR__ . '/button.php'; ?>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>
    <?php if (!empty($hero['annotation'])): ?>
        <p class="editorial-hero__annotation"><?= $e($hero['annotation']) ?></p>
    <?php endif ?>
    <?php if ($heroImage !== null): ?>
        <figure class="editorial-hero__media">
            <?php if (!empty($hero['image']['crop'])): ?>
                <svg viewBox="<?= $e(implode(' ', $hero['image']['crop'])) ?>" role="img" aria-label="<?= $e($hero['image']['alt'] ?? '') ?>" preserveAspectRatio="xMidYMid slice">
                    <image href="<?= $e($heroImage) ?>" width="1672" height="941" />
                </svg>
            <?php else: ?>
                <img src="<?= $e($heroImage) ?>" alt="<?= $e($hero['image']['alt'] ?? '') ?>" width="900" height="760">
            <?php endif ?>
        </figure>
    <?php else: ?>
        <div class="editorial-hero__placeholder" role="img" aria-label="<?= $e($hero['image']['alt'] ?? 'Botanical scene placeholder') ?>">
            <span aria-hidden="true">✳</span>
        </div>
    <?php endif ?>
</section>
