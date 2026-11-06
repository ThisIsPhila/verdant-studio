<?php
$pickerOptions = is_array($pickerOptions ?? null) ? $pickerOptions : [];
$pickerId = is_string($pickerId ?? null) ? $pickerId : 'asset-choice';
$pickerName = is_string($pickerName ?? null) ? $pickerName : 'asset_id';
$pickerLabel = is_string($pickerLabel ?? null) ? $pickerLabel : 'Approved image';
$pickerValue = is_string($pickerValue ?? null) ? $pickerValue : '';
$pickerEmptyLabel = is_string($pickerEmptyLabel ?? null) ? $pickerEmptyLabel : 'Choose an approved image';
$pickerKindControl = is_string($pickerKindControl ?? null) ? $pickerKindControl : '';
$pickerContextControl = is_string($pickerContextControl ?? null) ? $pickerContextControl : '';
$pickerStandaloneAsset = is_string($pickerStandaloneAsset ?? null) ? $pickerStandaloneAsset : '';
$pickerRequired = !empty($pickerRequired);
$pickerOptionsJson = json_encode($pickerOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="field admin-asset-picker" data-admin-asset-picker<?= $pickerKindControl !== '' ? ' data-kind-control="' . $e($pickerKindControl) . '"' : '' ?><?= $pickerContextControl !== '' ? ' data-context-control="' . $e($pickerContextControl) . '"' : '' ?><?= $pickerStandaloneAsset !== '' ? ' data-standalone-asset="' . $e($pickerStandaloneAsset) . '"' : '' ?>>
    <label for="<?= $e($pickerId) ?>"><?= $e($pickerLabel) ?></label>
    <select id="<?= $e($pickerId) ?>" name="<?= $e($pickerName) ?>" data-picker-source <?= $pickerRequired ? 'required' : '' ?>>
        <option value="" <?= $pickerValue === '' ? 'selected' : '' ?>><?= $e($pickerEmptyLabel) ?></option>
        <?php if ($pickerValue !== '' && !in_array($pickerValue, array_map(static fn (array $option): string => (string) ($option['id'] ?? ''), $pickerOptions), true)): ?><option value="<?= $e($pickerValue) ?>" selected>Submitted value (not approved)</option><?php endif ?>
        <?php foreach ($pickerOptions as $option): if (!is_array($option) || !is_string($option['id'] ?? null)) continue; ?>
            <option value="<?= $e($option['id']) ?>" <?= $pickerValue === $option['id'] ? 'selected' : '' ?>><?= $e($option['name'] ?? $option['id']) ?><?= isset($option['profile']) ? ' · ' . $e(ucfirst((string) $option['profile'])) : '' ?></option>
        <?php endforeach ?>
    </select>
    <div class="admin-asset-picker__actions" data-picker-enhanced hidden>
        <button type="button" class="quiet-button" data-picker-open aria-haspopup="dialog" aria-controls="<?= $e($pickerId) ?>-dialog">Choose image</button>
        <div class="admin-asset-picker__current" data-picker-current aria-live="polite"></div>
    </div>
    <dialog id="<?= $e($pickerId) ?>-dialog" data-picker-dialog aria-labelledby="<?= $e($pickerId) ?>-dialog-title">
        <div class="admin-asset-picker__dialog-header"><div><h2 id="<?= $e($pickerId) ?>-dialog-title">Choose <?= $e(strtolower($pickerLabel)) ?></h2><p>Only approved local images are available.</p></div><button type="button" class="quiet-button" data-picker-close>Close</button></div>
        <label class="admin-asset-picker__search" for="<?= $e($pickerId) ?>-search">Search images</label>
        <input id="<?= $e($pickerId) ?>-search" type="search" data-picker-search autocomplete="off">
        <div class="admin-asset-picker__choices" role="radiogroup" aria-label="Approved image choices" data-picker-choices></div>
        <div class="admin-asset-picker__paging"><button type="button" class="quiet-button" data-picker-previous>Previous</button><span data-picker-page aria-live="polite"></span><button type="button" class="quiet-button" data-picker-next>Next</button></div>
    </dialog>
    <script type="application/json" data-picker-assets><?= $pickerOptionsJson ?></script>
</div>
