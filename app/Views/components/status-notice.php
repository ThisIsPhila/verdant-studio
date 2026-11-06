<?php
/** Input: $component = ['title'=>string|null, 'message'=>string, 'tone'=>info|success|warning|error]. */
$_viewNotice = $component ?? [];
$_viewNoticeTone = in_array($_viewNotice['tone'] ?? 'info', ['info', 'success', 'warning', 'error'], true) ? ($_viewNotice['tone'] ?? 'info') : 'info';
?>
<aside class="status-notice status-notice--<?= $e($_viewNoticeTone) ?>" role="<?= $_viewNoticeTone === 'error' || $_viewNoticeTone === 'warning' ? 'alert' : 'status' ?>">
    <?php if (!empty($_viewNotice['title'])): ?><h2><?= $e($_viewNotice['title']) ?></h2><?php endif ?>
    <p><?= $e($_viewNotice['message'] ?? '') ?></p>
</aside>
