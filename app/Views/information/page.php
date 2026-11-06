<?php
// Shared escaped rendering helpers and page-specific content prepared by the controller.
require __DIR__ . '/helpers.php';
?>
<div class="<?= $esc(implode(' ', $rootClasses)) ?>"<?= in_array($pageKey, ['plant-care', 'journal', 'returns'], true) ? ' data-info-interactions' : '' ?>>
    <?php if ($preview): ?>
        <aside class="info-preview-notice" role="status">
            <strong>Protected preview</strong>
            <span>This review copy is visible to an authorised editor. Public pages use the published snapshot.</span>
        </aside>
    <?php endif ?>

<?php
require __DIR__ . '/hero.php';
require __DIR__ . '/learning.php';
require __DIR__ . '/contact.php';
require __DIR__ . '/delivery.php';
require __DIR__ . '/returns.php';
require __DIR__ . '/policy.php';
require __DIR__ . '/article.php';
require __DIR__ . '/editorial.php';
?>
</div>
