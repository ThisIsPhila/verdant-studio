<?php
$prefix = 'components[' . $index . ']';
$source = $component['source_type'] ?? 'product';
$isNew = empty($component['component_id']);
?>
<fieldset class="recipe-item" data-recipe-item>
    <legend><?= $e($component['label'] ?: 'New item') ?></legend>
    <input type="hidden" name="<?= $e($prefix) ?>[component_id]" value="<?= $e($component['component_id'] ?? '') ?>">
    <div class="admin-grid">
        <label>Item name <input data-item-label name="<?= $e($prefix) ?>[label]" maxlength="120" required value="<?= $e($component['label'] ?? '') ?>"></label>
        <label>Item type <select name="<?= $e($prefix) ?>[component_role]"><?php foreach (['plant','pot','flower','foliage','container','other'] as $role): ?><option value="<?= $e($role) ?>" <?= ($component['component_role'] ?? '') === $role ? 'selected' : '' ?>><?= $e($label($role)) ?></option><?php endforeach ?></select></label>
        <label>Items come from <select data-item-source name="<?= $e($prefix) ?>[source_type]"><option value="product" <?= $source === 'product' ? 'selected' : '' ?>>Named products</option><option value="family" <?= $source === 'family' ? 'selected' : '' ?>>Product family and variants</option><option value="compatible_pot" <?= $source === 'compatible_pot' ? 'selected' : '' ?>>Pots compatible with a plant</option></select></label>
        <label>Available to <select name="<?= $e($prefix) ?>[permitted_mode]"><?php foreach (['sale' => 'Buy', 'rent' => 'Rent', 'either' => 'Buy or rent'] as $mode => $caption): ?><option value="<?= $e($mode) ?>" <?= ($component['permitted_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= $e($caption) ?></option><?php endforeach ?></select></label>
    </div>
    <div data-item-source-panel="product"><?php $pickerType = 'product'; $pickerOptions = $products; require __DIR__ . '/composition-picker.php'; ?></div>
    <div data-item-source-panel="family"><?php $pickerType = 'family'; $pickerOptions = $families; require __DIR__ . '/composition-picker.php'; ?></div>
    <label data-item-source-panel="compatible_pot">Match pots to this plant item <select name="<?= $e($prefix) ?>[anchor_key]"><option value="">Choose a plant item</option><?php foreach ($rows as $candidate): if (($candidate['component_role'] ?? '') !== 'plant' || ($candidate['component_key'] ?? '') === ($component['component_key'] ?? '')) continue; ?><option value="<?= $e($candidate['component_key']) ?>" <?= ($component['anchor_key'] ?? '') === $candidate['component_key'] || (int) ($component['compatibility_anchor_component_id'] ?? 0) === (int) ($candidate['component_id'] ?? -1) ? 'selected' : '' ?>><?= $e($candidate['label'] ?: $candidate['component_key']) ?></option><?php endforeach ?></select></label>
    <div class="admin-grid">
        <label>Minimum quantity <input type="number" min="1" max="10000" name="<?= $e($prefix) ?>[min_quantity]" value="<?= $e($component['min_quantity'] ?? 1) ?>" required></label>
        <label>Maximum quantity <input type="number" min="1" max="10000" name="<?= $e($prefix) ?>[max_quantity]" value="<?= $e($component['max_quantity'] ?? 1) ?>" required></label>
    </div>
    <p class="help">Use the same quantity twice for a fixed amount. A range lets the customer choose.</p>
    <label><input type="checkbox" name="<?= $e($prefix) ?>[required]" value="1" <?= $component['required'] ? 'checked' : '' ?>> Required in the arrangement</label>
    <label><input type="checkbox" name="<?= $e($prefix) ?>[archived]" value="1" <?= $component['archived'] ? 'checked' : '' ?>> Archive this item when saving</label>
    <details class="recipe-advanced"><summary>Advanced settings · Choice limits and reference</summary>
        <div class="admin-grid">
            <label>Minimum product selections <input type="number" min="1" max="30" name="<?= $e($prefix) ?>[min_selections]" value="<?= $e($component['min_selections'] ?? 1) ?>" required></label>
            <label>Maximum product selections <input type="number" min="1" max="30" name="<?= $e($prefix) ?>[max_selections]" value="<?= $e($component['max_selections'] ?? 1) ?>" required></label>
            <label>Display order <input type="number" min="0" max="65535" name="<?= $e($prefix) ?>[sort_order]" value="<?= $e($component['sort_order'] ?? 0) ?>" required></label>
            <label>Item reference <input name="<?= $e($prefix) ?>[component_key]" maxlength="64" pattern="[a-z][a-z0-9_-]*" readonly value="<?= $e($component['component_key']) ?>" required data-item-key></label>
        </div>
        <p class="help">References preserve existing recipe links. Compatible pots must match a saved plant item; save a newly added plant before linking its pots.</p>
    </details>
    <?php if ($isNew): ?><button type="button" class="quiet-button" data-item-remove hidden>Remove new item</button><?php endif ?>
</fieldset>
