<?php
$visionPage = true;
$visionComponentStyles = ['arrangements'];
$hero = ['image' => '/assets/vision/images/hero-configure.jpg', 'title' => 'Create your perfect pair.', 'body' => 'Choose a plant and pair it with a compatible pot for your space.'];
require __DIR__ . '/vision/page-hero.php';
?>
<div class="vision-container vision-configuration-page">
<details class="vision-other-arrangements"><summary>Other arrangements</summary><nav aria-label="Arrangement choices"><?php foreach ($definitions as $availableDefinition): ?><a href="/configure?code=<?= $e(rawurlencode($availableDefinition['definition_code'])) ?>"><?= $e($availableDefinition['name']) ?></a><?php endforeach ?></nav></details>
<?php if ($savedNotice): ?><div class="alert" role="status">Your arrangement was saved.</div><?php endif ?>
<?php if ($errors): ?><div class="alert" role="alert"><h2>Check your arrangement</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>

<?php if (!$definition): ?>
    <section class="account-section" aria-labelledby="available-arrangements-title">
        <p class="eyebrow">Find your starting point</p>
        <h2 id="available-arrangements-title">Available arrangements</h2>
        <?php $landingOffers = is_array($arrangementOffers ?? null) ? $arrangementOffers : []; ?>
        <?php if ($landingOffers !== []): ?>
            <div class="arrangement-offer-grid" aria-label="Available arrangements">
                <?php foreach ($landingOffers as $arrangement): ?>
                    <?php require __DIR__ . '/components/arrangement-offer-card.php'; ?>
                <?php endforeach ?>
            </div>
        <?php elseif ($definitions): ?>
            <div class="product-grid" aria-label="Available arrangements">
                <?php foreach ($definitions as $item): ?>
                    <?php
                    $component = [
                        'title' => $item['name'],
                        'description' => $item['strategy'] === 'finished_stock'
                            ? 'A finished arrangement. Current stock and price are checked before checkout.'
                            : 'Choose the plant, available variant and compatible pot for a current estimate.',
                        'kind' => $label($item['arrangement_kind']),
                        'href' => '/configure?code=' . rawurlencode($item['definition_code']),
                        'action_label' => 'Configure arrangement',
                    ];
                    require __DIR__ . '/components/arrangement-card.php';
                    ?>
                <?php endforeach ?>
            </div>
        <?php else: ?>
            <p>No arrangements are available right now.</p>
        <?php endif ?>
    </section>
