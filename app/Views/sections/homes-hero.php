<?php
$heroHref = verdant_view_local_path($section['link_path'] ?? '', '/catalogue');
?>
<section class="editorial-hero homes-hero" aria-labelledby="homes-title">
    <div class="editorial-hero__copy">
        <p class="eyebrow">The Homes collection</p>
        <h1 id="homes-title"><?= $e($section['heading'] ?? 'A greener feeling at home') ?></h1>
        <p><?= $e($section['body'] ?? 'Explore an inspiration edit of existing plants and configured arrangements for residential spaces.') ?></p>
        <?php if ($heroHref !== '' && !empty($section['link_label'])): ?><a class="button" href="<?= $e($heroHref) ?>"><?= $e($section['link_label']) ?></a><?php endif ?>
    </div>
    <?php if (!empty($section['media_path'])): ?>
        <div class="editorial-hero__media"><img src="<?= $e($image($section['media_path'])) ?>" alt="<?= $e($section['media_alt'] ?? '') ?>" width="960" height="720"></div>
    <?php else: ?>
        <div class="editorial-hero__placeholder" role="img" aria-label="Botanical illustration placeholder"><span>Verdant · a little more at home</span></div>
    <?php endif ?>
</section>
