<?php
$heroMedia = $heroSection['media_path'] ?? null;
$heroMedia = is_string($heroMedia) ? verdant_view_asset_path($heroMedia) : null;
if ($heroMedia === null || str_contains($heroMedia, '/placeholders/')) {
    $heroMedia = '/assets/vision/images/r-cluster.jpg';
}
$heroLink = !empty($heroSection['link_path'])
    ? verdant_view_local_path($heroSection['link_path'], '/configure') : '/configure';
?>
<section class="page-hero arrangement-shop-hero relative overflow-hidden bg-surface" aria-labelledby="arrangements-title">
    <img src="<?= $e($heroMedia) ?>" alt="" width="1920" height="640" class="absolute inset-0 h-full w-full object-cover object-left">
    <div class="page-hero__veil" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-7xl px-6 py-16 md:py-20"><div class="max-w-xl">
        <p class="eyebrow">Curated arrangements</p>
        <h1 id="arrangements-title" class="mt-3 text-5xl leading-[0.95] md:text-7xl"><?= $e($heroSection['heading'] ?? 'A complete look. Naturally yours.') ?></h1>
        <p class="mt-4 text-lg text-foreground/80"><?= $e($heroSection['body'] ?? 'Thoughtful combinations of plants and pots, ready to bring your space together.') ?></p>
        <a class="button button--primary" href="<?= $e($heroLink) ?>"><?= $e($heroSection['link_label'] ?? 'Create your own') ?> →</a>
    </div></div>
</section>