<?php else: ?>
    <section class="vision-configuration-builder" aria-labelledby="configure-arrangement-title">

        <h2 id="configure-arrangement-title" class="vision-config-title"><?= $e($definition['name']) ?></h2>
        <div class="configuration-media-layout">
        <?php if (is_array($mediaGallery ?? null) && is_array($mediaProduct ?? null)): ?>
            <div class="configuration-media-preview">
                <?php $product = $mediaProduct; require __DIR__ . '/components/product-gallery.php'; unset($product); ?>
                <?php if (($mediaGallery['mode'] ?? '') === 'scene'): ?>
                    <p class="muted">Pair preview · this shows the selected plant and its currently compatible pot only.</p>
                <?php else: ?>
                    <p class="muted">Your selected plant.</p>
                <?php endif ?>
            </div>
        <?php endif ?>
        <div class="configuration-media-form">
        <?php if (($mediaNotice ?? '') !== ''): ?><p class="alert" role="status"><?= $e($mediaNotice) ?></p><?php endif ?>
        <p class="muted" data-configuration-refresh-status aria-live="polite">Select a plant or pot to update your pairing.</p>
        <form class="filters composition-builder" method="post" action="/compositions/quote">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <input type="hidden" name="code" value="<?= $e($definition['definition_code']) ?>">
            <?php $selectionByKey = []; foreach ($selection as $row) { $selectionByKey[$row['component_key']][] = $row; } ?>

            <?php if ($definition['strategy'] === 'finished_stock'): ?>
                <?php
                $productId = (int) $definition['finished_product_id'];
                $product = $finishedProduct;
                $mode = $selection[0]['mode'] ?? ($product && !$product['buy_enabled'] && $product['rent_enabled'] ? 'rent' : 'sale');
                if ($product && $mode === 'sale' && !$product['buy_enabled']) {
                    $mode = $product['rent_enabled'] ? 'rent' : '';
                } elseif ($product && $mode === 'rent' && !$product['rent_enabled']) {
                    $mode = $product['buy_enabled'] ? 'sale' : '';
                }
                $rentalVisible = $mode === 'rent';
                ?>
                <fieldset class="composition-component" data-configuration-choice data-product-sale="<?= $product && $product['buy_enabled'] ? 'true' : 'false' ?>" data-product-rent="<?= $product && $product['rent_enabled'] ? 'true' : 'false' ?>">
                    <legend>Finished arrangement · required</legend>
                    <input type="hidden" name="selection[0][component_key]" value="finished">
                    <input type="hidden" name="selection[0][product_id]" value="<?= $e($productId) ?>">
                    <p><?= $product ? $e($product['name']) : 'Arrangement' ?></p>
                    <label for="finished-mode">Choose Buy or Rent</label>
                    <select id="finished-mode" name="selection[0][mode]" data-mode-select>
                        <option value="sale" <?= $mode === 'sale' ? 'selected' : '' ?> <?= !$product || !$product['buy_enabled'] ? 'disabled' : '' ?>>Buy</option>
                        <option value="rent" <?= $mode === 'rent' ? 'selected' : '' ?> <?= !$product || !$product['rent_enabled'] ? 'disabled' : '' ?>>Rent</option>
                    </select>
                    <label for="finished-quantity">Quantity</label>
                    <input id="finished-quantity" type="number" min="1" max="10000" name="selection[0][quantity]" value="<?= $e($selection[0]['quantity'] ?? 1) ?>">
                    <div data-rental-fields <?= $rentalVisible ? '' : 'hidden' ?>>
                        <label for="finished-start">Rental start</label>
                        <input id="finished-start" type="date" name="selection[0][start_date]" value="<?= $e($selection[0]['start_date'] ?? '') ?>" <?= $rentalVisible ? 'required' : 'disabled' ?>>
                        <label for="finished-end">Rental end (inclusive)</label>
                        <input id="finished-end" type="date" name="selection[0][end_date_inclusive]" value="<?= $e($selection[0]['end_date_inclusive'] ?? '') ?>" <?= $rentalVisible ? 'required' : 'disabled' ?>>
                    </div>
                </fieldset>
            <?php else: ?>
                <?php $rowIndex = 0; foreach ($definition['components'] as $componentDefinition):
                    $choices = array_slice($selectionByKey[$componentDefinition['component_key']] ?? [], 0, max(1, (int) $componentDefinition['max_selections']));
                    $choices = array_pad($choices, max(1, (int) $componentDefinition['max_selections']), []);
                    $options = $componentDefinition['source_type'] === 'compatible_pot'
                        ? ($compatible[$componentDefinition['component_key']] ?? []) : $componentDefinition['product_options'];
                    ?>
                    <fieldset class="composition-component">
                        <legend><?= $e(($componentDefinition['component_role'] ?? '') === 'plant' ? 'Choose your plant' : ($componentDefinition['source_type'] === 'compatible_pot' ? 'Choose a compatible pot' : $componentDefinition['label'])) ?><?= $componentDefinition['required'] ? ' · required' : ' · optional' ?></legend>
                        <?php foreach ($choices as $choiceIndex => $choice):
                            $name = 'selection[' . $rowIndex . ']';
                            $chosenId = (int) ($choice['product_id'] ?? 0);
                            $selectedOption = null;
                            foreach ($options as $candidateOption) {
                                if ((int) $candidateOption['product_id'] === $chosenId) { $selectedOption = $candidateOption; break; }
                            }
                            $mode = $choice['mode'] ?? '';
                            if ($mode === '' && $selectedOption !== null) {
                                $mode = $selectedOption['buy_enabled'] ? 'sale' : ($selectedOption['rent_enabled'] ? 'rent' : '');
                            }
                            if ($selectedOption !== null && $mode === 'sale' && !$selectedOption['buy_enabled']) {
                                $mode = $selectedOption['rent_enabled'] ? 'rent' : '';
                            } elseif ($selectedOption !== null && $mode === 'rent' && !$selectedOption['rent_enabled']) {
                                $mode = $selectedOption['buy_enabled'] ? 'sale' : '';
                            }
                            if ($mode === '' && $selectedOption === null && $componentDefinition['permitted_mode'] === 'rent') { $mode = 'rent'; }
                            $rentalVisible = $mode === 'rent';
                            ?>
                            <?php require __DIR__ . '/vision/configuration-choice.php'; ?>
                            <?php ++$rowIndex; endforeach ?>
                        <?php if ($componentDefinition['source_type'] === 'compatible_pot' && !$options): ?>
                            <p class="muted">Choose a plant, then select Update choices to show its explicitly listed compatible pots.</p>
                        <?php endif ?>
                    </fieldset>
                <?php endforeach ?>
            <?php endif ?>

            <div class="field"><label for="saved-name">Name this arrangement (optional unless saving)</label><input id="saved-name" type="text" name="name" maxlength="120" value="<?= $e(is_string($_POST['name'] ?? null) ? $_POST['name'] : '') ?>"></div>
            <div class="form-actions">
                <button type="submit" formaction="/compositions/options" formnovalidate>Update choices</button>
                <button type="submit">Get estimate</button>
                <?php if ($actor !== null): ?><button type="submit" formaction="/compositions/save">Save arrangement</button><button type="submit" formaction="/compositions/prepare">Prepare checkout</button><?php else: ?><a class="button" href="/login">Sign in to save or prepare</a><?php endif ?>
            </div>
            <noscript><p class="muted">For a rental, choose Rent and update your choices to enter the dates.</p></noscript>
        </form>
        </div>
        </div>
    </section>

    <?php if ($quote): ?>
        <section class="vision-selection-summary" data-vision-quote aria-live="polite">
            <p class="eyebrow"><?= $quote['available'] ? 'Current estimate' : 'Availability needs another choice' ?></p>
            <?php $component = $summary; require __DIR__ . '/components/item-summary.php'; ?>
            <p class="muted">This estimate creates no stock hold. Availability and current prices are checked again when you prepare checkout.</p>
        </section>
    <?php else: ?><section class="vision-selection-summary" data-vision-quote><h2>Your selection</h2><p>Complete your choices and dates to see the current estimate.</p></section><?php endif ?>
<?php endif ?>
</div>
<script src="/assets/js/configuration.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/configuration.js')) ?>" defer></script>
<script src="/assets/js/vision-configuration.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/vision-configuration.js')) ?>" defer></script>
