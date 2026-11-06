<?php
$homesArrangementHeading = $section['heading'] ?? 'Complete plant styling for every home.';
$homesArrangementBody = $section['body'] ?? 'Explore active arrangements linked to the Homes collection. Choose current components and check availability in configuration.';
?>
<section class="vision-homes-arrangements vision-discovery-section" id="homes-arrangements" aria-labelledby="homes-arrangements-title">
    <div class="vision-discovery-heading">
        <div><p class="eyebrow">Curated arrangements</p><h2 id="homes-arrangements-title"><?= $e($homesArrangementHeading) ?></h2><p><?= $e($homesArrangementBody) ?></p></div>
        <a class="vision-discovery-reset" href="/arrangements?space=homes">View arrangements <span aria-hidden="true">→</span></a>
    </div>
    <?php if (!empty($compositions)): ?>
        <div class="vision-homes-arrangement-grid">
            <?php foreach ($compositions as $composition): ?>
                <?php $component = $composition; require __DIR__ . '/homes-arrangement-card.php'; ?>
            <?php endforeach ?>
        </div>
    <?php else: ?>
        <div class="vision-empty-editorial"><h3>No arrangements are currently linked to Homes.</h3><p>Browse individual collection products or build a plant and pot pairing.</p></div>
    <?php endif ?>
</section>
