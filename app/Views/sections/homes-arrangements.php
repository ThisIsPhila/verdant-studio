<section class="catalogue-section homes-arrangements" aria-labelledby="arrangements-heading">
    <div class="section-heading"><div><p class="eyebrow">Inspiration and configured offers</p><h2 id="arrangements-heading"><?= $e($section['heading'] ?? 'Pair plants and planters') ?></h2><p><?= $e($section['body'] ?? 'Configure current offers and confirm availability.') ?></p></div></div>
    <?php if ($compositions): ?>
        <ol class="arrangement-list homes-arrangement-list">
            <?php foreach ($compositions as $composition): ?>
                <li><article class="arrangement-card homes-arrangement-card">
                    <div class="arrangement-card__copy">
                        <p class="eyebrow"><?= $e($label($composition['arrangement_kind'])) ?> · <?= $e($composition['strategy'] === 'finished_stock' ? 'Stocked arrangement · check current availability' : 'Inspiration · configure to check availability') ?></p>
                        <h3><?= $e($composition['name']) ?></h3>
                        <p><?= $e($composition['explanation']) ?></p>
                        <?php if ($composition['purchase_components']): ?><h4>Purchased components</h4><ul><?php foreach ($composition['purchase_components'] as $component): ?><li><?= $e($component['label']) ?><?= $component['required'] ? ' · required' : ' · optional' ?></li><?php endforeach ?></ul><?php endif ?>
                        <?php if ($composition['choice_components']): ?><h4>Choose buy or rent</h4><ul><?php foreach ($composition['choice_components'] as $component): ?><li><?= $e($component['label']) ?><?= $component['required'] ? ' · required' : ' · optional' ?></li><?php endforeach ?></ul><?php endif ?>
                        <?php if ($composition['rental_components']): ?><h4>Rented components</h4><ul><?php foreach ($composition['rental_components'] as $component): ?><li><?= $e($component['label']) ?><?= $component['required'] ? ' · required' : ' · optional' ?></li><?php endforeach ?></ul><?php endif ?>
                        <a class="button" href="/configure?code=<?= $e(rawurlencode($composition['definition_code'])) ?>">Configure and check availability</a>
                        <p class="muted">Current component choices, eligibility and dates are checked during configuration. Browsing creates no stock hold.</p>
                    </div>
                </article></li>
            <?php endforeach ?>
        </ol>
    <?php else: ?>
        <p>No arrangements are currently available. Explore individual products instead.</p>
    <?php endif ?>
</section>
