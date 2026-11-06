    <?php if ($pageKey === 'returns'): ?>
        <?php
        $faqRows = $byRole(['faq']);
        $topics = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($presentation['categories'][$row['section_key']] ?? 'purchases'),
            $faqRows
        ))));
        $returnSections = $byRole(['reading']);
        ?>
        <?php if ($returnSections !== []): ?>
            <section class="info-container info-section info-returns-intro">
                <div class="info-cards">
                    <?php foreach ($returnSections as $row): ?>
                        <article class="info-card">
                            <div class="info-card-body">
                                <h3><?= $esc($row['heading'] ?? '') ?></h3>
                                <?php $paragraphs($row['body'] ?? '') ?>
                            </div>
                        </article>
                    <?php endforeach ?>
                </div>
            </section>
        <?php endif ?>

        <div class="info-help-tabs" role="group" aria-label="FAQ topics">
            <?php foreach ($topics as $index => $topic): ?>
                <button type="button" data-info-filter="<?= $esc($topic) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>">
                    <?= $esc($presentation['topic_labels'][$topic] ?? ucfirst($topic)) ?>
                </button>
            <?php endforeach ?>
        </div>
        <section class="info-container info-section info-faq-panel">
            <div class="info-faq-heading">
                <div>
                    <p class="info-eyebrow">Frequently asked questions</p>
                    <h2>Your questions, answered.</h2>
                </div>
                <label class="info-faq-search">
                    <span aria-hidden="true">⌕</span>
                    <span class="info-visually-hidden">Search FAQs</span>
                    <input type="search" data-info-search placeholder="Search FAQs…">
                </label>
            </div>
            <div class="info-faq-list">
                <?php foreach ($faqRows as $row): ?>
                    <?php $topic = (string) ($presentation['categories'][$row['section_key']] ?? 'purchases'); ?>
                    <details class="info-faq-item" data-info-category="<?= $esc($topic) ?>">
                        <summary><?= $esc($row['heading'] ?? '') ?><span aria-hidden="true">⌄</span></summary>
                        <div class="info-faq-answer"><?php $paragraphs($row['body'] ?? '') ?></div>
                    </details>
                <?php endforeach ?>
                <?php if ($faqRows === []): ?>
                    <p class="info-faq-empty">Frequently asked questions will be added after the service details are confirmed.</p>
                <?php else: ?>
                    <p class="info-faq-empty" data-info-empty hidden>
                        No answers in this topic match your search. Try another search or choose a different FAQ topic.
                    </p>
                <?php endif ?>
            </div>
            <?php if ($preview): ?>
                <p class="info-review-note">These answers are review copy. Confirm business rules and a working support route before publishing them.</p>
            <?php endif ?>
            <p class="info-faq-links">
                <a href="/delivery">Delivery & collection</a>
                <a href="/contact#customer">Contact customer support</a>
            </p>
        </section>
    <?php endif ?>
