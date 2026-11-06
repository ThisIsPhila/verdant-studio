<?php

declare(strict_types=1);

namespace Verdant\Catalogue;

use Verdant\Support\ValidationException;

/** Normalizes discovery inputs for both a named collection and all catalogue products. */
final class CollectionService
{
    private const KINDS = ['plant', 'cut_flower', 'cut_foliage', 'accessory', 'plant_arrangement', 'floral_arrangement', 'other'];
    private const CARE = ['light_level', 'watering_level', 'toxicity'];

    /** Customer size taxonomy with placement guidance; keys match stored size labels. */
    public const SIZE_FILTER_LABELS = [
        'Small' => 'Small · tabletop & desktop',
        'Medium' => 'Medium · floor or supported',
        'Large' => 'Large · floor',
    ];

    public function __construct(private readonly CollectionRepository $collections)
    {
    }

    /** @return array<string,string|int|null> */
    public static function normalizeFilters(array $query): array
    {
        $filters = [];
        foreach (['category_id', 'light_level', 'watering_level', 'toxicity', 'kind', 'mode', 'size', 'colour', 'budget', 'q'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 120) {
                throw new ValidationException([$key => 'Choose a valid filter.']);
            }
            $filters[$key] = trim($value);
        }
        if ($filters['category_id'] !== ''
            && (!ctype_digit($filters['category_id']) || strlen($filters['category_id']) > 18 || (int) $filters['category_id'] < 1)) {
            throw new ValidationException(['category_id' => 'Choose a valid category.']);
        }
        if ($filters['kind'] !== '' && !in_array($filters['kind'], self::KINDS, true)) {
            throw new ValidationException(['kind' => 'Choose a valid product type.']);
        }
        if ($filters['mode'] !== '' && !in_array($filters['mode'], ['buy', 'rent'], true)) {
            throw new ValidationException(['mode' => 'Choose buy or rent.']);
        }
        foreach (self::CARE as $key) {
            if ($filters[$key] !== '' && ($filters[$key] === 'unknown' || preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $filters[$key]) !== 1)) {
                throw new ValidationException([$key => 'Choose a verified plant-care value.']);
            }
        }
        foreach (['size', 'colour'] as $key) {
            if ($filters[$key] !== '' && strtolower(trim($filters[$key])) === 'unknown') {
                throw new ValidationException([$key => 'Choose a verified product detail.']);
            }
        }
        $sort = $query['sort'] ?? 'variety';
        if (!is_string($sort) || !in_array($sort, ['', 'name', 'price_asc', 'price_desc', 'variety'], true)) {
            throw new ValidationException(['sort' => 'Choose a valid sort order.']);
        }
        $budgetMinor = null;
        if ($filters['budget'] !== '') {
            if (preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $filters['budget']) !== 1) {
                throw new ValidationException(['budget' => 'Enter a maximum budget in ZAR, up to two decimal places.']);
            }
            [$whole, $fraction] = array_pad(explode('.', $filters['budget'], 2), 2, '');
            $budgetMinor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        }
        return [
            'sort' => $sort, 'category_id' => $filters['category_id'], 'kind' => $filters['kind'], 'mode' => $filters['mode'],
            'size' => $filters['size'], 'colour' => $filters['colour'], 'budget' => $filters['budget'],
            'budget_minor' => $budgetMinor, 'q' => $filters['q'],
            'light_level' => $filters['light_level'], 'watering_level' => $filters['watering_level'],
            'toxicity' => $filters['toxicity'],
        ];
    }

    /** Product/filter API usable for the entire catalogue (`$collectionCode = null`) or one collection. */
    public function browse(array $query, ?string $collectionCode = null): array
    {
        $filters = self::normalizeFilters($query);
        if ($collectionCode !== null && ($query['sort'] ?? '') === '') {
            $filters['sort'] = '';
        }
        $page = $query['page'] ?? '1';
        if (!is_string($page) || !ctype_digit($page) || strlen($page) > 4 || (int) $page < 1) {
            throw new ValidationException(['page' => 'Choose a valid page.']);
        }
        if ($collectionCode !== null && preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $collectionCode) !== 1) {
            throw new ValidationException(['collection' => 'Choose a valid collection.']);
        }
        $pageNumber = (int) $page;
        $rows = $this->collections->browse($filters, $collectionCode, 25, ($pageNumber - 1) * 24);
        $hasNext = count($rows) > 24;
        if ($hasNext) {
            array_pop($rows);
        }
        $collection = $collectionCode === null ? null : $this->collections->collection($collectionCode);
        if ($collectionCode !== null && $collection === null) {
            return ['products' => [], 'filters' => $filters, 'options' => $this->collections->filterOptions(),
                'categories' => $this->collections->categories(), 'collections' => $this->collections->collections(),
                'collection' => null, 'compositions' => [], 'page' => $pageNumber, 'hasNext' => false, 'total' => 0];
        }
        return [
            'products' => $rows, 'filters' => $filters, 'options' => $this->collections->filterOptions(),
            'categories' => $this->collections->categories(), 'collections' => $this->collections->collections(),
            'collection' => $collection,
            'compositions' => $collectionCode === null ? [] : $this->collections->compositions($collectionCode),
            'page' => $pageNumber, 'hasNext' => $hasNext, 'total' => $this->count($filters, $collectionCode),
        ];
    }

    /** Apply already normalized filters; useful to catalogue controllers composing their own page model. */
    public function products(array $normalizedFilters, ?string $collectionCode = null, int $limit = 24, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid catalogue result window.');
        }
        return $this->collections->browse($normalizedFilters, $collectionCode, $limit, $offset);
    }

    /** Count the same filtered rows browse() pages through, without the result window. */
    public function count(array $normalizedFilters, ?string $collectionCode = null): int
    {
        return $this->collections->count($normalizedFilters, $collectionCode);
    }

    public function collections(): array
    {
        return $this->collections->collections();
    }

    public function filterOptions(): array
    {
        return $this->collections->filterOptions();
    }
}
