<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$registry = require $root . '/config/product-media.php';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$assets = array_merge($registry['environments'], $registry['plants'], $registry['pots']);
$byId = [];
foreach ($assets as $asset) {
    $assert(!isset($byId[$asset['id']]), 'Duplicate asset ID: ' . $asset['id']);
    $byId[$asset['id']] = $asset;
    $assert(str_starts_with($asset['path'], '/assets/images/product-media/'), 'Asset path outside media allowlist: ' . $asset['id']);
    $diskPath = $root . '/public' . $asset['path'];
    $assert(is_file($diskPath), 'Missing local media file: ' . $asset['id']);
    if (!is_file($diskPath)) continue;
    $dimensions = getimagesize($diskPath);
    $assert($dimensions !== false, 'Unreadable image: ' . $asset['id']);
    if ($dimensions === false) continue;
    $crop = $asset['crop'];
    $assert(count($crop) === 4 && $crop[0] >= 0 && $crop[1] >= 0 && $crop[2] > 0 && $crop[3] > 0, 'Invalid crop: ' . $asset['id']);
    $assert($crop[0] + $crop[2] <= $dimensions[0] && $crop[1] + $crop[3] <= $dimensions[1], 'Crop exceeds native source bounds: ' . $asset['id']);
    $assert($dimensions[0] === $asset['source_width'] && $dimensions[1] === $asset['source_height'], 'Native dimensions do not match registry: ' . $asset['id']);
    $assert(isset($asset['version'], $asset['provenance']), 'Missing version/provenance: ' . $asset['id']);
    if (isset($asset['root'])) $assert($asset['root'][0] >= 0 && $asset['root'][0] <= $crop[2] && $asset['root'][1] >= 0 && $asset['root'][1] <= $crop[3], 'Plant insertion point outside crop: ' . $asset['id']);
    if (isset($asset['anchor'])) $assert($asset['anchor'][0] >= 0 && $asset['anchor'][0] <= $crop[2] && $asset['anchor'][1] >= 0 && $asset['anchor'][1] < $asset['anchor'][2] && $asset['anchor'][2] <= $crop[3], 'Pot soil/ground anchor outside crop: ' . $asset['id']);
}
$seedRows = require $root . '/database/seeds/catalogue.php';
$seedSkus = array_column($seedRows, 0);
foreach ($registry['sku_matches'] as $match) {
    $assert(in_array($match['sku'], $seedSkus, true), 'SKU is absent from starter catalogue seed: ' . $match['sku']);
    $assert(isset($byId[$match['asset_id']]), 'SKU match references unknown asset: ' . $match['asset_id']);
    $assert(isset($match['match']) && $match['match'] !== '', 'SKU match has no rationale: ' . $match['sku']);
}
if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo count($assets) . ' registered media assets and ' . count($registry['sku_matches']) . " starter SKU references verified.\n";
