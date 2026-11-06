<?php $sectionHref = verdant_view_local_path($section['link_path'] ?? '', '/catalogue'); ?>
<section class="editorial-section homes-product-introduction" aria-labelledby="homes-products-introduction-title">
    <div class="section-heading"><div><p class="eyebrow">Individual pieces</p><h2 id="homes-products-introduction-title"><?= $e($section['heading'] ?? 'Plants for home') ?></h2><p><?= $e($section['body'] ?? 'Browse individual products in the Homes edit.') ?></p></div>
        <?php if ($sectionHref !== '' && !empty($section['link_label'])): ?><a class="text-link" href="<?= $e($sectionHref) ?>"><?= $e($section['link_label']) ?> <span aria-hidden="true">→</span></a><?php endif ?>
    </div>
</section>
