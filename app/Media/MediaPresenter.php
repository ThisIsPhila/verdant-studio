<?php

declare(strict_types=1);

namespace Verdant\Media;

use Verdant\Storefront\VisionLandingPresenter;
use Verdant\Support\Format;

final class MediaPresenter
{
    public function __construct(
        private readonly MediaBindingLookup $bindings,
        private readonly MediaAssetRegistry $assets,
    ) {
    }

    public function productGallery(array $product, array $compatiblePots = [], bool $allowPlantOnly = false): array
    {
        if (($product['product_kind'] ?? '') === 'plant') {
            foreach ($compatiblePots as $pot) {
                if (!is_array($pot) || !$this->isAvailable($pot)) continue;
                $scene = $this->scene($product, $pot, 'product-detail');
                if ($scene !== null) return $this->scenePresentation($product, $pot, $scene);
            }
            if ($allowPlantOnly) {
                $binding = $this->activeBinding($product, 'plant');
                $asset = $binding === null ? null : $this->registeredAsset($binding, 'plant');
                $backgroundBinding = $this->bindings->bindingForTarget('scene:product-detail', 'background');
                $background = $backgroundBinding !== null && ($backgroundBinding['archived_at'] ?? null) === null
                    ? $this->registeredAsset($backgroundBinding, 'background') : null;
                if ($asset !== null && $background !== null) {
                    $photo = VisionLandingPresenter::image(['name' => $product['name'] ?? '']);
                    return ['mode' => 'scene', 'scene' => ['background' => $this->descriptor($background), 'plant' => $this->descriptor($asset), 'pot' => null],
                        'scene_alt' => $this->name($product), 'description' => '',
                        'image' => ['src' => $photo ?? Format::imagePath($product['image_path'] ?? null), 'alt' => $this->name($product)], 'scale_reference' => null];
                }
            }
            return $this->fallback($product);
        }

        if (($product['product_kind'] ?? '') === 'accessory'
            && (($product['accessory_subtype'] ?? '') === 'pot' || ($product['category_name'] ?? '') === 'Planters & pots')) {
            $binding = $this->activeBinding($product, 'pot');
            $asset = $binding === null ? null : $this->registeredAsset($binding, 'pot');
            if ($asset !== null) {
                $backgroundBinding = $this->bindings->bindingForTarget('scene:standalone-pot', 'background');
                $background = $backgroundBinding !== null && ($backgroundBinding['archived_at'] ?? null) === null
                    && ($backgroundBinding['asset_id'] ?? null) === ($this->assets->backgroundScenes()['standalone-pot'] ?? null)
                    ? $this->registeredAsset($backgroundBinding, 'background') : null;
                return [
                    'mode' => 'standalone-pot',
                    'scene' => ['background' => $background === null ? null : $this->descriptor($background),
                        'pot' => $this->descriptor($asset), 'camera_scale' => $this->standaloneCameraScale($backgroundBinding)],
                    'image' => ['src' => '/assets/images/catalogue/placeholders/accessory.svg', 'alt' => 'Product image of ' . $this->name($product)],
                    'scene_alt' => 'Standalone product view of ' . $this->name($product) . ' on a neutral surface.',
                    'description' => 'Standalone planter presentation. Measurements are not inferred from this image.',
                    'scale_reference' => null,
                ];
            }
        }

        return $this->fallback($product);
    }

    /** Public card descriptor; reuse active assignments, never infer inventory fit. */
    public function productThumbnail(array $product, array $compatiblePots = []): array
    {
        $imagePath = $product['image_path'] ?? '';
        if (is_string($imagePath) && $imagePath !== '' && !str_contains($imagePath, '/placeholders/') && $imagePath !== '/assets/images/botanical.svg') return $this->fallback($product);
        $gallery = $this->productGallery($product, $compatiblePots);
        if (($gallery['mode'] ?? '') === 'scene') return $gallery;
        $binding = $this->activeBinding($product, 'pot');
        $asset = $binding === null ? null : $this->registeredAsset($binding, 'pot');
        if ($asset !== null) {
            $standaloneBinding = $this->bindings->bindingForTarget('scene:standalone-pot', 'background');
            $background = $standaloneBinding !== null && ($standaloneBinding['archived_at'] ?? null) === null
                && ($standaloneBinding['asset_id'] ?? null) === ($this->assets->backgroundScenes()['standalone-pot'] ?? null)
                ? $this->registeredAsset($standaloneBinding, 'background') : null;
            return ['mode' => 'pot',
            'scene' => ['pot' => $this->descriptor($asset), 'background' => $background === null ? null : $this->descriptor($background),
                'camera_scale' => $this->standaloneCameraScale($standaloneBinding)],
            'scene_alt' => 'Product view of ' . $this->name($product)];
        }
        return $gallery;
    }

    public function sizeLabel(array $product): string
    {
        foreach (['variant_label', 'size_label'] as $key) {
            $value = strtolower(trim((string) ($product[$key] ?? '')));
            if (in_array($value, ['small', 'medium', 'large'], true)) return ucfirst($value);
        }
        $binding = $this->activeBinding($product, 'plant');
        $asset = $binding === null ? null : $this->registeredAsset($binding, 'plant');
        return isset($asset['profile']) ? ucfirst($asset['profile']) : 'Size unavailable';
    }

