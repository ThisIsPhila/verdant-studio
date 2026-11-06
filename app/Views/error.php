<?php
$internalError = ($layout ?? 'customer') === 'internal';
if (!$internalError) { $visionPage = true; $visionComponentStyles = ['customer-records']; }
?>
<section class="<?= $internalError ? 'admin-panel' : 'empty-state vision-error-page' ?>" aria-labelledby="error-page-title">
    <p class="eyebrow">Verdant Studio</p>
    <h1 id="error-page-title"><?= $e($title) ?></h1>
    <p><?= $e($message) ?></p>
    <div class="vision-error-actions"><a class="button button--primary" href="<?= $internalError ? '/admin' : '/catalogue' ?>"><?= $internalError ? 'Return to administration' : 'Browse the collection' ?></a></div>
</section>
