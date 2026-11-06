    <?php if ($pageKey === 'plant-care'): ?>
        <?php
        $careRows = $byRole(['care-topic']);
        $careHeading = $byRole(['section-heading'])[0]['heading'] ?? 'Care guides';
        ?>
        <?php if ($careRows !== []): ?>
            <section class="info-container info-section info-care-overview">
                <p class="info-eyebrow">Care guides</p>
                <h2><?= $esc($careHeading) ?></h2>
                <div class="info-care-grid">
                    <?php foreach ($careRows as $row): ?>
                        <?php
                        $category = (string) ($presentation['categories'][$row['section_key']] ?? $row['section_key']);
                        $id = $sectionId($row);
                        $summary = explode("\n\n", (string) ($row['body'] ?? ''), 2)[0] ?? '';
                        ?>
                        <article class="info-card">
                            <div class="info-card-body">
                                <h3><?= $esc($row['heading'] ?? '') ?></h3>
                                <p><?= $esc($summary) ?></p>
                                <a class="info-text-link" href="#<?= $esc($id) ?>">Read guide <span aria-hidden="true">→</span></a>
                            </div>
                        </article>
                    <?php endforeach ?>
                </div>
            </section>

            <section class="info-soft info-care-detail" aria-label="A closer look">
            <div class="info-container info-topic-navigation">
                <div class="info-filter-tabs" role="group" aria-label="Care topics">
                    <?php foreach ($careRows as $index => $row): ?>
                        <?php $category = (string) ($presentation['categories'][$row['section_key']] ?? $row['section_key']); ?>
                        <button type="button" data-info-filter="<?= $esc($category) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>">
                            <?= $esc($row['heading'] ?? '') ?>
                        </button>
                    <?php endforeach ?>
                </div>
            </div>
                <div class="info-container info-section info-split info-care-feature">
                    <?php $featureImage = $asset($presentation['care_feature_image'] ?? null); ?>
                    <?php if ($featureImage !== null): ?>
                        <img src="<?= $esc($featureImage) ?>" alt="<?= $esc($presentation['care_feature_alt'] ?? '') ?>" loading="lazy">
                    <?php endif ?>
                    <div>
                        <p class="info-eyebrow">A closer look</p>
                        <?php foreach ($careRows as $index => $row): ?>
                            <?php
                            $category = (string) ($presentation['categories'][$row['section_key']] ?? $row['section_key']);
                            $parts = explode("\n\n", (string) ($row['body'] ?? ''), 2);
                            $id = $sectionId($row);
                            ?>
                            <div data-care-preview="<?= $esc($category) ?>"<?= $index === 0 ? '' : ' hidden' ?>>
                                <h2><?= $esc($row['heading'] ?? '') ?> with confidence.</h2>
                                <?php if (!empty($parts[1])): ?><?php $paragraphs($parts[1]) ?><?php endif ?>
                                <a class="info-text-link" href="#<?= $esc($id) ?>">Read the full guide <span aria-hidden="true">→</span></a>
                            </div>
                        <?php endforeach ?>
                    </div>
                </div>
            </section>

            <section class="info-container info-section info-care-reading" aria-label="Full care guides">
                <?php foreach ($careRows as $index => $row): ?>
                    <?php
                    $id = $sectionId($row);
                    $parts = explode("\n\n", (string) ($row['body'] ?? ''), 2);
                    ?>
                    <section class="info-reading-section" id="<?= $esc($id) ?>">
                        <span class="info-section-number" aria-hidden="true"><?= $esc(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                        <div>
                            <h2><?= $esc($row['heading'] ?? '') ?></h2>
                            <?php if (isset($parts[0])): ?><p><?= $esc($parts[0]) ?></p><?php endif ?>
                            <?php if (isset($parts[1])): ?><?php $paragraphs($parts[1]) ?><?php endif ?>
                        </div>
                    </section>
                <?php endforeach ?>
                <p class="info-care-products-link">
                    <a class="info-text-link" href="/catalogue?kind=plant">View all plants <span aria-hidden="true">→</span></a>
                </p>
            </section>
        <?php endif ?>
        <?php $browsePlants = $rows['browse-plants'] ?? []; ?>
        <section class="info-container info-section">
            <div class="info-section-header">
                <div><p class="info-eyebrow">Find your plant</p><h2><?= $esc($browsePlants['heading'] ?? 'Care starts with the plant.') ?></h2></div>
                <a class="info-text-link" href="/catalogue?kind=plant">View all plants <span aria-hidden="true">→</span></a>
            </div>
            <div class="info-care-products">
                <?php foreach ($careProducts ?? [] as $plant): ?>
                    <a href="/product?id=<?= $esc($plant['product_id']) ?>">
                        <img src="<?= $esc($image($plant['image_path'] ?? null)) ?>" alt="<?= $esc($plant['name']) ?>" loading="lazy" width="400" height="340">
                        <h3><?= $esc($plant['name']) ?></h3><span>View plant details</span>
                    </a>
                <?php endforeach ?>
            </div>
        </section>
    <?php elseif ($pageKey === 'journal'): ?>
        <?php
        $articleCards = $byRole(['article-card', 'card']);
        $journalHeading = $byRole(['section-heading'])[0]['heading'] ?? 'Journal';
        $categories = ['Plant care', 'Spaces', 'Seasonal'];
        ?>
        <?php if ($categories !== []): ?>
            <div class="info-container info-topic-navigation">
                <div class="info-filter-tabs" role="group" aria-label="Article categories">
                    <button type="button" data-info-filter="all" aria-pressed="true">All</button>
                    <?php foreach ($categories as $category): ?>
                        <button type="button" data-info-filter="<?= $esc($category) ?>" aria-pressed="false">
                            <?= $esc($category) ?>
                        </button>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endif ?>

        <section class="info-container info-section" id="articles">
            <h2><?= $esc($journalHeading) ?></h2>
            <?php if ($articleCards !== []): ?>
                <div class="info-cards">
                    <?php foreach ($articleCards as $row): ?>
                        <?php
                        $category = (string) ($presentation['categories'][$row['section_key']] ?? '');
                        $image = $asset($row['media_path'] ?? null);
                        $href = $localUrl($row['link_path'] ?? null);
                        ?>
                        <article class="info-card" data-info-category="<?= $esc($category) ?>">
                            <?php if ($image !== null): ?>
                                <img src="<?= $esc($image) ?>" alt="<?= $esc($row['media_alt'] ?? '') ?>" loading="lazy" width="600" height="400">
                            <?php endif ?>
                            <div class="info-card-body">
                                <p class="info-eyebrow"><?= $esc($category) ?></p>
                                <h3><?= $esc($row['heading'] ?? '') ?></h3>
                                <?php $paragraphs($row['body'] ?? '') ?>
                                <?php if ($href !== null && !empty($row['link_label'])): ?>
                                    <a class="info-text-link" href="<?= $esc($href) ?>">
                                        <?= $esc($row['link_label']) ?> <span aria-hidden="true">→</span>
                                    </a>
                                <?php endif ?>
                            </div>
                        </article>
                    <?php endforeach ?>
                </div>
                <div class="info-empty" data-info-empty hidden role="status">
                    <h2>A new season of stories is on its way.</h2>
                    <p>No articles in this category yet. Explore the other stories while we put this collection together.</p>
                    <button type="button" class="info-button info-button-outline" data-info-reset>View all articles</button>
                </div>
            <?php else: ?>
                <div class="info-empty" role="status">
                    <h2>A new season of stories is on its way.</h2>
                    <p>Articles are coming soon. Check back for plant care, styling ideas and seasonal inspiration.</p>
                </div>
            <?php endif ?>
        </section>
        <?php if (isset($rows['feature'])): $feature = $rows['feature']; ?>
            <section class="info-soft">
                <div class="info-container info-section info-split">
                    <img src="<?= $esc($asset($feature['media_path']) ?? '') ?>" alt="<?= $esc($feature['media_alt']) ?>" loading="lazy">
                    <div><p class="info-eyebrow">A closer look</p><h2><?= $esc($feature['heading']) ?></h2>
                        <?php $paragraphs($feature['body']) ?>
                        <a class="info-button info-button-solid" href="<?= $esc($localUrl($feature['link_path']) ?? '/journal') ?>"><?= $esc($feature['link_label']) ?> <span aria-hidden="true">→</span></a>
                    </div>
                </div>
            </section>
        <?php endif ?>
    <?php endif ?>
