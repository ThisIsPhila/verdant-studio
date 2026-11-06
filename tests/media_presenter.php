<?php

declare(strict_types=1);

use Verdant\Media\MediaAssetRegistry;
use Verdant\Media\MediaBindingLookup;
use Verdant\Media\MediaPresenter;
use Verdant\Support\Format;

require dirname(__DIR__) . '/app/bootstrap.php';

$e = Format::escape(...);
$image = Format::imagePath(...);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};

$plantBinding = [
    'product_id' => 3008, 'media_kind' => 'plant', 'asset_id' => 'plant-monstera-small-v01',
    'visible_height_mm' => null, 'measurement_status' => 'unknown', 'archived_at' => null,
];
$stoneBinding = [
    'product_id' => 4001, 'media_kind' => 'pot', 'asset_id' => 'pot-stone-small-v01',
    'pot_height_mm' => null, 'measurement_status' => 'unknown', 'archived_at' => null,
];
$sandBinding = [
    'product_id' => 4027, 'media_kind' => 'pot', 'asset_id' => 'pot-sand-medium-v01',
    'pot_height_mm' => null, 'measurement_status' => 'unknown', 'archived_at' => null,
];
$bindings = [
    'product:3008:plant' => $plantBinding,
    'product:4001:pot' => $stoneBinding,
    'product:4027:pot' => $sandBinding,
    'scene:product-detail:background' => ['asset_id' => 'bg-individual-stone-v01', 'archived_at' => null],
    'scene:configuration:background' => ['asset_id' => 'bg-individual-entry-v01', 'archived_at' => null],
    'scene:standalone-pot:background' => ['asset_id' => 'bg-individual-stone-v01', 'camera_scale' => 1.05, 'archived_at' => null],
];
$lookup = new class($bindings) implements MediaBindingLookup {
    public function __construct(private array $bindings) {}
    public function bindingForTarget(string $targetKey, string $kind, bool $lock = false): ?array
    {
        return $this->bindings[$targetKey . ':' . $kind] ?? null;
    }
    public function replace(string $key, array $binding): void { $this->bindings[$key] = $binding; }
};
$registry = new MediaAssetRegistry(require dirname(__DIR__) . '/config/product-media.php');
$presenter = new MediaPresenter($lookup, $registry);
$plant = [
    'product_id' => 3008, 'product_kind' => 'plant', 'name' => 'Monstera',
    'image_path' => '/assets/images/botanical.svg', 'image_alt' => 'Monstera placeholder',
];
$compatibleStone = [
    'product_id' => 4001, 'name' => 'Stone planter', 'variant_label' => 'Small',
    'source_status' => 'demonstration', 'image_path' => '/assets/images/botanical.svg',
];
$detail = $presenter->productGallery($plant, [$compatibleStone]);
$assert($detail['mode'] === 'scene', 'Plant gallery uses only its registered product and server-listed compatible pot.');
$assert($detail['scene']['background']['id'] === 'bg-individual-stone-v01', 'Product detail receives its registered background scene.');
$assert($detail['scene']['plant']['id'] === 'plant-monstera-small-v01' && $detail['scene']['pot']['id'] === 'pot-stone-small-v01', 'Scene IDs resolve from actual product bindings.');
$assert($detail['scene_alt'] === 'Product view showing Monstera with Stone planter.', 'Scene accessible name describes the selected plant and pot rather than the fallback image.');
$assert($detail['scale_reference'] === null, 'Unmeasured assignments do not create a human-scale view.');
$assert(str_contains($detail['description'], 'priced separately') && !str_contains($detail['description'], 'Demonstration'), 'Customer copy explains pricing without internal provenance boilerplate.');
$assert(!isset($detail['scene']['plant']['provenance'], $detail['scene']['plant']['binding_id']), 'Public descriptors omit registry provenance and database binding metadata.');
$lookup->replace('scene:product-detail:background', ['asset_id' => 'bg-individual-homes-v01', 'archived_at' => null]);
$assert($presenter->productGallery($plant, [$compatibleStone])['scene']['background']['id'] === 'bg-individual-homes-v01', 'A current admin-selected registered scene background is used.');
$lookup->replace('scene:product-detail:background', ['asset_id' => 'bg-individual-stone-v01', 'archived_at' => null]);

$unlisted = ['product_id' => 4002, 'name' => 'Unlisted pot', 'image_path' => '/assets/images/botanical.svg'];
$assert($presenter->configurationGallery($plant, [$compatibleStone], 4002)['mode'] === 'fallback', 'Configuration cannot compose a pot absent from the server compatibility result.');
$configuration = $presenter->configurationGallery($plant, [$compatibleStone], '4001');
$assert($configuration['mode'] === 'scene' && $configuration['scene']['background']['id'] === 'bg-individual-entry-v01', 'Configuration uses its dedicated registered scene.');
$assert($presenter->configurationGallery($plant, [$compatibleStone], ['4001'])['mode'] === 'fallback', 'Malformed selected product IDs cannot select a media scene.');

