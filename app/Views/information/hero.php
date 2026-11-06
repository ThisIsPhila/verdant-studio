    <?php if ($layout === 'editorial' || $pageKey === 'journal'): ?>
        <header class="info-editorial-hero">
            <div class="info-editorial-hero-copy">
                <p class="info-eyebrow"><?= $esc($eyebrow) ?></p>
                <h1><?= $esc($title) ?></h1>
                <?php $paragraphs($description) ?>
                <?php if ($hero && !empty($hero['link_path']) && !empty($hero['link_label'])): ?>
                    <div class="info-action-row">
                        <a class="info-button info-button-solid" href="<?= $esc($localUrl($hero['link_path']) ?? '#') ?>">
                            <?= $esc($hero['link_label']) ?> <span aria-hidden="true">→</span>
                        </a>
                    </div>
                <?php endif ?>
                <?php if ($heroScript !== ''): ?><p class="info-script"><?= $esc($heroScript) ?></p><?php endif ?>
            </div>
            <?php if ($heroImage !== null): ?><img src="<?= $esc($heroImage) ?>" alt="<?= $esc($heroAlt) ?>" width="900" height="600"><?php endif ?>
        </header>
    <?php elseif ($layout !== 'article'): ?>
        <header class="info-hero">
            <div class="info-container">
                <p class="info-eyebrow"><?= $esc($eyebrow) ?></p>
                <h1><?= $esc($title) ?></h1>
                <?php if ($description !== ''): ?><div class="info-intro"><?php $paragraphs($description) ?></div><?php endif ?>
                <?php if ($isPolicy && $preview): ?><span class="info-draft-badge">Draft for review</span><?php endif ?>
            </div>
        </header>
    <?php endif ?>
