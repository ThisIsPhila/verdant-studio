<?php

declare(strict_types=1);

namespace Verdant\Catalogue;

final class ProductFamilyService
{
    public function __construct(private readonly ProductFamilyRepository $families)
    {
    }

    /** @return array{family:?array, variants:list<array>, compatible_pots:list<array>} */
    public function selectionFor(int $productId, array $product): array
    {
        if ($productId < 1) {
            return ['family' => null, 'variants' => [], 'compatible_pots' => []];
        }

        $family = $this->families->familyForActiveProduct($productId);
        return [
            'family' => $family,
            'variants' => $family === null ? [] : $this->families->variants((int) $family['family_id']),
            'compatible_pots' => ($product['product_kind'] ?? null) === 'plant'
                ? $this->families->compatiblePots($productId) : [],
        ];
    }
}
