<?php

declare(strict_types=1);

namespace Verdant\Media;

use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

final class MediaAssignmentService
{
    public function __construct(
        private readonly MediaAssignmentRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly MediaAssetRegistry $assets,
    ) {
    }

    public function saveProductAssignment(
        int $productId,
        string $kind,
        string $assetId,
        ?int $visibleHeightMm,
        ?int $potHeightMm,
        string $measurementStatus,
        ?int $expectedVersion,
        int $actorId,
    ): array {
        $this->requireAdmin($actorId);
        return $this->transactions->run(function () use (
            $productId, $kind, $assetId, $visibleHeightMm, $potHeightMm, $measurementStatus, $expectedVersion, $actorId,
        ): array {
            $product = $this->repository->product($productId, true);
            $this->validateProduct($product, $kind);
            $data = $this->validateAssignment($productId, 'product:' . $productId, $kind, $assetId, $visibleHeightMm, $potHeightMm, $measurementStatus);
            $current = $this->repository->bindingForTarget($data['target_key'], $kind, true);
            if ($current === null) {
                if ($expectedVersion !== null) {
                    throw new ValidationException(['version' => 'This media assignment no longer exists. Reload the product.']);
                }
                $bindingId = $this->repository->insertBinding($data, $actorId);
                $saved = $this->repository->binding($bindingId, true);
                $this->repository->addHistory($bindingId, $actorId, 'create', null, $saved);
                return $saved;
            }
            if ($expectedVersion === null || (int) $current['lock_version'] !== $expectedVersion || $current['archived_at'] !== null) {
                throw new ValidationException(['version' => 'This media assignment changed or is archived. Reload it before editing.']);
            }
            if (!$this->repository->updateBinding((int) $current['binding_id'], $expectedVersion, $data, $actorId)) {
                throw new ValidationException(['version' => 'This media assignment changed. Reload it before editing.']);
            }
            $saved = $this->repository->binding((int) $current['binding_id'], true);
            $this->repository->addHistory((int) $saved['binding_id'], $actorId, 'update', $current, $saved);
            return $saved;
        });
    }

    public function saveSceneBackground(string $scene, string $assetId, ?int $expectedVersion, int $actorId, float $cameraScale = 1.0): array
    {
        $this->requireAdmin($actorId);
        $scenes = $this->assets->backgroundScenes();
        if (!array_key_exists($scene, $scenes)) {
            throw new ValidationException(['scene' => 'Choose a registered storefront scene.']);
        }
        if ($scene === 'standalone-pot' && $assetId !== $scenes['standalone-pot']) {
            throw new ValidationException(['asset_id' => 'Choose the approved neutral standalone-pot surface.']);
        }
        if (!is_finite($cameraScale) || $cameraScale < 0.85 || $cameraScale > 1.15
            || ($scene !== 'standalone-pot' && $cameraScale !== 1.0)) {
            throw new ValidationException(['camera_scale' => 'Use the supported standalone-pot framing between 0.85 and 1.15.']);
        }
        return $this->transactions->run(function () use ($scene, $assetId, $cameraScale, $expectedVersion, $actorId): array {
            $data = $this->validateAssignment(null, 'scene:' . $scene, 'background', $assetId, null, null, 'unknown', $cameraScale);
            $current = $this->repository->bindingForTarget($data['target_key'], 'background', true);
            if ($current === null) {
                if ($expectedVersion !== null) throw new ValidationException(['version' => 'This scene assignment no longer exists.']);
                $bindingId = $this->repository->insertBinding($data, $actorId);
                $saved = $this->repository->binding($bindingId, true);
                $this->repository->addHistory($bindingId, $actorId, 'create', null, $saved);
                return $saved;
            }
            if ($expectedVersion === null || (int) $current['lock_version'] !== $expectedVersion || $current['archived_at'] !== null) {
                throw new ValidationException(['version' => 'This scene assignment changed or is archived. Reload it before editing.']);
            }
            if (!$this->repository->updateBinding((int) $current['binding_id'], $expectedVersion, $data, $actorId)) {
                throw new ValidationException(['version' => 'This scene assignment changed. Reload it before editing.']);
            }
            $saved = $this->repository->binding((int) $current['binding_id'], true);
            $this->repository->addHistory((int) $saved['binding_id'], $actorId, 'update', $current, $saved);
            return $saved;
        });
    }

