<?php
$pairOptions = $section['pair_options'] ?? [];
$initialPair = $pairOptions[0] ?? null;
$initialPot = $initialPair['pots'][0] ?? null;
?>
<section class="editorial-section configuration-entry" data-landing-pair>
    <div class="configuration-entry__intro">
        <p class="eyebrow">Create your perfect pair</p>
        <h2><?= $e($section['heading']) ?></h2>
        <p class="configuration-entry__body"><?= $e($section['body']) ?></p>
        <p class="configuration-entry__signature">Thoughtfully paired.<br>Naturally striking.</p>
    </div>
    <?php if ($initialPair !== null): ?>
        <div class="configuration-entry__preview">
            <?php
            $mediaCardsUsed = true;
            $landingGalleryUsed = true;
            $productGalleryAssetsExternal = true;
            $product = ['product_id' => 'landing-pair'];
            $mediaGallery = $section['pair_gallery'] ?? null;
            require __DIR__ . '/../components/product-gallery.php';
            ?>
        </div>
        <form class="configuration-entry__form" action="/cart/add" method="post" id="landing-pair-form">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <input type="hidden" name="quantity" value="1">
            <label for="landing-plant">1. Choose a plant</label>
            <div class="configuration-entry__plant-choice">
                <div class="configuration-entry__choice-image">
                    <?php foreach ($pairOptions as $pairPlant): ?>
                        <div data-pair-summary-image="<?= $e($pairPlant['id']) ?>"<?= $pairPlant['id'] === $initialPair['id'] ? '' : ' hidden' ?>>
                            <?php $product = $pairPlant + ['product_id' => $pairPlant['id']]; require __DIR__ . '/../components/product-thumbnail.php'; ?>
                        </div>
                    <?php endforeach ?>
                </div>
                <select id="landing-plant" name="product_id" data-pair-plant>
                    <?php foreach ($pairOptions as $pairPlant): ?>
                        <option value="<?= $e($pairPlant['id']) ?>"><?= $e($pairPlant['name']) ?> · <?= $e($pairPlant['size']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <fieldset class="configuration-entry__variants">
                <legend>2. Plant variant</legend>
                <div class="configuration-entry__choices" data-pair-variants>
                    <?php foreach ($pairOptions as $variant): ?>
                        <?php $sameFamily = $variant['family'] > 0 ? $variant['family'] === $initialPair['family'] : $variant['id'] === $initialPair['id']; ?>
                        <button type="button" data-pair-variant="<?= $e($variant['id']) ?>" aria-pressed="<?= $variant['id'] === $initialPair['id'] ? 'true' : 'false' ?>"<?= $sameFamily ? '' : ' hidden' ?>>
                            <span><?= $e($variant['size']) ?></span><small><?= $e($money($variant['price'])) ?></small>
                        </button>
                    <?php endforeach ?>
                </div>
            </fieldset>
            <fieldset class="configuration-entry__pots">
                <legend>3. Choose a pot &amp; finish</legend>
                <?php foreach ($pairOptions as $pairPlant): ?>
                    <div class="configuration-entry__choices" data-pair-pots="<?= $e($pairPlant['id']) ?>"<?= $pairPlant['id'] === $initialPair['id'] ? '' : ' hidden' ?>>
                        <?php foreach ($pairPlant['pots'] as $index => $pairPot): ?>
                            <label class="configuration-entry__pot">
                                <input type="radio" name="pot_id" value="<?= $e($pairPot['id']) ?>" data-pair-pot<?= $pairPlant['id'] === $initialPair['id'] ? ($index === 0 ? ' checked' : '') : ' disabled' ?>>
                                <span class="configuration-entry__pot-image">
                                    <?php $product = $pairPot + ['product_id' => $pairPot['id']]; $thumbnailPreferPhoto = true; require __DIR__ . '/../components/product-thumbnail.php'; unset($thumbnailPreferPhoto); ?>
                                </span>
                                <span><?= $e(preg_replace('/\s+(?:planter|pot).*$/i', '', $pairPot['name'])) ?></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                <?php endforeach ?>
            </fieldset>
            <p class="configuration-entry__size" data-pair-size><?= $e($initialPair['size']) ?> plant · Compatible pots</p>
            <script type="application/json" data-pair-options><?= json_encode($pairOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
        </form>
        <div class="configuration-entry__summary">
            <p class="eyebrow">Your set</p>
            <div class="configuration-entry__set">
                <div class="configuration-entry__set-image">
                    <?php
                    $product = ['product_id' => 'landing-pair-summary'];
                    require __DIR__ . '/../components/product-gallery.php';
                    ?>
                </div>
                <div>
                    <p data-pair-name><?= $e($initialPair['name']) ?> · <?= $e($initialPair['size']) ?></p>
                    <p data-pair-pot-name><?= $e($initialPot['name']) ?></p>
                </div>
            </div>
            <div class="configuration-entry__total">
                <span class="eyebrow">Total</span>
                <strong data-pair-total><?= $e($money($initialPair['price'] + $initialPot['price'])) ?></strong>
            </div>
            <p data-pair-status role="status" aria-live="polite"></p>
            <button class="button" type="submit" form="landing-pair-form">Add configured set →</button>
        </div>
    <?php elseif ($section['link_path'] !== null): ?>
        <a class="button" href="<?= $e($section['link_path']) ?>"><?= $e($section['link_label']) ?></a>
    <?php endif ?>
</section>
