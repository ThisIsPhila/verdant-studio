<?php
if (($layoutName ?? 'customer') === 'internal') {
    require __DIR__ . '/admin/layout.php';
    return;
}
$visionSkin = !empty($landingVision) || !empty($visionPage);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Verdant') ?> · Verdant Studio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php if ($visionSkin): ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500&family=Jost:wght@300;400;500&family=Mrs+Saint+Delafield&display=swap">
    <?php else: ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600&family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&display=swap">
    <?php endif ?>
    <?php
    $stylesheets = [
        'app',
        'pages',
        'components/site-header',
        'components/hero',
        'components/cards',
        'components/sections',
        'components/configuration-entry',
        'components/rental-collection',
        'components/service-band',
        'components/site-footer',
        'media-renderer', 'support',
    ];
    if ($visionSkin) {
        $stylesheets = ['vision/global', 'vision/components', 'vision/pages', 'media-renderer', 'support'];
        foreach ($visionComponentStyles ?? [] as $componentStyle) {
            if (in_array($componentStyle, ['product-detail', 'discovery', 'checkout', 'customer-records', 'arrangements', 'information', 'information-help'], true)) {
                $stylesheets[] = 'vision/' . $componentStyle;
            }
        }
    }
    ?>
    $stylesheets[] = 'components/store-notice'; ?>
    <?php foreach ($stylesheets as $stylesheet): ?>
        <link rel="stylesheet" href="/assets/css/<?= $e($stylesheet) ?>.css?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/css/' . $stylesheet . '.css')) ?>">
    <?php endforeach ?>
</head>
<body class="layout-<?= $e($layoutName ?? 'customer') ?><?= $visionSkin ? ' vision-store' . (!empty($landingVision) ? ' landing-vision' : '') : '' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if (($layoutName ?? 'customer') === 'internal'): ?>
    <?php require __DIR__ . '/components/site-header.php'; ?>
    <?php require __DIR__ . '/admin/nav.php'; ?>
<?php else: ?>
    <?php require __DIR__ . ($visionSkin ? '/vision/header.php' : '/components/site-header.php'); ?>
<?php endif ?>
<?php require __DIR__ . '/components/store-notice.php'; ?>
<?php if (in_array($template, ['checkout', 'rental-checkout', 'composition-checkout', 'composition-pending', 'payment-result', 'rental-payment-result', 'composition-payment-result'], true)): ?>
<p class="store-payment-notice" role="note">Payment testing only: Verdant is a fictional store. Use the payment provider’s test options; no real purchase or rental will be fulfilled.</p>
<?php endif ?>
<main id="main" tabindex="-1"><?= $content ?></main>
<?php if ($visionSkin && empty($landingVision)): require __DIR__ . '/vision/service-band.php'; endif ?>
<?php require __DIR__ . ($visionSkin ? '/vision/footer.php' : '/components/site-footer.php'); ?>
<?php if (!empty($mediaCardsUsed)): ?>
<script src="/assets/js/media/renderer.js" defer></script>
<script src="/assets/js/media/thumbnails.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/media/thumbnails.js')) ?>" defer></script>
<?php endif ?>
<?php if (!empty($landingGalleryUsed)): ?>
<script src="/assets/js/media/gallery.js" defer></script>
<script src="/assets/js/landing-pair.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/landing-pair.js')) ?>" defer></script>
<?php endif ?>
<script src="/assets/js/site-navigation.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/site-navigation.js')) ?>" defer></script>
<script src="/assets/js/storefront-controls.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/storefront-controls.js')) ?>" defer></script>
<?php if (isset($pageKey) && in_array('information', $visionComponentStyles ?? [], true)): ?>
<script src="/assets/js/information-pages.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/information-pages.js')) ?>" defer></script>
<?php endif ?>
<?php if ($visionSkin): ?><script src="/assets/js/vision-landing.js" defer></script><?php endif ?>
</body>
</html>
