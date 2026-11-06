<?php
$pairOptions = $section['pair_options'] ?? [];
$initialPair = $pairOptions[0] ?? null;
$initialPot = $initialPair['pots'][0] ?? null;
?>
<section data-vision-section="configuration-entry" class="bg-surface" data-landing-pair>
  <div class="mx-auto grid max-w-7xl items-center gap-10 px-6 py-14 lg:grid-cols-[1.4fr_1fr_0.8fr]">
    <div class="grid min-w-0 grid-cols-1 items-center gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
    <div class="min-w-0">
        <p class="eyebrow">Create your perfect pair</p>
        <h2 class="mt-3 text-4xl leading-tight sm:text-5xl lg:text-3xl"><?= $e($section['heading']) ?></h2>
        <p class="mt-4 max-w-xs text-sm text-muted-foreground"><?= $e($section['body']) ?></p>
        <p class="mt-5 font-script text-3xl leading-tight">Thoughtfully paired.<br>Naturally striking.</p>
    </div>
    <?php if ($initialPair !== null): ?>
        <div class="vision-pair-preview w-44 justify-self-center md:w-56">
            <?php
            $mediaCardsUsed = true;
            $landingGalleryUsed = true;
            $productGalleryAssetsExternal = true;
            $product = ['product_id' => 'landing-pair'];
            $mediaGallery = $section['pair_gallery'] ?? null;
            require __DIR__ . '/../components/product-gallery.php';
            ?>
        </div>
    </div>
        <form class="vision-pair-form space-y-6" action="/cart/add" method="post" id="landing-pair-form">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <input type="hidden" name="quantity" value="1">
            <label for="landing-plant">1. Choose a plant</label>
            <div class="vision-pair-plant mt-2 flex items-center gap-3 border bg-card p-2">
                <div class="vision-pair-choice-image h-10 w-10 shrink-0 overflow-hidden">
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
            <fieldset class="vision-pair-variants">
                <legend>2. Plant variant</legend>
                <div class="mt-2 flex gap-2" data-pair-variants>
                    <?php foreach ($pairOptions as $variant): ?>
                        <?php $sameFamily = $variant['family'] > 0 ? $variant['family'] === $initialPair['family'] : $variant['id'] === $initialPair['id']; ?>
                        <button class="vision-pair-variant flex-1 border bg-card py-2 text-center text-xs" type="button" data-pair-variant="<?= $e($variant['id']) ?>" aria-pressed="<?= $variant['id'] === $initialPair['id'] ? 'true' : 'false' ?>"<?= $sameFamily ? '' : ' hidden' ?>>
                            <span><?= $e($variant['size']) ?></span><small><?= $e($money($variant['price'])) ?></small>
                        </button>
                    <?php endforeach ?>
                </div>
            </fieldset>
            <fieldset class="vision-pair-pots">
                <legend>3. Choose a pot &amp; finish</legend>
                <?php foreach ($pairOptions as $pairPlant): ?>
                    <div class="mt-2 grid grid-cols-4 gap-2" data-pair-pots="<?= $e($pairPlant['id']) ?>"<?= $pairPlant['id'] === $initialPair['id'] ? '' : ' hidden' ?>>
                        <?php foreach ($pairPlant['pots'] as $index => $pairPot): ?>
                            <label class="vision-pair-pot relative flex min-w-0 cursor-pointer flex-col items-center gap-1.5 border border-transparent p-2 text-[0.65rem]">
                                <input type="radio" name="pot_id" value="<?= $e($pairPot['id']) ?>" data-pair-pot<?= $pairPlant['id'] === $initialPair['id'] ? ($index === 0 ? ' checked' : '') : ' disabled' ?>>
                                <span class="vision-pair-choice-image vision-pair-pot-image block overflow-hidden">
                                    <?php $product = $pairPot + ['product_id' => $pairPot['id']]; $thumbnailPreferPhoto = true; require __DIR__ . '/../components/product-thumbnail.php'; unset($thumbnailPreferPhoto); ?>
                                </span>
                                <span><?= $e(preg_replace('/\s+(?:planter|pot).*$/i', '', $pairPot['name'])) ?></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                <?php endforeach ?>
            </fieldset>
            <p class="text-xs text-muted-foreground" data-pair-size><?= $e($initialPair['size']) ?> plant · Compatible pots</p>
            <script type="application/json" data-pair-options><?= json_encode($pairOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
        </form>
        <div class="vision-pair-summary border-t pt-6 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-8">
            <p class="eyebrow">Your set</p>
            <div class="mt-4 flex items-center gap-3">
                <div class="vision-pair-set-image h-20 w-16 shrink-0 overflow-hidden">
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
            <div class="mt-6">
                <span class="eyebrow">Total</span>
                <strong class="block font-display text-3xl font-normal" data-pair-total><?= $e($money($initialPair['price'] + $initialPot['price'])) ?></strong>
            </div>
            <p data-pair-status role="status" aria-live="polite"></p>
            <button class="btn-solid mt-4" type="submit" form="landing-pair-form">Add configured set →</button>
        </div>
    <?php else: ?>
    </div>
    <?php if ($section['link_path'] !== null): ?>
        <a class="button" href="<?= $e($section['link_path']) ?>"><?= $e($section['link_label']) ?></a>
    <?php endif ?>
    <?php endif ?>
  </div>
</section>
