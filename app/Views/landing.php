<?php
$landingVision = true;
$visionServicesVisible = false;
foreach ($pageSections as $section) {
    if ($section['section_type'] === 'service_band') {
        $visionServicesVisible = true;
        require __DIR__ . '/vision/service-band.php';
        continue;
    }
    // An allowlisted renderer map keeps stored section values from selecting files.
    $sectionTemplate = match ($section['section_type']) {
        'hero' => 'hero', 'collection' => 'spaces', 'arrangement_grid' => 'arrangements',
        'product_grid' => 'products', 'configuration_entry' => 'configuration-entry',
        'rental_collection' => 'rental-collection', default => null,
    };
    if ($sectionTemplate !== null) {
        require __DIR__ . '/vision/' . $sectionTemplate . '.php';
    } elseif ($section['section_type'] === 'editorial') {
        ?>
        <section class="mx-auto max-w-7xl px-6 py-12">
            <h2 class="text-3xl"><?= $e($section['heading']) ?></h2>
            <p class="mt-4 text-sm text-muted-foreground"><?= $e($section['body']) ?></p>
        </section>
        <?php
    }
}
