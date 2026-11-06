<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

final class VariantAdminService
{
    private const PRODUCT_KINDS = ['plant', 'cut_flower', 'cut_foliage', 'accessory', 'plant_arrangement', 'floral_arrangement', 'other'];
    private const COMPATIBILITY_SOURCES = ['demonstration', 'owner_verified'];

    public function __construct(
        private readonly VariantAdminRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AdminAccess $access,
    ) {
    }

    public function families(array $actor): array
    {
        $this->access->require($actor);
        return $this->repository->families();
    }

    public function activeFamilies(array $actor): array
    {
        $this->access->require($actor);
        return array_values(array_filter(
            $this->repository->families(),
            static fn (array $family): bool => $family['archived_at'] === null,
        ));
    }

    public function familiesForProduct(array $actor, ?int $currentFamilyId = null): array
    {
        $this->access->require($actor);
        $families = $this->repository->families();
        return array_values(array_filter(
            $families,
            static fn (array $family): bool => $family['archived_at'] === null
                || ($currentFamilyId !== null && (int) $family['family_id'] === $currentFamilyId),
        ));
    }

    public function family(array $actor, mixed $id): ?array
    {
        $this->access->require($actor);
        return $this->repository->family($this->positiveId($id, 'family_id'));
    }

    public function saveFamily(array $actor, mixed $id, array $input): int
    {
        $familyId = $id === null || $id === '' ? null : $this->positiveId($id, 'family_id');
        $name = $this->text($input['name'] ?? null, 'name', 180);
        $kind = $input['product_kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::PRODUCT_KINDS, true)) {
            throw new ValidationException(['product_kind' => 'Choose a listed product kind.']);
        }
        $code = null;
        if ($familyId === null) {
            $code = $input['family_code'] ?? null;
            if (!is_string($code) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) !== 1) {
                throw new ValidationException(['family_code' => 'Use lowercase letters, numbers and hyphens for the family code.']);
            }
        }

