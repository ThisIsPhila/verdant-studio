<?php
/**
 * Progressive filter form.
 * Input: $component = ['action'=>local path, 'legend'=>string, 'fields'=>component arrays,
 * 'active'=>[['label'=>string,'remove_href'=>local path]], 'submit_label'=>string].
 * Native details begins closed; callers own query fields and preserve state in field values.
 */
require_once __DIR__ . '/_helpers.php';
$_viewFilterPanel = $component ?? [];
$_viewFilterAction = verdant_view_local_path($_viewFilterPanel['action'] ?? '/catalogue', '/catalogue');
$_viewOriginalComponent = $component ?? null;
?>
<div class="filter-panel">
    <?php if (!empty($_viewFilterPanel['active'])): ?>
        <ul class="active-filters" aria-label="Active filters">
            <?php foreach ($_viewFilterPanel['active'] as $_viewFilterChip): ?>
                <?php $_viewRemoveHref = isset($_viewFilterChip['remove_href']) ? verdant_view_local_path($_viewFilterChip['remove_href'], '') : ''; ?>
                <li><span><?= $e($_viewFilterChip['label'] ?? '') ?></span><?php if ($_viewRemoveHref !== ''): ?><a href="<?= $e($_viewRemoveHref) ?>" aria-label="Remove <?= $e($_viewFilterChip['label'] ?? 'filter') ?>">×</a><?php endif ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
    <details class="filter-disclosure">
        <summary><?= $e($_viewFilterPanel['legend'] ?? 'Filter & sort') ?></summary>
        <form action="<?= $e($_viewFilterAction) ?>" method="get" class="filter-form">
            <div class="filter-fields">
                <?php foreach (($_viewFilterPanel['fields'] ?? []) as $_viewFieldComponent): ?>
                    <?php $component = $_viewFieldComponent; ?>
                    <?php require __DIR__ . '/form-field.php'; ?>
                <?php endforeach ?>
            </div>
            <button class="button button--primary" type="submit"><?= $e($_viewFilterPanel['submit_label'] ?? 'Apply filters') ?></button>
        </form>
    </details>
</div>
<?php $component = $_viewOriginalComponent; ?>
