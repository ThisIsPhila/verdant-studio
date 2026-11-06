    <?php if ($pageKey === 'journal-article' || $pageKey === 'journal-houseplants' || $pageKey === 'journal-pots'): ?>
        <?php
        $articleRows = $byRole(['article-body', 'reading']);
        $relatedRows = $byRole(['related-card']);
        $category = (string) ($presentation['article_category'] ?? '');
        ?>
        <article class="info-article">
            <header class="info-container info-article-intro">
                <nav aria-label="Breadcrumb">
                    <a href="/journal">Journal</a>
                    <span aria-hidden="true"> / </span>
                    <?= $esc($category) ?>
                </nav>
                <p class="info-eyebrow"><?= $esc($category) ?></p>
                <h1><?= $esc($title) ?></h1>
                <?php if (!empty($articlePublishedAt)): ?>
                    <p class="info-eyebrow">Published <time datetime="<?= $esc(substr($articlePublishedAt, 0, 10)) ?>"><?= $esc(date('j F Y', strtotime($articlePublishedAt))) ?></time></p>
                <?php endif ?>
                <?php $paragraphs($description) ?>
                <?php if ($heroImage !== null): ?>
                    <img src="<?= $esc($heroImage) ?>" alt="<?= $esc($heroAlt) ?>" width="1200" height="500">
                <?php endif ?>
            </header>

            <div class="info-container info-reading-layout">
                <?php $renderContentsNav($articleRows, 'Article contents'); ?>
                <div class="info-reading-body">
                    <?php foreach ($byRole(['article-callout']) as $callout): ?>
                        <aside class="info-callout"><h2><?= $esc($callout['heading']) ?></h2><?php $paragraphs($callout['body']) ?></aside>
                    <?php endforeach ?>
                    <?php $renderReadingSections($articleRows); ?>
                </div>
            </div>

            <?php if ($relatedRows !== []): ?>
                <section class="info-container info-section">
                    <div class="info-section-header">
                        <h2>Related reading.</h2>
                        <a class="info-text-link" href="/journal">View all journal articles <span aria-hidden="true">→</span></a>
                    </div>
                    <div class="info-cards">
                        <?php foreach ($relatedRows as $row): ?>
                            <?php
                            $image = $asset($row['media_path'] ?? null);
                            $href = $localUrl($row['link_path'] ?? null);
                            ?>
                            <article class="info-card">
                                <?php if ($image !== null): ?>
                                    <img src="<?= $esc($image) ?>" alt="<?= $esc($row['media_alt'] ?? '') ?>" loading="lazy" width="600" height="400">
                                <?php endif ?>
                                <div class="info-card-body">
                                    <h3><?= $esc($row['heading'] ?? '') ?></h3>
                                    <?php $paragraphs($row['body'] ?? '') ?>
                                    <?php if ($href !== null): ?>
                                        <a class="info-text-link" href="<?= $esc($href) ?>">
                                            <?= $esc($row['link_label'] ?? 'Read story') ?> <span aria-hidden="true">→</span>
                                        </a>
                                    <?php endif ?>
                                </div>
                            </article>
                        <?php endforeach ?>
                    </div>
                </section>
            <?php endif ?>
        </article>
    <?php endif ?>
