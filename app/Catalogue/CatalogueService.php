<?php

declare(strict_types=1);

namespace Verdant\Catalogue;

use Verdant\Support\ValidationException;

final class CatalogueService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ?ProductFamilyService $families = null,
        private readonly ?CollectionService $discovery = null,
    ) {
    }

    public function catalogue(array $query): array
    {
        if ($this->discovery !== null) {
            $normalized = CollectionService::normalizeFilters($query);
            $page = $query['page'] ?? '1';
            if (!is_string($page) || !ctype_digit($page) || strlen($page) > 4 || (int) $page < 1) {
                throw new ValidationException(['page' => 'Choose a valid page.']);
            }
            $page = (int) $page;
            $products = $this->discovery->products($normalized, null, 25, ($page - 1) * 24);
            $hasNext = count($products) > 24;
            if ($hasNext) {
                array_pop($products);
            }
            $filters = $normalized;
            unset($filters['budget_minor']);
            return [
                'products' => $products, 'categories' => $this->products->categories(),
                'options' => $this->discovery->filterOptions(), 'filters' => $filters,
                'page' => $page, 'hasNext' => $hasNext, 'total' => $this->discovery->count($normalized, null),
            ];
        }

        $filters = [];
        foreach (['category_id', 'light_level', 'watering_level', 'toxicity'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 40) {
                throw new ValidationException([$key => 'Choose a valid filter.']);
            }
            if ($key === 'category_id' && $value !== '') {
                if (!ctype_digit($value) || strlen($value) > 18 || (int) $value < 1) {
                    throw new ValidationException([$key => 'Choose a valid category.']);
                }
            }
            $filters[$key] = $value;
        }
        $page = $query['page'] ?? '1';
        if (!is_string($page) || !ctype_digit($page) || strlen($page) > 4 || (int) $page < 1) {
            throw new ValidationException(['page' => 'Choose a valid page.']);
        }
        $page = (int) $page;
        $products = $this->products->browse($filters, 25, ($page - 1) * 24);
        $hasNext = count($products) > 24;
        if ($hasNext) {
            array_pop($products);
        }
        return [
            'products' => $products, 'categories' => $this->products->categories(),
            'options' => $this->products->filterOptions(), 'filters' => $filters,
            'page' => $page, 'hasNext' => $hasNext, 'total' => $this->products->count($filters),
        ];
    }

    public function product(int $id): ?array
    {
        if ($id < 1 || ($product = $this->products->findActive($id)) === null) {
            return null;
        }
        $selection = $this->families?->selectionFor($id, $product)
            ?? ['family' => null, 'variants' => [], 'compatible_pots' => []];
        return $product + $selection;
    }
}
