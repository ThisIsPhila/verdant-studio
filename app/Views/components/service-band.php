<?php
require_once __DIR__ . '/_helpers.php';
$serviceItems = ($component ?? [])['items'] ?? [];
$serviceIcons = ['truck', 'consult', 'bag', 'leaf', 'pot'];
?>
<section class="service-band" aria-label="Ways to shop">
    <?php foreach ($serviceItems as $serviceIndex => $service): ?>
        <div class="service-item">
            <?= verdant_view_icon($serviceIcons[$serviceIndex] ?? 'leaf') ?>
            <div>
                <span><?= $e($service['label'] ?? '') ?></span>
                <p><?= $e($service['text'] ?? '') ?></p>
            </div>
        </div>
    <?php endforeach ?>
</section>