$potProduct = [
    'product_id' => 4027, 'product_kind' => 'accessory', 'accessory_subtype' => 'other',
    'category_name' => 'Planters & pots', 'name' => 'Sand ribbed planter',
    'image_path' => '/assets/images/botanical.svg',
];
$standalonePot = $presenter->productGallery($potProduct);
$assert($standalonePot['mode'] === 'standalone-pot' && $standalonePot['scene']['pot']['crop'] === [561, 701, 561, 701], 'Planters & pots use the registered pot crop, not the source sheet.');
$assert($standalonePot['scene']['background']['id'] === 'bg-individual-stone-v01' && $standalonePot['scene']['camera_scale'] === 1.05, 'Standalone pots use their own neutral surface binding and camera setting.');
$assert(str_contains($standalonePot['description'], 'Measurements are not inferred'), 'Standalone pot framing makes no physical-size claim.');
$lookup->replace('scene:standalone-pot:background', ['asset_id' => 'bg-individual-homes-v01', 'camera_scale' => 1.05, 'archived_at' => null]);
$assert($presenter->productGallery($potProduct)['scene']['background'] === null,
    'A room-scale backdrop is ignored for standalone pots so the renderer uses its plain neutral surface.');
$lookup->replace('scene:standalone-pot:background', ['asset_id' => 'bg-individual-stone-v01', 'camera_scale' => 1.05, 'archived_at' => null]);
$tool = array_replace($potProduct, ['category_name' => 'Tools & care', 'accessory_subtype' => 'other', 'product_id' => 4028]);
$assert($presenter->productGallery($tool)['mode'] === 'fallback', 'Pot art is not applied to unrelated accessory categories.');
$flower = array_replace($plant, ['product_kind' => 'cut_flower', 'product_id' => 3017, 'name' => 'Lily stems']);
$assert($presenter->productGallery($flower, [$compatibleStone])['mode'] === 'fallback', 'A flower cannot be rendered as a plant/pot composition.');

$lookup->replace('product:3008:plant', [...$plantBinding, 'visible_height_mm' => 420, 'measurement_status' => 'measured']);
$lookup->replace('product:4001:pot', [...$stoneBinding, 'pot_height_mm' => 120, 'measurement_status' => 'measured']);
$measured = $presenter->productGallery($plant, [$compatibleStone]);
$assert($measured['scale_reference']['plant']['visible_height_mm'] === 420 && $measured['scale_reference']['pot']['pot_height_mm'] === 120, 'Confirmed dimensions are passed only as a paired measurement reference.');
$mediaGallery = $measured;
$product = $plant;
ob_start();
require dirname(__DIR__) . '/app/Views/components/product-gallery.php';
$measuredHtml = (string) ob_get_clean();
$assert(str_contains($measuredHtml, 'data-media-view="scale"') && str_contains($measuredHtml, 'Compare with a person'), 'Human-scale control is rendered only after both measurements are confirmed.');
$lookup->replace('product:3008:plant', $plantBinding);
$lookup->replace('product:4001:pot', $stoneBinding);

$lookup->replace('product:3008:plant', [...$plantBinding, 'archived_at' => '2026-10-14 12:00:00']);
$assert($presenter->productGallery($plant, [$compatibleStone])['mode'] === 'fallback', 'Archived plant media returns to the standard catalogue image.');
$lookup->replace('product:3008:plant', $plantBinding);
$lookup->replace('scene:product-detail:background', ['asset_id' => 'bg-individual-stone-v01', 'archived_at' => '2026-10-14 12:00:00']);
$assert($presenter->productGallery($plant, [$compatibleStone])['mode'] === 'fallback', 'Archived scene media returns to the standard catalogue image.');

$mediaGallery = $detail;
$product = $plant;
ob_start();
require dirname(__DIR__) . '/app/Views/components/product-gallery.php';
$html = (string) ob_get_clean();
$assert(str_contains($html, 'data-media-main') && str_contains($html, 'data-media-fallback'), 'Scene gallery includes an ordinary-image no-JavaScript fallback.');
$assert(str_contains($html, 'aria-live="polite"') && str_contains($html, 'aria-label="Product images"'), 'Gallery includes accessible status and section labels.');
$assert(!str_contains($html, 'data-media-view="scale"'), 'Unmeasured gallery does not offer an unqualified scale reference.');
$assert(str_contains($html, 'plant-monstera-small-v01') && !str_contains($html, 'binding_id'), 'JSON gallery data carries registered public descriptors only.');

$lookup->replace('scene:product-detail:background', ['asset_id' => 'bg-individual-stone-v01', 'archived_at' => null]);
$assert($presenter->productThumbnail($plant, [$compatibleStone])['mode'] === 'scene', 'Cards reuse the eligible product scene.');
$potCard = ['product_id' => 4001, 'product_kind' => 'accessory', 'accessory_subtype' => 'pot', 'name' => 'Stone'];
$assert($presenter->productThumbnail($potCard)['mode'] === 'pot', 'Pot thumbnails use the processed registered layer, not the full sheet.');
$ownedPlant = array_replace($plant, ['image_path' => '/assets/images/owner.png']);
$assert($presenter->productThumbnail($ownedPlant, [$compatibleStone])['mode'] === 'fallback', 'An explicit owner image is preserved.');
$lookup->replace('product:3008:plant', [...$plantBinding, 'archived_at' => '2026-10-15']);
$assert($presenter->productThumbnail($plant, [$compatibleStone])['mode'] === 'fallback', 'Archived plant assignment cannot reappear on cards.');
$flower = ['product_id' => 9001, 'product_kind' => 'cut_flower', 'name' => 'Rose stems'];
$assert($presenter->productThumbnail($flower)['mode'] === 'fallback', 'Unmapped stems never receive unapproved reference artwork.');

 echo "Media presenter checks: $checks passed, 0 failed.\n";
