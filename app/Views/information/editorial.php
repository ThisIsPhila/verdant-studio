    <?php
    $hasGenericContent = !in_array(
        $pageKey,
        ['journal', 'contact', 'delivery', 'returns', 'journal-article', 'journal-houseplants', 'journal-pots', 'privacy', 'terms', 'plant-care'],
        true
    );
    ?>
    <?php if ($hasGenericContent): ?>
        <?php
        $genericRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => !in_array($roleOf($row), ['hero', 'script-line', 'section-heading'], true)
        ));
        $index = 0;
        ?>
        <?php while ($index < count($genericRows)): ?>
            <?php
            $row = $genericRows[$index];
            $role = $roleOf($row);
            $rowGroup = (string) ($presentation['groups'][$row['section_key']] ?? 'cards');
            $cardRoles = ['card', 'article-card', 'principle'];
            ?>
            <?php if (in_array($role, $cardRoles, true)): ?>
                <?php
                $groupRows = [];
                while ($index < count($genericRows)) {
                    $candidate = $genericRows[$index];
                    $candidateRole = $roleOf($candidate);
                    $candidateGroup = (string) ($presentation['groups'][$candidate['section_key']] ?? 'cards');
                    if (!in_array($candidateRole, $cardRoles, true) || $candidateGroup !== $rowGroup) {
                        break;
                    }
                    $groupRows[] = $candidate;
                    $index++;
                }
                ?>
                <?php if ($rowGroup === 'principles'): ?>
                    <section class="info-soft">
                        <div class="info-container info-section">
                            <p class="info-eyebrow">Our principles</p>
                            <h2>Thoughtful plants. Beautiful spaces.</h2>
                            <div class="info-principles">
                                <?php foreach ($groupRows as $principle): ?>
                                    <article class="info-principle">
                                        <span aria-hidden="true"><?= $esc($presentation['icons'][$principle['section_key']] ?? '✿') ?></span>
                                        <div>
                                            <h3><?= $esc($principle['heading'] ?? '') ?></h3>
                                            <?php $paragraphs($principle['body'] ?? '') ?>
                                        </div>
                                    </article>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="info-container info-section">
                        <div class="info-cards">
                            <?php foreach ($groupRows as $card): ?>
                                <?php
                                $image = $asset($card['media_path'] ?? null);
                                $href = $localUrl($card['link_path'] ?? null);
                                ?>
                                <article class="info-card">
                                    <?php if ($image !== null): ?>
                                        <img src="<?= $esc($image) ?>" alt="<?= $esc($card['media_alt'] ?? '') ?>" loading="lazy" width="600" height="400">
                                    <?php endif ?>
                                    <div class="info-card-body">
                                        <p class="info-eyebrow"><?= $esc($presentation['categories'][$card['section_key']] ?? '') ?></p>
                                        <h3><?= $esc($card['heading'] ?? '') ?></h3>
                                        <?php $paragraphs($card['body'] ?? '') ?>
                                        <?php if ($href !== null && !empty($card['link_label'])): ?>
                                            <a class="info-text-link" href="<?= $esc($href) ?>">
                                                <?= $esc($card['link_label']) ?> <span aria-hidden="true">→</span>
                                            </a>
                                        <?php endif ?>
                                    </div>
                                </article>
                            <?php endforeach ?>
                        </div>
                    </section>
                <?php endif ?>
            <?php else: ?>
                <?php
                $id = $sectionId($row);
                $image = $role === 'soft' ? null : $asset($row['media_path'] ?? null);
                $imageSide = $presentation['image_side'][$row['section_key']] ?? 'left';
                $href = $localUrl($row['link_path'] ?? null);
                $eyebrowText = $presentation['section_eyebrows'][$row['section_key']] ?? '';
                $index++;
                ?>
                <?php if ($role === 'split'): ?>
                    <section class="info-container info-section info-split" id="<?= $esc($id) ?>">
                        <?php if ($image !== null && $imageSide === 'left'): ?>
                            <img src="<?= $esc($image) ?>" alt="<?= $esc($row['media_alt'] ?? '') ?>" loading="lazy">
                        <?php endif ?>
                        <div>
                            <?php if ($eyebrowText !== ''): ?><p class="info-eyebrow"><?= $esc($eyebrowText) ?></p><?php endif ?>
                            <h2><?= $esc($row['heading'] ?? '') ?></h2>
                            <?php $paragraphs($row['body'] ?? '') ?>
                            <?php if ($href !== null && !empty($row['link_label'])): ?>
                                <a class="info-text-link" href="<?= $esc($href) ?>">
                                    <?= $esc($row['link_label']) ?> <span aria-hidden="true">→</span>
                                </a>
                            <?php endif ?>
                        </div>
                        <?php if ($image !== null && $imageSide === 'right'): ?>
                            <img src="<?= $esc($image) ?>" alt="<?= $esc($row['media_alt'] ?? '') ?>" loading="lazy">
                        <?php endif ?>
                    </section>
                <?php else: ?>
                    <section class="<?= $role === 'soft' ? 'info-soft' : '' ?>" id="<?= $esc($id) ?>">
                        <div class="info-container info-section">
                            <?php if ($eyebrowText !== ''): ?><p class="info-eyebrow"><?= $esc($eyebrowText) ?></p><?php endif ?>
                            <h2><?= $esc($row['heading'] ?? '') ?></h2>
                            <?php if ($image !== null): ?>
                                <img class="info-section-image" src="<?= $esc($image) ?>" alt="<?= $esc($row['media_alt'] ?? '') ?>" loading="lazy">
                            <?php endif ?>
                            <?php $paragraphs($row['body'] ?? '') ?>
                            <?php if ($href !== null && !empty($row['link_label'])): ?>
                                <a class="info-text-link" href="<?= $esc($href) ?>">
                                    <?= $esc($row['link_label']) ?> <span aria-hidden="true">→</span>
                                </a>
                            <?php endif ?>
                        </div>
                    </section>
                <?php endif ?>
            <?php endif ?>
        <?php endwhile ?>
    <?php endif ?>
