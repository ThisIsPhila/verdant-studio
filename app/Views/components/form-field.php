<?php
/**
 * Labelled native field.
 * Input: $component = ['name','label','type','value','options'=>[['value','label']], 'help','error','required','id'].
 * Field names/values are escaped; callers must supply vetted option data and errors.
 */
$_viewFormField = $component ?? [];
$_viewFieldName = (string) ($_viewFormField['name'] ?? '');
$_viewFieldId = (string) ($_viewFormField['id'] ?? ('field-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $_viewFieldName)));
$_viewFieldType = in_array($_viewFormField['type'] ?? 'text', ['text', 'email', 'password', 'search', 'number', 'date', 'select', 'textarea'], true) ? ($_viewFormField['type'] ?? 'text') : 'text';
$_viewInputMode = in_array($_viewFormField['inputmode'] ?? '', ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], true) ? ($_viewFormField['inputmode'] ?? '') : '';
?>
<div class="form-field<?= !empty($_viewFormField['error']) ? ' form-field--error' : '' ?>">
    <label for="<?= $e($_viewFieldId) ?>"><?= $e($_viewFormField['label'] ?? $_viewFieldName) ?><?php if (!empty($_viewFormField['required'])): ?> <span aria-hidden="true">*</span><?php endif ?></label>
    <?php if ($_viewFieldType === 'select'): ?>
        <select id="<?= $e($_viewFieldId) ?>" name="<?= $e($_viewFieldName) ?>"<?php if (!empty($_viewFormField['required'])): ?> required<?php endif ?><?php if (!empty($_viewFormField['error'])): ?> aria-invalid="true" aria-describedby="<?= $e($_viewFieldId) ?>-error"<?php endif ?>>
            <?php foreach (($_viewFormField['options'] ?? []) as $_viewOption): ?><option value="<?= $e($_viewOption['value'] ?? '') ?>"<?= (string) ($_viewFormField['value'] ?? '') === (string) ($_viewOption['value'] ?? '') ? ' selected' : '' ?>><?= $e($_viewOption['label'] ?? '') ?></option><?php endforeach ?>
        </select>
    <?php elseif ($_viewFieldType === 'textarea'): ?>
        <textarea id="<?= $e($_viewFieldId) ?>" name="<?= $e($_viewFieldName) ?>"<?php if (!empty($_viewFormField['required'])): ?> required<?php endif ?><?php if (!empty($_viewFormField['error'])): ?> aria-invalid="true" aria-describedby="<?= $e($_viewFieldId) ?>-error"<?php endif ?>><?= $e($_viewFormField['value'] ?? '') ?></textarea>
    <?php else: ?>
        <input id="<?= $e($_viewFieldId) ?>" type="<?= $e($_viewFieldType) ?>" name="<?= $e($_viewFieldName) ?>" value="<?= $e($_viewFormField['value'] ?? '') ?>"<?php if ($_viewInputMode !== ''): ?> inputmode="<?= $e($_viewInputMode) ?>"<?php endif ?><?php if (!empty($_viewFormField['required'])): ?> required<?php endif ?><?php if (!empty($_viewFormField['error'])): ?> aria-invalid="true" aria-describedby="<?= $e($_viewFieldId) ?>-error"<?php endif ?>>
    <?php endif ?>
    <?php if (!empty($_viewFormField['help'])): ?><span class="field-help" id="<?= $e($_viewFieldId) ?>-help"><?= $e($_viewFormField['help']) ?></span><?php endif ?>
    <?php if (!empty($_viewFormField['error'])): ?><span class="field-error" id="<?= $e($_viewFieldId) ?>-error"><?= $e($_viewFormField['error']) ?></span><?php endif ?>
</div>
