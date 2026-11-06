                            <fieldset class="composition-component" data-configuration-choice>
                            <legend <?= (int) $componentDefinition['max_selections'] === 1 ? 'class="sr-only"' : '' ?>>Choice <?= $e($choiceIndex + 1) ?><?= $choiceIndex > 0 || !$componentDefinition['required'] ? ' · optional; leave empty to skip' : '' ?></legend>
                            <input type="hidden" name="<?= $e($name) ?>[component_key]" value="<?= $e($componentDefinition['component_key']) ?>">
                            <div class="vision-choice-grid">
                                <?php if (!$componentDefinition['required'] || $choiceIndex > 0): ?><label class="vision-choice-tile"><input type="radio" name="<?= $e($name) ?>[product_id]" value="" data-vision-choice <?= $chosenId === 0 ? 'checked' : '' ?>>None</label><?php endif ?>
                                <?php foreach ($options as $tileOption): $tileImage = \Verdant\Storefront\VisionLandingPresenter::image($tileOption); $tileIsPot = ($tileOption['accessory_subtype'] ?? '') === 'pot'; ?>
                                    <label class="vision-choice-tile<?= $tileIsPot ? ' vision-choice-tile--mat' : '' ?>">
                                        <input type="radio" name="<?= $e($name) ?>[product_id]" value="<?= $e($tileOption['product_id']) ?>" data-vision-choice <?= $chosenId === (int) $tileOption['product_id'] ? 'checked' : '' ?> <?= $componentDefinition['required'] && $choiceIndex === 0 ? 'required' : '' ?>>
                                        <?php if ($tileImage !== null): ?><img src="<?= $e($tileImage) ?>" alt="" loading="lazy"><?php else: $product = $tileOption; require __DIR__ . '/../components/product-thumbnail.php'; endif ?>
                                        <span><?= $e($tileOption['name']) ?></span>
                                        <?php if (!empty($tileOption['display_size'] ?? $tileOption['size_label'] ?? '') && !in_array(strtolower($tileOption['display_size'] ?? $tileOption['size_label']), ['unknown','standard'], true)): ?><small><?= $e($tileOption['display_size'] ?? $tileOption['size_label']) ?></small><?php endif ?>
                                        <?php if (!empty($tileOption['buy_enabled'])): ?><small><?= $e($money($tileOption['retail_price_minor'])) ?></small><?php endif ?>
                                    </label>
                                <?php endforeach ?>
                            </div>
                            <select id="component-<?= $e($rowIndex) ?>-product" data-product-select hidden aria-hidden="true" tabindex="-1">
                                <option value="">Select an item</option>
                                <?php foreach ($options as $option): ?>
                                    <option value="<?= $e($option['product_id']) ?>" data-sale-enabled="<?= $option['buy_enabled'] ? 'true' : 'false' ?>" data-rent-enabled="<?= $option['rent_enabled'] ? 'true' : 'false' ?>" <?= $chosenId === (int) $option['product_id'] ? 'selected' : '' ?>><?= $e($option['name']) ?><?php if (($option['size_label'] ?? '') !== '' && !in_array(strtolower($option['size_label']), ['unknown', 'standard'], true)): ?> · <?= $e($option['size_label']) ?><?php endif ?><?php if ($option['buy_enabled']): ?> · Buy <?= $e($money($option['retail_price_minor'])) ?><?php endif ?><?php if ($option['rent_enabled']): ?> · Rent <?= $e($money($option['rental_daily_rate_minor'])) ?>/day<?php endif ?></option>
                                <?php endforeach ?>
                            </select>
                            <div class="vision-choice-controls"><label for="component-<?= $e($rowIndex) ?>-mode">Choose Buy or Rent</label>
                            <select id="component-<?= $e($rowIndex) ?>-mode" name="<?= $e($name) ?>[mode]" data-mode-select>
                                <?php if ($componentDefinition['permitted_mode'] !== 'rent'): ?><option value="sale" <?= $mode === 'sale' ? 'selected' : '' ?> <?= $selectedOption !== null && !$selectedOption['buy_enabled'] ? 'disabled' : '' ?>>Buy</option><?php endif ?>
                                <?php if ($componentDefinition['permitted_mode'] !== 'sale'): ?><option value="rent" <?= $mode === 'rent' ? 'selected' : '' ?> <?= $selectedOption !== null && !$selectedOption['rent_enabled'] ? 'disabled' : '' ?>>Rent</option><?php endif ?>
                            </select>
                            <label for="component-<?= $e($rowIndex) ?>-quantity">Quantity</label>
                            <input id="component-<?= $e($rowIndex) ?>-quantity" type="number" min="<?= $e($componentDefinition['min_quantity']) ?>" max="<?= $e($componentDefinition['max_quantity']) ?>" name="<?= $e($name) ?>[quantity]" value="<?= $e($choice['quantity'] ?? $componentDefinition['min_quantity']) ?>">
                            <div data-rental-fields <?= $rentalVisible ? '' : 'hidden' ?>>
                                <label for="component-<?= $e($rowIndex) ?>-start">Rental start</label>
                                <input id="component-<?= $e($rowIndex) ?>-start" type="date" name="<?= $e($name) ?>[start_date]" value="<?= $e($choice['start_date'] ?? '') ?>" <?= $rentalVisible ? 'required' : 'disabled' ?>>
                                <label for="component-<?= $e($rowIndex) ?>-end">Rental end (inclusive)</label>
                                <input id="component-<?= $e($rowIndex) ?>-end" type="date" name="<?= $e($name) ?>[end_date_inclusive]" value="<?= $e($choice['end_date_inclusive'] ?? '') ?>" <?= $rentalVisible ? 'required' : 'disabled' ?>>
                            </div>
                            </div>
                            </fieldset>