    public function archiveBinding(int $bindingId, int $expectedVersion, int $actorId): void
    {
        $this->changeArchiveState($bindingId, $expectedVersion, $actorId, true);
    }

    public function restoreBinding(int $bindingId, int $expectedVersion, int $actorId): array
    {
        $this->requireAdmin($actorId);
        return $this->transactions->run(function () use ($bindingId, $expectedVersion, $actorId): array {
            $current = $this->currentForArchive($bindingId, $expectedVersion, true);
            if ($current['product_id'] !== null) {
                $product = $this->repository->product((int) $current['product_id'], true);
                $this->validateProduct($product, $current['media_kind']);
            }
            $this->validateAssetKind($current['media_kind'], $current['asset_id']);
            if (!$this->repository->changeArchive($bindingId, $expectedVersion, false, $actorId)) {
                throw new ValidationException(['version' => 'This media assignment changed. Reload it before restoring.']);
            }
            $saved = $this->repository->binding($bindingId, true);
            $this->repository->addHistory($bindingId, $actorId, 'restore', $current, $saved);
            return $saved;
        });
    }

    /** Insert starter media only where no owner-created, active, or archived row exists. */
    public function seedDefaults(): array
    {
        $counts = ['inserted' => 0, 'existing' => 0, 'skipped' => []];
        foreach ($this->assets->skuMatches() as $match) {
            $asset = $this->assets->asset($match['asset_id']);
            if ($asset === null) {
                $counts['skipped'][] = $match['sku'] . ': asset is not registered';
                continue;
            }
            $product = $this->repository->productBySku($match['sku']);
            if ($product === null || $product['archived_at'] !== null) {
                $counts['skipped'][] = $match['sku'] . ': product is absent or archived';
                continue;
            }
            try {
                $this->validateProduct($product, $asset['kind']);
            } catch (ValidationException) {
                $counts['skipped'][] = $match['sku'] . ': catalogue type/category does not qualify for ' . $asset['kind'] . ' media';
                continue;
            }
            $result = $this->seedOne(
                (int) $product['product_id'], 'product:' . (int) $product['product_id'], $asset['kind'], $asset['id'],
            );
            ++$counts[$result];
        }
        foreach ($this->assets->backgroundScenes() as $scene => $assetId) {
            $result = $this->seedOne(null, 'scene:' . $scene, 'background', $assetId);
            ++$counts[$result];
        }
        return $counts;
    }

    private function seedOne(?int $productId, string $target, string $kind, string $assetId): string
    {
        return $this->transactions->run(function () use ($productId, $target, $kind, $assetId): string {
            if ($productId !== null) {
                $this->validateProduct($this->repository->product($productId, true), $kind);
            }
            if ($this->repository->bindingForTarget($target, $kind, true) !== null) return 'existing';
            $data = $this->validateAssignment($productId, $target, $kind, $assetId, null, null, 'unknown');
            $bindingId = $this->repository->insertBinding($data, null);
            $saved = $this->repository->binding($bindingId, true);
            $this->repository->addHistory($bindingId, null, 'seed', null, $saved);
            return 'inserted';
        });
    }

    private function changeArchiveState(int $bindingId, int $expectedVersion, int $actorId, bool $archived): void
    {
        $this->requireAdmin($actorId);
        $this->transactions->run(function () use ($bindingId, $expectedVersion, $actorId, $archived): void {
            $current = $this->currentForArchive($bindingId, $expectedVersion, false);
            if (!$this->repository->changeArchive($bindingId, $expectedVersion, $archived, $actorId)) {
                throw new ValidationException(['version' => 'This media assignment changed. Reload it before archiving.']);
            }
            $saved = $this->repository->binding($bindingId, true);
            $this->repository->addHistory($bindingId, $actorId, 'archive', $current, $saved);
        });
    }