        $this->access->require($actor);
        return $this->transactions->run(function () use ($actor, $familyId, $code, $name, $kind): int {
            $this->access->require($actor, true);
            if ($familyId === null) {
                if ($this->repository->familyByCode((string) $code, true) !== null) {
                    throw new ValidationException(['family_code' => 'That family code is already in use.']);
                }
                return $this->repository->insertFamily((string) $code, $name, $kind);
            }
            $family = $this->repository->family($familyId, true);
            if ($family === null) {
                throw new ValidationException(['family_id' => 'This product family is unavailable.']);
            }
            if ($family['archived_at'] !== null) {
                throw new ValidationException(['family_id' => 'Restore this family before editing it.']);
            }
            if ($kind !== $family['product_kind'] && $this->repository->hasMembers($familyId)) {
                throw new ValidationException(['product_kind' => 'A family with assigned SKUs cannot change product kind.']);
            }
            $this->repository->updateFamily($familyId, $name, $kind);
            return $familyId;
        });
    }

    public function setFamilyArchived(array $actor, mixed $id, bool $archived): void
    {
        $familyId = $this->positiveId($id, 'family_id');
        $this->access->require($actor);
        $this->transactions->run(function () use ($actor, $familyId, $archived): void {
            $this->access->require($actor, true);
            $family = $this->repository->family($familyId, true);
            if ($family === null) {
                throw new ValidationException(['family_id' => 'This product family is unavailable.']);
            }
            if ($archived && $this->repository->hasActiveMembers($familyId)) {
                throw new ValidationException(['family_id' => 'Remove or archive active variant SKUs before archiving this family.']);
            }
            $this->repository->setFamilyArchived($familyId, $archived);
        });
    }

    public function compatibilityPage(array $actor): array
    {
        $this->access->require($actor);
        return [
            'compatibilities' => $this->repository->compatibilities(),
            'plants' => $this->repository->eligiblePlants(),
            'pots' => $this->repository->eligiblePots(),
        ];
    }

    public function saveCompatibility(array $actor, mixed $plantId, mixed $potId, mixed $source): int
    {
        $plantProductId = $this->positiveId($plantId, 'plant_product_id');
        $potProductId = $this->positiveId($potId, 'pot_product_id');
        if ($plantProductId === $potProductId) {
            throw new ValidationException(['pot_product_id' => 'Choose a different product for the pot.']);
        }
        if (!is_string($source) || !in_array($source, self::COMPATIBILITY_SOURCES, true)) {
            throw new ValidationException(['source_status' => 'Choose a listed compatibility source.']);
        }
        $this->access->require($actor);
        return $this->transactions->run(function () use ($actor, $plantProductId, $potProductId, $source): int {
            $this->access->require($actor, true);
            $products = $this->repository->compatibilityProducts([$plantProductId, $potProductId], true);
            $byId = [];
            foreach ($products as $product) {
                $byId[(int) $product['product_id']] = $product;
            }
            if (count($byId) !== 2 || !$this->isActive($byId[$plantProductId] ?? null)
                || ($byId[$plantProductId]['product_kind'] ?? null) !== 'plant') {
                throw new ValidationException(['plant_product_id' => 'Choose an active plant SKU.']);
            }
            $pot = $byId[$potProductId] ?? null;
            if (!$this->isActive($pot) || $pot['product_kind'] !== 'accessory' || $pot['accessory_subtype'] !== 'pot'
                || ((int) $pot['buy_enabled'] !== 1 && (int) $pot['rent_enabled'] !== 1)) {
                throw new ValidationException(['pot_product_id' => 'Choose an active product explicitly classified as a buyable or rentable pot.']);
            }

            $existing = $this->repository->compatibilityForPair($plantProductId, $potProductId, true);
            if ($existing !== null) {
                if ($existing['archived_at'] !== null) {
                    throw new ValidationException(['pot_product_id' => 'This compatibility is archived; restore it from the list.']);
                }
                $this->repository->updateCompatibilitySource((int) $existing['compatibility_id'], $source);
                return (int) $existing['compatibility_id'];
            }
            return $this->repository->insertCompatibility($plantProductId, $potProductId, $source);
        });
    }

    public function setCompatibilityArchived(array $actor, mixed $id, bool $archived): void
    {
        $compatibilityId = $this->positiveId($id, 'compatibility_id');
        $this->access->require($actor);
        $this->transactions->run(function () use ($actor, $compatibilityId, $archived): void {
            $this->access->require($actor, true);
            $reference = $this->repository->compatibility($compatibilityId);
            if ($reference === null) {
                throw new ValidationException(['compatibility_id' => 'This compatibility record is unavailable.']);
            }
            $products = $this->repository->compatibilityProducts(
                [(int) $reference['plant_product_id'], (int) $reference['pot_product_id']], true,
            );
            $byId = [];
            foreach ($products as $product) {
                $byId[(int) $product['product_id']] = $product;
            }
            $record = $this->repository->compatibility($compatibilityId, true);
            if ($record === null) {
                throw new ValidationException(['compatibility_id' => 'This compatibility record is unavailable.']);
            }
            if (!$archived && (!$this->isActive($byId[(int) $record['plant_product_id']] ?? null)
                || ($byId[(int) $record['plant_product_id']]['product_kind'] ?? null) !== 'plant'
                || !$this->isActive($byId[(int) $record['pot_product_id']] ?? null)
                || ($byId[(int) $record['pot_product_id']]['product_kind'] ?? null) !== 'accessory'
                || ($byId[(int) $record['pot_product_id']]['accessory_subtype'] ?? null) !== 'pot')) {
                throw new ValidationException(['compatibility_id' => 'Restore both active SKUs and verify their plant/pot types before restoring this pairing.']);
            }
            $this->repository->setCompatibilityArchived($compatibilityId, $archived);
        });
    }

    private function isActive(?array $product): bool
    {
        return $product !== null && $product['archived_at'] === null && $product['category_archived_at'] === null;
    }

    private function text(mixed $value, string $field, int $maximum): string
    {
        if (!is_string($value)) {
            throw new ValidationException([$field => 'Enter valid text.']);
        }
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($value === '' || $length === false || $length > $maximum) {
            throw new ValidationException([$field => 'Enter a value within the allowed length.']);
        }
        return $value;
    }

    private function positiveId(mixed $value, string $field): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1
            || (float) $value > PHP_INT_MAX) {
            throw new ValidationException([$field => 'Choose a valid record.']);
        }
        return (int) $value;
    }
}
