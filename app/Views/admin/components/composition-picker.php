<?php $idField = $pickerType . '_id'; $selectedIds = $component[$pickerType . '_ids']; ?>
<details class="recipe-picker" data-recipe-picker>
    <summary>Choose <?= $pickerType === 'product' ? 'products' : 'product families' ?></summary>
    <label>Search by name <input type="search" data-picker-search hidden></label>
    <div class="recipe-picker-options" data-picker-options>
    <?php foreach ($pickerOptions as $option):
        $isSelected = in_array((int) $option[$idField], $selectedIds, true);
        $available = $option['archived_at'] === null && ($option['category_archived_at'] ?? null) === null;
    ?>
        <label data-picker-option data-search="<?= $e(strtolower($option['name'])) ?>">
            <input type="checkbox" name="<?= $e($prefix) ?>[<?= $e($pickerType) ?>_ids][]" value="<?= $e($option[$idField]) ?>" data-item-name="<?= $e($option['name']) ?>" <?= $isSelected ? 'checked' : '' ?> <?= !$available && !$isSelected ? 'disabled' : '' ?>>
            <span><?= $e($option['name']) ?><?= !$available ? ' · Unavailable' : '' ?><small><?= $e($option[$pickerType === 'product' ? 'sku' : 'family_code']) ?></small></span>
        </label>
    <?php endforeach ?>
    </div>
    <p class="help" data-picker-count></p><button type="button" class="quiet-button" data-picker-more hidden>Show more</button>
</details>
<p class="help" data-picker-selection></p>