    /** Build a composition only for a pot present in the server's eligible compatibility result. */
    public function configurationGallery(array $plant, array $compatiblePots, mixed $selectedPotId): array
    {
        if (($plant['product_kind'] ?? '') !== 'plant' || !$this->isAvailable($plant)
            || (!(is_int($selectedPotId) && $selectedPotId > 0)
                && !(is_string($selectedPotId) && ctype_digit($selectedPotId) && (int) $selectedPotId > 0))) {
            return $this->fallback($plant);
        }
        $pot = null;
        foreach ($compatiblePots as $candidate) {
            if (is_array($candidate) && $this->isAvailable($candidate) && (int) ($candidate['product_id'] ?? 0) === (int) $selectedPotId) {
                $pot = $candidate;
                break;
            }
        }
        if ($pot === null) return $this->fallback($plant);
        $scene = $this->scene($plant, $pot, 'configuration');
        return $scene === null ? $this->fallback($plant) : $this->scenePresentation($plant, $pot, $scene);
    }

    private function scene(array $plant, array $pot, string $sceneName): ?array
    {
        if (!$this->isAvailable($plant) || ($plant['product_kind'] ?? '') !== 'plant' || !$this->isAvailable($pot)) return null;
        $plantBinding = $this->activeBinding($plant, 'plant');
        $potBinding = $this->activeBinding($pot, 'pot');
        $knownScenes = $this->assets->backgroundScenes();
        $backgroundBinding = array_key_exists($sceneName, $knownScenes)
            ? $this->bindings->bindingForTarget('scene:' . $sceneName, 'background')
            : null;
        if ($plantBinding === null || $potBinding === null || $backgroundBinding === null
            || $backgroundBinding['archived_at'] !== null) {
            return null;
        }

        $plantAsset = $this->registeredAsset($plantBinding, 'plant');
        $potAsset = $this->registeredAsset($potBinding, 'pot');
        $backgroundAsset = $this->registeredAsset($backgroundBinding, 'background');
        if ($plantAsset === null || $potAsset === null || $backgroundAsset === null) return null;

        return [
            'background' => $this->descriptor($backgroundAsset),
            'plant' => $this->descriptor($plantAsset),
            'pot' => $this->descriptor($potAsset),
        ];
    }

    private function scenePresentation(array $plant, array $pot, array $scene): array
    {
        $plantBinding = $this->activeBinding($plant, 'plant');
        $potBinding = $this->activeBinding($pot, 'pot');
        $measurements = null;
        if ($plantBinding !== null && $potBinding !== null
            && $plantBinding['measurement_status'] === 'measured'
            && $potBinding['measurement_status'] === 'measured'
            && (int) ($plantBinding['visible_height_mm'] ?? 0) > 0
            && (int) ($potBinding['pot_height_mm'] ?? 0) > 0) {
            $measurements = [
                'plant' => [
                    'measurement_status' => 'measured',
                    'visible_height_mm' => (int) $plantBinding['visible_height_mm'],
                ],
                'pot' => [
                    'measurement_status' => 'measured',
                    'pot_height_mm' => (int) $potBinding['pot_height_mm'],
                ],
            ];
        }
        $description = 'Plants and pots are shown together for styling and are priced separately.';
        return [
            'mode' => 'scene',
            'scene' => $scene,
            'image' => $this->fallback($plant)['image'],
            'scene_alt' => 'Product view showing ' . $this->name($plant) . ' with ' . $this->name($pot) . '.',
            'description' => $description,
            'scale_reference' => $measurements,
        ];
    }

    private function activeBinding(array $product, string $kind): ?array
    {
        $productId = filter_var($product['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) return null;
        $binding = $this->bindings->bindingForTarget('product:' . $productId, $kind);
        return $binding !== null && ($binding['archived_at'] ?? null) === null ? $binding : null;
    }

    private function registeredAsset(array $binding, string $expectedKind): ?array
    {
        $assetId = $binding['asset_id'] ?? null;
        if (!is_string($assetId)) return null;
        $asset = $this->assets->asset($assetId);
        return $asset !== null && ($asset['kind'] ?? null) === $expectedKind ? $asset : null;
    }

    private function descriptor(array $asset): array
    {
        $fields = ['id', 'kind', 'path', 'crop', 'source_width', 'source_height', 'root', 'profile', 'canvas_offset', 'anchor'];
        return array_intersect_key($asset, array_fill_keys($fields, true));
    }

    private function standaloneCameraScale(?array $binding): float
    {
        $scale = (float) ($binding['camera_scale'] ?? 1.0);
        return is_finite($scale) && $scale >= 0.85 && $scale <= 1.15 ? $scale : 1.0;
    }

    private function isAvailable(array $product): bool
    {
        return ($product['archived_at'] ?? null) === null && (int) ($product['product_id'] ?? 0) > 0;
    }

    private function fallback(array $product): array
    {
        $photo = VisionLandingPresenter::image(['name' => $product['name'] ?? '']);
        return [
            'mode' => 'fallback',
            'image' => [
                'src' => $photo ?? Format::imagePath(is_string($product['image_path'] ?? null) ? $product['image_path'] : null),
                'alt' => $photo !== null
                    ? $this->name($product)
                    : (is_string($product['image_alt'] ?? null) && $product['image_alt'] !== ''
                        ? $product['image_alt'] : 'Botanical product placeholder'),
            ],
            'description' => $photo !== null ? '' : 'Showing the standard catalogue image.',
            'scale_reference' => null,
        ];
    }

    private function name(array $product): string
    {
        return is_string($product['name'] ?? null) && $product['name'] !== '' ? $product['name'] : 'planter';
    }
}
