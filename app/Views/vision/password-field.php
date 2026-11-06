<?php
$id = $passwordField['id'];
$help = $passwordField['help'] ?? '';
$error = $passwordField['error'] ?? null;
$errorId = $passwordField['error_id'] ?? $id . '-error';
$describedBy = trim(($help !== '' ? $id . '-help ' : '') . ($error !== null ? $errorId : ''));
$toggleLabel = $passwordField['toggle_label'] ?? strtolower($passwordField['label']);
?>
<div class="field">
    <label for="<?= $e($id) ?>"><?= $e($passwordField['label']) ?></label>
    <div class="vision-password-control">
        <input id="<?= $e($id) ?>" name="<?= $e($id) ?>" type="password" autocomplete="<?= $e($passwordField['autocomplete']) ?>" required<?= $error !== null ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . $e($describedBy) . '"' : '' ?>>
        <button type="button" class="vision-password-toggle" data-password-toggle data-password-label="<?= $e($toggleLabel) ?>" aria-controls="<?= $e($id) ?>" aria-label="Show <?= $e($toggleLabel) ?>" aria-pressed="false" hidden>
            <svg data-password-show width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg data-password-hide width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" hidden><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a19 19 0 0 1-3.2 4.1M6.1 6.1A20 20 0 0 0 2 12s3.5 7 10 7a11 11 0 0 0 5-1.3M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
    </div>
    <?php if ($help !== ''): ?><span class="help" id="<?= $e($id) ?>-help"><?= $e($help) ?></span><?php endif ?>
    <?php if ($error !== null): ?><span class="field-error" id="<?= $e($errorId) ?>"><?= $e($error) ?></span><?php endif ?>
</div>
