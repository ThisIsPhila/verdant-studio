    <?php if ($isPolicy): ?>
        <?php
        $policyRows = $byRole(['policy-section']);
        $summaryRows = $byRole(['terms-summary']);
        $policyVersion = $policyState['version'] ?? $policyState['published_version'] ?? null;
        ?>
        <?php if ($summaryRows !== []): ?>
            <div class="info-terms-summary">
                <?php foreach ($summaryRows as $row): ?>
                    <section>
                        <span aria-hidden="true">✿</span>
                        <div>
                            <h2><?= $esc($row['heading'] ?? '') ?></h2>
                            <?php $paragraphs($row['body'] ?? '') ?>
                        </div>
                    </section>
                <?php endforeach ?>
            </div>
        <?php endif ?>
        <?php if (!$preview && !empty($policyVersion) && !empty($policyState['effective_date'])): ?>
            <p class="info-container info-policy-meta">
                Version <?= $esc($policyVersion) ?> · Effective <?= $esc($policyState['effective_date']) ?>
            </p>
        <?php endif ?>

        <div class="info-container info-reading-layout">
            <?php $renderContentsNav($policyRows, 'On this page'); ?>
            <div class="info-reading-body">
                <?php if ($pageKey === 'terms'): ?>
                    <aside class="info-rights-callout">
                        <span aria-hidden="true">✿</span>
                        <h2>Your statutory rights remain protected.</h2>
                        <p>These terms do not limit consumer rights that cannot lawfully be excluded.</p>
                        <p><a href="/returns">Read the Returns & FAQs page</a>.</p>
                    </aside>
                <?php endif ?>
                <?php $renderReadingSections($policyRows); ?>
            </div>
        </div>
    <?php endif ?>

    <?php if ($isPolicy && $pageKey === 'privacy'): ?>
        <p class="info-policy-source">
            For information about complaints, see the
            <a href="https://inforegulator.org.za/complaints/" target="_blank" rel="noopener noreferrer">
                South African Information Regulator
            </a>.
        </p>
    <?php endif ?>
