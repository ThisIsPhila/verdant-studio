<?php

declare(strict_types=1);

namespace Verdant\Storefront;

use mysqli;
use Verdant\Compositions\CompositionService;
use Verdant\Support\ValidationException;

/** Read-only discovery over active composition rules and their current eligible SKU options. */
final class ArrangementDiscoveryService
{
    private const PAGE_SIZE = 12;

    public function __construct(
        private readonly mysqli $connection,
        private readonly CompositionService $compositions,
    ) {
    }

    /** @return array<string,mixed> */
    public function browse(array $query): array
    {
        $homesCodes = $this->homesDefinitionCodes();
        $definitions = [];
        foreach ($this->compositions->activeDefinitions() as $definition) {
            if (!is_array($definition) || ($definition['status'] ?? null) !== 'active') {
                continue;
            }
            $summary = $this->summarize($definition, isset($homesCodes[$definition['definition_code'] ?? '']));
            if ($summary !== null) {
                $definitions[] = $summary;
            }
        }

        $sizeOptions = [];
        $hasHomes = false;
        $hasBuy = false;
        $hasRent = false;
        foreach ($definitions as $definition) {
            $hasHomes = $hasHomes || in_array('homes', $definition['spaces'], true);
            $hasBuy = $hasBuy || in_array('buy', $definition['modes'], true);
            $hasRent = $hasRent || in_array('rent', $definition['modes'], true);
            foreach ($definition['sizes'] as $size) {
                $sizeOptions[$size] = $size;
            }
        }
        $options = [
            'space' => $hasHomes ? ['homes' => 'Homes'] : [],
            'size' => array_values($sizeOptions),
            'mode' => array_filter(['buy' => $hasBuy ? 'Buy' : null, 'rent' => $hasRent ? 'Rent or mixed' : null]),
        ];
        $filters = self::normalizeFilters($query, $options);
        $matching = array_values(array_filter($definitions, static function (array $definition) use ($filters): bool {
            if ($filters['q'] !== '') {
                $searchText = [$definition['name']];
                foreach ($definition['components'] as $component) {
                    $searchText[] = $component['label'];
                    foreach ($component['options'] as $option) $searchText[] = $option['name'];
                }
                if (stripos(implode(' ', $searchText), $filters['q']) === false) return false;
            }
            if ($filters['space'] !== '' && !in_array($filters['space'], $definition['spaces'], true)) {
                return false;
            }
            if ($filters['size'] !== '' && !in_array($filters['size'], $definition['sizes'], true)) {
                return false;
            }
            if ($filters['mode'] !== '' && !in_array($filters['mode'], $definition['modes'], true)) {
                return false;
            }
            if ($filters['budget_minor'] !== null) {
                $buyEstimate = $definition['estimated_buy_floor_minor'];
                $rentEstimate = $definition['estimated_rent_floor_minor'];
                if ($definition['has_mixed_modes']) {
                    $buyEstimate = $definition['mixed_buy_subtotal_minor'];
                    $rentEstimate = $definition['mixed_rent_subtotal_minor'];
                }
                if ($filters['mode'] === 'buy') {
                    $estimates = [$buyEstimate];
                } elseif ($filters['mode'] === 'rent') {
                    $estimates = $definition['has_mixed_modes'] ? [$buyEstimate, $rentEstimate] : [$rentEstimate];
                } elseif ($definition['has_mixed_modes']) {
                    $estimates = [$buyEstimate, $rentEstimate];
                } else {
                    $estimates = [$buyEstimate ?? $rentEstimate];
                }
                if ($estimates === [] || in_array(null, $estimates, true) || max($estimates) > $filters['budget_minor']) {
                    return false;
                }
            }
            return true;
        }));

        $page = $filters['page'];
        $offset = ($page - 1) * self::PAGE_SIZE;
        $pageRows = array_slice($matching, $offset, self::PAGE_SIZE + 1);
        $hasNext = count($pageRows) > self::PAGE_SIZE;
        if ($hasNext) {
            array_pop($pageRows);
        }
        return [
            'arrangements' => $pageRows,
            'filters' => $filters,
            'options' => $options,
            'page' => $page,
            'hasNext' => $hasNext,
            'total' => count($matching),
        ];
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $options @return array<string,string|int|null> */
    public static function normalizeFilters(array $query, array $options): array
    {
        $filters = [];
        foreach (['space', 'size', 'mode', 'budget', 'page', 'q'] as $key) {
            $value = $query[$key] ?? ($key === 'page' ? '1' : '');
            if (!is_string($value) || strlen($value) > 120) {
                throw new ValidationException([$key => 'Choose a valid arrangement filter.']);
            }
            $filters[$key] = trim($value);
        }
        foreach (['space', 'size'] as $key) {
            $validOption = $key === 'space'
                ? array_key_exists($filters[$key], $options[$key] ?? [])
                : in_array($filters[$key], $options[$key] ?? [], true);
            if ($filters[$key] !== '' && !$validOption) {
                throw new ValidationException([$key => 'Choose an available option.']);
            }
        }
        if ($filters['mode'] !== '' && !in_array($filters['mode'], ['buy', 'rent'], true)) {
            throw new ValidationException(['mode' => 'Choose buy or rent.']);
        }
        $budgetMinor = null;
        if ($filters['budget'] !== '') {
            if (preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $filters['budget']) !== 1) {
                throw new ValidationException(['budget' => 'Enter a maximum minimum-arrangement estimate in ZAR, up to two decimal places.']);
            }
            [$whole, $fraction] = array_pad(explode('.', $filters['budget'], 2), 2, '');
            $budgetMinor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        }
        if ($filters['page'] === '' || !ctype_digit($filters['page']) || strlen($filters['page']) > 4 || (int) $filters['page'] < 1) {
            throw new ValidationException(['page' => 'Choose a valid results page.']);
        }
        return [
            'space' => $filters['space'], 'size' => $filters['size'], 'mode' => $filters['mode'],
            'q' => $filters['q'], 'budget' => $filters['budget'], 'budget_minor' => $budgetMinor, 'page' => (int) $filters['page'],
        ];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed>|null */
    private function summarize(array $definition, bool $inHomes): ?array
    {
        $componentCards = [];
        $sizes = [];
        $hasRentCandidate = false;
        $buyComponentPrices = [];
        $rentComponentPrices = [];
        $requiredComponentPrices = [];
        $buyFloor = 0;
        $rentFloor = 0;
        $allRequiredBuy = true;
        $allRequiredRent = true;
        $allRequiredAny = true;

        if (($definition['strategy'] ?? null) === 'finished_stock') {
            $finished = $this->finishedProduct((int) ($definition['finished_product_id'] ?? 0));
            if ($finished === null) {
                return null;
            }
            $modePrices = self::eligiblePrices($finished, 'either');
            if ($modePrices === []) {
                return null;
            }
            if (isset($finished['size_label']) && self::verifiedSize($finished['size_label'])) {
                $sizes[(string) $finished['size_label']] = (string) $finished['size_label'];
            }
            $buyComponentPrices = $modePrices['buy'] === null ? [] : [$modePrices['buy']];
            $rentComponentPrices = $modePrices['rent'] === null ? [] : [$modePrices['rent']];
            $requiredComponentPrices[] = $modePrices;
            $allRequiredBuy = $modePrices['buy'] !== null;
            $allRequiredRent = $modePrices['rent'] !== null;
            $buyFloor = $modePrices['buy'] ?? 0;
            $rentFloor = $modePrices['rent'] ?? 0;
            $allRequiredAny = true;
            $hasRentCandidate = $modePrices['rent'] !== null;
            $componentCards[] = [
                'label' => 'Finished arrangement', 'role' => 'arrangement', 'required' => true,
                'quantity' => 1, 'options' => [$this->optionForCard($finished, $modePrices)],
            ];
        } else {
            foreach (($definition['components'] ?? []) as $component) {
                $products = $this->componentProducts($definition, $component);
                $options = [];
                $buyPrices = [];
                $rentPrices = [];
                foreach ($products as $product) {
                    $prices = self::eligiblePrices($product, (string) ($component['permitted_mode'] ?? 'sale'));
                    if ($prices === []) {
                        continue;
                    }
                    $options[] = $this->optionForCard($product, $prices);
                    if (isset($product['size_label']) && self::verifiedSize($product['size_label'])) {
                        $sizes[(string) $product['size_label']] = (string) $product['size_label'];
                    }
                    if ($prices['buy'] !== null) { $buyPrices[] = $prices['buy']; }
                    if ($prices['rent'] !== null) { $rentPrices[] = $prices['rent']; }
                }
                $required = (bool) ($component['required'] ?? false);
                if ($required && $options === []) {
                    $allRequiredAny = false;
                    $allRequiredBuy = false;
                    $allRequiredRent = false;
                }
                $canBuyComponent = $buyPrices !== [];
                $canRentComponent = $rentPrices !== [];
                if ($required) {
                    $quantityFactor = max(1, (int) ($component['min_quantity'] ?? 1))
                        * max(1, (int) ($component['min_selections'] ?? 1));
                    $buyComponentFloor = $buyPrices === [] ? null : min($buyPrices) * $quantityFactor;
                    $rentComponentFloor = $rentPrices === [] ? null : min($rentPrices) * $quantityFactor;
                    $requiredComponentPrices[] = ['buy' => $buyComponentFloor, 'rent' => $rentComponentFloor];
                    $allRequiredBuy = $allRequiredBuy && $canBuyComponent;
                    $allRequiredRent = $allRequiredRent && $canRentComponent;
                    if (!$canBuyComponent && !$canRentComponent) { $allRequiredAny = false; }
                    if ($canBuyComponent) {
                        $buyFloor += $buyComponentFloor;
                    }
                    if ($canRentComponent) {
                        $rentFloor += $rentComponentFloor;
                    }
                }
                $hasRentCandidate = $hasRentCandidate || ($required && $canRentComponent);
                $buyComponentPrices = array_merge($buyComponentPrices, $buyPrices);
                $rentComponentPrices = array_merge($rentComponentPrices, $rentPrices);
                $componentCards[] = [
                    'label' => (string) ($component['label'] ?? 'Arrangement component'),
                    'role' => (string) ($component['component_role'] ?? 'other'),
                    'required' => $required,
                    'quantity' => max(1, (int) ($component['min_quantity'] ?? 1))
                        * max(1, (int) ($component['min_selections'] ?? 1)),
                    'options' => $options,
                ];
            }
            if (!$allRequiredAny) {
                return null;
            }
            if ($componentCards === []) {
                return null;
            }
        }

        $modes = [];
        if ($allRequiredBuy) { $modes[] = 'buy'; }
        if ($allRequiredAny && $hasRentCandidate) { $modes[] = 'rent'; }
        if ($modes === []) {
            return null;
        }
        $spaces = $inHomes ? ['homes'] : [];
        return [
            'definition_code' => (string) $definition['definition_code'],
            'name' => (string) $definition['name'],
            'arrangement_kind' => (string) $definition['arrangement_kind'],
            'strategy' => (string) $definition['strategy'],
            'spaces' => $spaces,
            'sizes' => array_values($sizes),
            'modes' => $modes,
            'has_mixed_modes' => $allRequiredAny && $hasRentCandidate && !$allRequiredRent,
            'components' => $componentCards,
            'component_prices' => [
                'buy' => $buyComponentPrices,
                'rent' => $rentComponentPrices,
            ],
            'required_component_prices' => $requiredComponentPrices,
            'estimated_buy_floor_minor' => $allRequiredBuy ? $buyFloor : null,
            'estimated_rent_floor_minor' => $allRequiredRent ? $rentFloor : null,
            'mixed_buy_subtotal_minor' => !$allRequiredBuy && $buyFloor > 0 ? $buyFloor : null,
            'mixed_rent_subtotal_minor' => !$allRequiredRent && $rentFloor > 0 ? $rentFloor : null,
            'lowest_buy_component_minor' => $buyComponentPrices === [] ? null : min($buyComponentPrices),
            'lowest_rent_component_minor' => $rentComponentPrices === [] ? null : min($rentComponentPrices),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function componentProducts(array $definition, array $component): array
    {
        if (($component['source_type'] ?? null) !== 'compatible_pot') {
            return is_array($component['product_options'] ?? null) ? $component['product_options'] : [];
        }
        $anchorId = (int) ($component['compatibility_anchor_component_id'] ?? 0);
        $anchor = null;
        foreach (($definition['components'] ?? []) as $candidate) {
            if ((int) ($candidate['component_id'] ?? 0) === $anchorId) { $anchor = $candidate; break; }
        }
        if ($anchor === null) { return []; }
        $products = [];
        foreach (($anchor['product_options'] ?? []) as $plant) {
            $plantId = (int) ($plant['product_id'] ?? 0);
            if ($plantId < 1 || ($plant['product_kind'] ?? null) !== 'plant') { continue; }
            foreach ($this->compositions->compatiblePots((string) $definition['definition_code'], (string) $component['component_key'], $plantId) as $pot) {
                $products[(int) ($pot['product_id'] ?? 0)] = $pot;
            }
        }
        return array_values(array_filter($products, static fn (array $product): bool => (int) ($product['product_id'] ?? 0) > 0));
    }

    private function finishedProduct(int $productId): ?array
    {
        if ($productId < 1) { return null; }
        $statement = $this->connection->prepare(
            'SELECT p.product_id, p.sku, p.name, p.product_kind, p.size_label,
                    p.buy_enabled, p.rent_enabled, p.retail_price_minor, p.rental_daily_rate_minor
             FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             WHERE p.product_id = ? AND p.archived_at IS NULL AND c.archived_at IS NULL
               AND (p.family_id IS NULL OR (f.archived_at IS NULL AND f.product_kind = p.product_kind))',
        );
        $statement->execute([$productId]);
        try {
            $product = $statement->get_result()->fetch_assoc() ?: null;
            if ($product === null) { return null; }
            foreach (['product_id', 'buy_enabled', 'rent_enabled', 'retail_price_minor', 'rental_daily_rate_minor'] as $field) {
                if ($product[$field] !== null) { $product[$field] = (int) $product[$field]; }
            }
            return $product;
        } finally {
            $statement->close();
        }
    }

    /** @return array<string,true> */
    private function homesDefinitionCodes(): array
    {
        $statement = $this->connection->prepare(
            "SELECT DISTINCT d.definition_code FROM collection_compositions cc
             JOIN collections c ON c.collection_id = cc.collection_id AND c.state = 'active' AND c.collection_code = 'homes'
             JOIN composition_definitions d ON d.definition_id = cc.definition_id AND d.status = 'active'",
        );
        $statement->execute();
        try {
            $codes = [];
            foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $codes[(string) $row['definition_code']] = true;
            }
            return $codes;
        } finally {
            $statement->close();
        }
    }

    /** @return array{buy:?int,rent:?int} */
    private static function eligiblePrices(array $product, string $permittedMode): array
    {
        $buy = in_array($permittedMode, ['sale', 'either'], true) && !empty($product['buy_enabled'])
            && is_numeric($product['retail_price_minor'] ?? null) ? (int) $product['retail_price_minor'] : null;
        $rent = in_array($permittedMode, ['rent', 'either'], true) && !empty($product['rent_enabled'])
            && is_numeric($product['rental_daily_rate_minor'] ?? null) ? (int) $product['rental_daily_rate_minor'] : null;
        return ['buy' => $buy !== null && $buy >= 0 ? $buy : null, 'rent' => $rent !== null && $rent >= 0 ? $rent : null];
    }

    private function optionForCard(array $product, array $prices): array
    {
        return [
            'sku' => (string) ($product['sku'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'size_label' => self::verifiedSize($product['size_label'] ?? null) ? (string) $product['size_label'] : null,
            'buy_price_minor' => $prices['buy'],
            'rent_rate_minor' => $prices['rent'],
        ];
    }

    private static function verifiedSize(mixed $size): bool
    {
        return is_string($size) && trim($size) !== '' && strtolower(trim($size)) !== 'unknown';
    }
}
