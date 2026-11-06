<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$label = static fn (string $value): string => \Verdant\Support\Format::label($value);
$products = [[
    'product_id' => 41, 'sku' => 'PLANT-41', 'name' => 'Monstera', 'product_kind' => 'plant',
    'variant_label' => 'Large', 'size_label' => 'Large', 'colour_label' => null,
]];
$assets = [
    ['id' => 'plant-41', 'kind' => 'plant', 'name' => 'Monstera cutout', 'path' => '/assets/images/plant-41.webp',
        'source_width' => 1200, 'source_height' => 1600, 'profile' => 'Large plant', 'provenance' => 'Owner supplied', 'crop' => [0, 0, 1, 1]],
    ['id' => 'pot-41', 'kind' => 'pot', 'name' => 'Stone pot', 'path' => '/assets/images/pot-41.webp',
        'source_width' => 1200, 'source_height' => 1200, 'profile' => 'Large pot', 'provenance' => 'Registered local asset', 'crop' => [0.1, 0.1, 0.8, 0.8]],
    ['id' => 'bg-individual-stone-v01', 'kind' => 'background', 'name' => 'Stone product surface', 'path' => '/assets/images/bg-41.webp',
        'source_width' => 2400, 'source_height' => 1400, 'profile' => 'Scene', 'provenance' => 'Owner supplied', 'crop' => [0, 0, 1, 1]],
];
$bindings = [[
    'binding_id' => 8, 'product_id' => 41, 'target_key' => 'product:41', 'media_kind' => 'plant', 'asset_id' => 'plant-41',
    'visible_height_mm' => null, 'pot_height_mm' => null, 'measurement_status' => 'unknown', 'lock_version' => 2,
    'updated_at' => '2026-10-14 10:00:00', 'archived_at' => null, 'sku' => 'PLANT-41', 'product_name' => 'Monstera',
    'product_kind' => 'plant', 'product_archived_at' => null, 'category_archived_at' => null,
]];
$scenes = ['homes' => 'bg-individual-stone-v01', 'standalone-pot' => 'bg-individual-stone-v01'];
$values = ['target_type' => 'product', 'product_id' => '41', 'media_kind' => 'plant', 'asset_id' => 'plant-41',
    'measurement_status' => 'unknown', 'visible_height_mm' => '', 'pot_height_mm' => ''];
$errors = [];
$selected = null;
$selectedId = null;
$history = [];
$saved = null;
$render = static function () use (&$products, &$assets, &$bindings, &$scenes, &$values, &$errors, &$selected, &$selectedId, &$history, &$saved, $e, $label): string {
    ob_start();
    require dirname(__DIR__) . '/app/Views/admin/media.php';
    return (string) ob_get_clean();
};

$body = $render();
$check(str_contains($body, 'Assign media to a product') && str_contains($body, 'Choose an active plant or pot'),
    'New assignment form presents product targets.');
$check(str_contains($body, 'Registered local assets') && str_contains($body, 'Owner supplied')
    && str_contains($body, 'Source image and crop details') && str_contains($body, 'data-asset-crop-preview'),
    'Registry cards show the processed crop first and retain the source sheet only as expandable details.');
$check(str_contains($body, 'data-crop-fallback') && str_contains($body, 'loading="lazy" hidden'),
    'Library source sheets stay hidden in the crop preview when enhancement or asset loading fails.');
$check(str_contains($body, 'data-admin-asset-picker') && str_contains($body, 'data-picker-dialog')
    && str_contains($body, 'data-picker-search') && str_contains($body, 'name="asset_id"'),
    'Media assignment exposes the accessible visual picker and a native-select fallback.');
$check(str_contains($body, 'name="asset_id"') && !str_contains($body, 'type="file"')
    && !str_contains($body, 'name="image_url"'), 'Asset assignment is registry-only, with no upload or remote URL control.');
$check(str_contains($body, 'Dimension confidence') && str_contains($body, 'Unknown — no physical measurement')
    && str_contains($body, 'Measured — owner verified'), 'Measurement confidence is explicit.');
$check(str_contains($body, 'name="csrf"') && str_contains($body, '/admin/media/archive'),
    'Lifecycle forms carry CSRF tokens and reversible archive actions.');

$selected = ['binding_id' => 8, 'product_id' => 41, 'target_key' => 'product:41', 'media_kind' => 'plant',
    'asset_id' => 'plant-41', 'visible_height_mm' => null, 'pot_height_mm' => null, 'measurement_status' => 'unknown',
    'lock_version' => 2, 'updated_at' => '2026-10-14 10:00:00', 'archived_at' => null];
$selectedId = 8;
$values['expected_version'] = '2';
$values['asset_id'] = 'unregistered-asset';
$errors = ['asset_id' => 'Choose a registered asset.', 'version' => 'This assignment changed.'];
$html = $render();
$check(str_contains($html, 'Product assignment details') && str_contains($html, 'value="2"')
    && str_contains($html, 'value="41"') && str_contains($html, 'Choose a registered asset.'),
    'Edit validation retains target, version and failed asset selection.');
$check(str_contains($html, 'Assignment history') && str_contains($html, 'aria-current="page"'),
    'Selected assignment offers its audit-history region and current row marker.');
$check(str_contains($html, 'Stone pot') && str_contains($html, 'data-picker-choices')
    && str_contains($html, 'Submitted value (not approved)'),
    'Validation retains unsupported submitted picker values without treating them as approved assets.');

$selected['archived_at'] = '2026-10-14 11:00:00';
$bindings[0]['archived_at'] = '2026-10-14 11:00:00';
$html = $render();
//
$check(str_contains($html, 'Archived assignment') && !str_contains($html, '>Save media assignment</button>')
    && str_contains($html, 'Restore'), 'Archived assignments cannot be edited and expose restore.');

$values['target_type'] = 'scene';
$values['scene_key'] = 'homes';
$selected = null;
$selectedId = null;
$errors = [];
$html = $render();
$check(str_contains($html, 'Storefront presentation') && str_contains($html, 'Registered background asset'),
    'Scene assignment is a distinct registered-background form.');
$values['scene_key'] = 'standalone-pot';
$html = $render();
$check(str_contains($html, 'Standalone pot on a stone surface') && str_contains($html, 'camera_scale')
    && str_contains($html, 'data-standalone-pot-canvas') && str_contains($html, 'does not change stored planter dimensions'),
    'Standalone-pot context offers a separate bounded framing control and neutral-surface preview.');

echo "Admin media presentation checks: {$checks} passed, 0 failed.\n";