    private function currentForArchive(int $bindingId, int $expectedVersion, bool $restore): array
    {
        $current = $this->repository->binding($bindingId, true);
        $wantedArchive = $restore;
        if ($current === null || (int) $current['lock_version'] !== $expectedVersion
            || (($current['archived_at'] !== null) !== $wantedArchive)) {
            throw new ValidationException(['version' => 'This media assignment changed. Reload it before continuing.']);
        }
        return $current;
    }

    private function validateAssignment(
        ?int $productId, string $target, string $kind, string $assetId,
        ?int $visibleHeightMm, ?int $potHeightMm, string $measurementStatus, float $cameraScale = 1.0,
    ): array {
        $this->validateAssetKind($kind, $assetId);
        if (!in_array($measurementStatus, ['unknown', 'illustrative', 'measured'], true)) {
            throw new ValidationException(['measurement_status' => 'Choose a supported measurement status.']);
        }
        foreach (['visible_height_mm' => $visibleHeightMm, 'pot_height_mm' => $potHeightMm] as $field => $value) {
            if ($value !== null && ($value < 1 || $value > 5000)) {
                throw new ValidationException([$field => 'Use a positive measurement up to 5000 mm.']);
            }
        }
        if ($measurementStatus === 'unknown' && ($visibleHeightMm !== null || $potHeightMm !== null)) {
            throw new ValidationException(['measurement_status' => 'Unknown dimensions must not be stored as measurements.']);
        }
        if (($kind === 'plant' && $potHeightMm !== null)
            || ($kind === 'pot' && $visibleHeightMm !== null)
            || ($kind === 'background' && ($visibleHeightMm !== null || $potHeightMm !== null))) {
            throw new ValidationException(['geometry' => 'Measurements must match the selected media kind.']);
        }
        if ($measurementStatus === 'measured'
            && !(($kind === 'plant' && $visibleHeightMm !== null)
                || ($kind === 'pot' && $potHeightMm !== null)
                || $kind === 'background')) {
            throw new ValidationException(['measurement_status' => 'A measured plant or pot needs its matching physical dimension.']);
        }
        if ($kind === 'background' && $measurementStatus !== 'unknown') {
            throw new ValidationException(['measurement_status' => 'Scene backgrounds do not carry product measurements.']);
        }
        return [
            'product_id' => $productId,
            'target_key' => $target,
            'media_kind' => $kind,
            'asset_id' => $assetId,
            'visible_height_mm' => $visibleHeightMm,
            'pot_height_mm' => $potHeightMm,
            'measurement_status' => $measurementStatus,
            'camera_scale' => $cameraScale,
        ];
    }

    private function validateAssetKind(string $kind, string $assetId): void
    {
        $asset = $this->assets->asset($assetId);
        if ($asset === null || $asset['kind'] !== $kind) {
            throw new ValidationException(['asset_id' => 'Choose a registered asset of the selected media kind.']);
        }
    }

    private function validateProduct(?array $product, string $kind): void
    {
        if ($product === null || $product['archived_at'] !== null) {
            throw new ValidationException(['product_id' => 'Choose an existing active product.']);
        }
        // Category membership permits a standalone pot product image; it does not create plant compatibility.
        $potProduct = $product['product_kind'] === 'accessory'
            && ($product['accessory_subtype'] === 'pot' || $product['category_name'] === 'Planters & pots');
        $valid = ($kind === 'plant' && $product['product_kind'] === 'plant')
            || ($kind === 'pot' && $potProduct);
        if (!$valid) {
            throw new ValidationException(['media_kind' => 'The product type is not eligible for this media kind.']);
        }
    }

    private function requireAdmin(int $actorId): void
    {
        if ($actorId < 1 || !$this->repository->isActiveAdministrator($actorId)) {
            throw new ValidationException(['actor' => 'An active administrator is required to change media assignments.']);
        }
    }
}
