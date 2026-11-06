<?php
/** Input: $component = ['steps'=>[['label','description']], 'current'=>1-based step number]. */
$_viewConfiguration = $component ?? [];
$_viewCurrentStep = (int) ($_viewConfiguration['current'] ?? 0);
?>
<ol class="configuration-steps" aria-label="Configuration progress">
    <?php foreach (($_viewConfiguration['steps'] ?? []) as $_viewStepIndex => $_viewStep): $_viewStepNumber = $_viewStepIndex + 1; ?>
        <li<?= $_viewStepNumber === $_viewCurrentStep ? ' aria-current="step"' : '' ?> class="<?= $_viewStepNumber < $_viewCurrentStep ? 'is-complete' : ($_viewStepNumber === $_viewCurrentStep ? 'is-current' : '') ?>">
            <span class="configuration-steps__number" aria-hidden="true"><?= $_viewStepNumber < $_viewCurrentStep ? '✓' : $_viewStepNumber ?></span>
            <span><strong><?= $e($_viewStep['label'] ?? '') ?></strong><?php if (!empty($_viewStep['description'])): ?><small><?= $e($_viewStep['description']) ?></small><?php endif ?></span>
        </li>
    <?php endforeach ?>
</ol>
