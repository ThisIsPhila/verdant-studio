    <?php if ($pageKey === 'delivery'): ?>
        <?php
        $deliveryRows = $byRole(['reading']);
        $steps = $byRole(['step']);
        $callouts = $byRole(['callout']);
        ?>
        <div class="info-container info-reading-layout">
            <?php $renderContentsNav($deliveryRows, 'On this page'); ?>
            <div class="info-reading-body">
                <?php if ($steps !== []): ?>
                    <section class="info-delivery-steps" aria-label="How it works">
                        <h2>How it works</h2>
                        <p>From choosing your plants to preparing their new home.</p>
                        <div>
                            <?php foreach ($steps as $index => $row): ?>
                                <article>
                                    <span class="info-step-number"><?= $esc((string) ($index + 1)) ?></span>
                                    <h3><?= $esc($row['heading'] ?? '') ?></h3>
                                    <?php $paragraphs($row['body'] ?? '') ?>
                                </article>
                            <?php endforeach ?>
                        </div>
                    </section>
                <?php endif ?>
                <?php $renderReadingSections($deliveryRows); ?>
                <?php foreach ($callouts as $row): ?>
                    <aside class="info-delivery-callout">
                        <span aria-hidden="true">✿</span>
                        <div>
                            <h2><?= $esc($row['heading'] ?? '') ?></h2>
                            <?php $paragraphs($row['body'] ?? '') ?>
                        </div>
                    </aside>
                <?php endforeach ?>
            </div>
        </div>
    <?php endif ?>
