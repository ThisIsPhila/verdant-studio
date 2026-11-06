<section class="vision-homes-arrangements vision-discovery-section" aria-labelledby="collection-arrangements-title">
    <div class="vision-discovery-heading"><div><p class="eyebrow">Linked arrangements</p><h2 id="collection-arrangements-title">Arrangements in <?= $e($collection['name']) ?>.</h2><p>These active definitions are linked to this collection. Current component eligibility and pricing are checked when you configure.</p></div></div>
    <?php if (!empty($compositions)): ?>
        <div class="vision-homes-arrangement-grid">
            <?php foreach ($compositions as $composition): ?>
                <?php
                $component = $composition;
                $arrangementImage = $collectionImage;
                $arrangementImageAlt = (string) ($collection['name'] ?? 'Botanical collection');
                require __DIR__ . '/homes-arrangement-card.php';
                ?>
            <?php endforeach ?>
        </div>
    <?php else: ?>
        <div class="vision-empty-editorial"><h3>No arrangements are currently linked to this collection.</h3><p>Browse products currently included in the collection.</p></div>
    <?php endif ?>
</section>
