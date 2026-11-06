<?php
$_homesHeroHeading = (string) ($section['heading'] ?? 'Bring nature home.');
$_homesHeroBody = (string) ($section['body'] ?? 'Indoor plants, trees, pots and styling for calmer, healthier living. Curated for modern homes, with beauty that lasts.');
$_homesHeroMedia = $section['media_path'] ?? null;
$_homesHeroImage = in_array($_homesHeroMedia, ['/assets/images/botanical.svg'], true)
    || (is_string($_homesHeroMedia) && str_starts_with($_homesHeroMedia, '/assets/images/catalogue/placeholders/'))
    ? '/assets/vision/images/homes.jpg'
    : (verdant_view_asset_path($_homesHeroMedia) ?? '/assets/vision/images/homes.jpg');
$_homesHeroAlt = (string) ($section['media_alt'] ?? 'Botanical styling in a bright home');
$_homesHeroPath = verdant_view_local_path($section['link_path'] ?? '', '/collections/homes#homes-products');
?>
<section class="vision-homes-hero" aria-labelledby="homes-title">
    <div class="vision-homes-hero__copy">
        <p class="eyebrow">Plants for brighter homes</p>
        <h1 id="homes-title"><?= $e($_homesHeroHeading) ?></h1>
        <p><?= $e($_homesHeroBody) ?></p>
        <div class="vision-homes-hero__actions">
            <a class="btn-solid" href="<?= $e($_homesHeroPath) ?>"><?= $e($section['link_label'] ?? 'Shop home plants') ?> <span aria-hidden="true">→</span></a>
            <a class="btn-outline" href="/collections/homes#homes-arrangements">Explore arrangements</a>
        </div>
        <ul class="vision-homes-benefits" aria-label="Homes collection benefits">
            <li><span aria-hidden="true">♧</span> Locally sourced plants</li>
            <li><span aria-hidden="true">⌂</span> Nationwide delivery</li>
            <li><span aria-hidden="true">✳</span> Plant care support</li>
        </ul>
    </div>
    <div class="vision-homes-hero__media"><img src="<?= $e($_homesHeroImage) ?>" alt="<?= $e($_homesHeroAlt) ?>" width="960" height="720"></div>
</section>
