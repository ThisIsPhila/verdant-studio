<?php

declare(strict_types=1);

namespace Verdant\Compositions;

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Inventory\RentalPeriod;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\TransactionManager;

/**
 * Server-authoritative composition validation, saved selections and reservations.
 * Pending composition checkouts require a sales-order anchor for the later single-payment contract;
 * a rent-only checkout uses an explicitly flagged empty payment-anchor order, never a retail leg.
 */
final class CompositionService
{
    public function __construct(
        private readonly CompositionRepository $compositions,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function definition(string $code): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) !== 1) {
            return null;
        }
        $definition = $this->compositions->definition($code);
        return ($definition !== null && $definition['status'] === 'active') ? $definition : null;
    }

    /** Read the active, non-archived SKU backing a finished-stock definition for presentation. */
    public function finishedProductForDefinition(string $code): ?array
    {
        $definition = $this->definition($code);
        if ($definition === null || $definition['strategy'] !== 'finished_stock' || $definition['finished_product_id'] === null) {
            return null;
        }
        $product = $this->compositions->product((int) $definition['finished_product_id']);
        if ($product === null || !$this->productIsActive($product)) {
            return null;
        }
        foreach (['product_id', 'retail_price_minor', 'rental_daily_rate_minor'] as $field) {
            if ($product[$field] !== null) {
                $product[$field] = (int) $product[$field];
            }
        }
        foreach (['buy_enabled', 'rent_enabled'] as $field) {
            $product[$field] = (bool) $product[$field];
        }
        return $product;
    }

    /** @return list<array<string,mixed>> */
    public function activeDefinitions(): array
    {
        return $this->compositions->activeDefinitions();
    }

    /** Resolve only explicitly allowed pots; this read never creates stock holds. */
    public function compatiblePots(string $definitionCode, string $componentKey, int $plantProductId): array
    {
        $definition = $this->definition($definitionCode);
        if ($definition === null || $plantProductId < 1) {
            return [];
        }
        $potRule = $anchorRule = null;
        foreach ($definition['components'] as $rule) {
            if ($rule['component_key'] === $componentKey && $rule['source_type'] === 'compatible_pot') {
                $potRule = $rule;
            }
        }
        if ($potRule === null) {
            return [];
        }
        foreach ($definition['components'] as $rule) {
            if ((int) $rule['component_id'] === (int) $potRule['compatibility_anchor_component_id']) {
                $anchorRule = $rule;
                break;
            }
        }
        $plant = $this->compositions->product($plantProductId);
        if ($anchorRule === null || $plant === null || !$this->productIsActive($plant)
            || $plant['product_kind'] !== 'plant' || !$this->isAllowedByRule($anchorRule, $plant)) {
            return [];
        }
        return $this->compositions->compatibleProducts($plantProductId);
    }

    /** Read an active pot's display labels for a plant-change refresh; never grants compatibility. */
    public function activePotForPresentation(int $productId): ?array
    {
        return $this->compositions->activePotForPresentation($productId);
    }

    /** Server quote only: reports current prices and capacity estimates and writes no holds. */
    public function quote(string $definitionCode, array $selection): array
    {
        $definition = $this->definition($definitionCode);
        if ($definition === null) {
            throw new \DomainException('This composition is no longer available.');
        }
        $resolved = $this->resolve($definition, $this->normalizeSelection($selection));
        $lines = $this->mergeSelections($resolved['selections']);
        $total = $this->total($lines);
        $projectedCapacity = $this->quoteCapacityByProduct($lines);
        $available = true;
        foreach ($lines as &$line) {
            $free = $line['mode'] === 'sale'
                ? $this->capacity->retailAvailable((int) $line['product_id'])
                : $this->capacity->availableForPeriod(
                    (int) $line['product_id'], (string) $line['start_date'], (string) $line['end_date_exclusive'],
                );
            $line['line_total_minor'] = $this->total([$line]);
            $line['available_quantity'] = $free;
            $line['available'] = $free >= (int) $line['quantity']
                && ($projectedCapacity[(int) $line['product_id']] ?? false);
            $available = $available && $line['available'];
            unset($line['line_key']);
        }
        unset($line);
        return ['definition' => $definition, 'lines' => $lines, 'total_minor' => $total, 'available' => $available]
            + $this->priceBreakdown($lines);
    }

    /** Conservative shared-SKU estimate for proposed sale and dated-rental claims; writes no rows. */
    private function quoteCapacityByProduct(array $lines): array
    {
        $saleQuantities = [];
        $rentals = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            if ($line['mode'] === 'sale') {
                $saleQuantities[$productId] = ($saleQuantities[$productId] ?? 0) + (int) $line['quantity'];
            } else {
                $rentals[$productId][] = $line;
            }
        }
        $productIds = array_values(array_unique(array_merge(array_keys($saleQuantities), array_keys($rentals))));
        $availableByProduct = [];
        foreach ($productIds as $productId) {
            $saleQuantity = $saleQuantities[$productId] ?? 0;
            $saleAvailable = $saleQuantity === 0 || $this->capacity->retailAvailable((int) $productId) >= $saleQuantity;
            $intervalAvailable = true;
            $claims = $rentals[$productId] ?? [];
            if ($claims !== []) {
                $boundaries = [];
                foreach ($claims as $claim) {
                    $boundaries[(string) $claim['start_date']] = true;
                    $boundaries[(string) $claim['end_date_exclusive']] = true;
                }
                $dates = array_keys($boundaries);
                sort($dates, SORT_STRING);
                for ($index = 0; $index < count($dates) - 1; ++$index) {
                    $start = $dates[$index];
                    $end = $dates[$index + 1];
                    $proposed = 0;
                    foreach ($claims as $claim) {
                        if ($claim['start_date'] < $end && $claim['end_date_exclusive'] > $start) {
                            $proposed += (int) $claim['quantity'];
                        }
                    }
                    if ($proposed === 0) {
                        continue;
                    }
                    $free = $this->capacity->availableForPeriod((int) $productId, $start, $end);
                    if ($saleQuantity + $proposed > $free) {
                        $intervalAvailable = false;
                        break;
                    }
                }
            }
            $availableByProduct[(int) $productId] = $saleAvailable && $intervalAvailable;
        }
        return $availableByProduct;
    }

    /**
     * Persist a customer-owned selection. Prices here are informational snapshots; checkout always
     * reloads the product and calculates the current server price.
     * @param list<array<string,mixed>> $selection
     */
    public function save(int $customerId, string $name, string $definitionCode, array $selection): int
    {
        $name = trim($name);
        if ($customerId < 1 || $name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException('Enter a valid name for this saved composition.');
        }
        $normalized = $this->normalizeSelection($selection);
        return $this->transactions->run(function () use ($customerId, $name, $definitionCode, $normalized): int {
            if (!$this->compositions->activeCustomer($customerId, true)) {
                throw new \DomainException('Your account is unavailable for saved compositions.');
            }
            $definition = $this->compositions->definition($definitionCode, true);
            if ($definition === null || $definition['status'] !== 'active') {
                throw new \DomainException('This composition is no longer available.');
            }
            $resolved = $this->resolve($definition, $normalized);
            $savedId = $this->compositions->insertSaved($customerId, $definition, $name);
            foreach ($resolved['selections'] as $selectionRow) {
                $this->compositions->insertSavedComponent($savedId, $selectionRow, $selectionRow['product']);
            }
            return $savedId;
        });
    }

    public function saved(int $customerId, int $savedCompositionId): ?array
    {
        if ($customerId < 1 || $savedCompositionId < 1) {
            return null;
        }
        return $this->compositions->savedForCustomer($customerId, $savedCompositionId);
    }

    public function checkout(int $customerId, int $checkoutId): ?array
    {
        $checkout = $customerId > 0 && $checkoutId > 0 ? $this->compositions->checkoutForCustomer($customerId, $checkoutId) : null;
        if ($checkout !== null) {
            foreach ($checkout['lines'] as &$line) {
                $line['line_total_minor'] = $this->total([$line]);
            }
            unset($line);
            $checkout += $this->priceBreakdown($checkout['lines']);
        }
        return $checkout;
    }

    public function checkouts(int $customerId): array
    {
        return $customerId > 0 ? $this->compositions->checkoutsForCustomer($customerId) : [];
    }

    public function savedList(int $customerId): array
    {
        return $customerId > 0 ? $this->compositions->savedList($customerId) : [];
    }

    public function archiveSaved(int $customerId, int $savedCompositionId): bool
    {
        if ($customerId < 1 || $savedCompositionId < 1) {
            return false;
        }
        return $this->transactions->run(function () use ($customerId, $savedCompositionId): bool {
            if (!$this->compositions->activeCustomer($customerId, true)) {
                return false;
            }
            return $this->compositions->archiveSaved($customerId, $savedCompositionId);
        });
    }

    /**
     * Create all sale/rental children and capacity holds in one transaction. The caller supplies
     * selections (or the owner-checked saved() result); prices and capabilities are never trusted
     * from the browser. Rental end dates in this API are customer-inclusive.
     * @param list<array{component_key:string,product_id:int,quantity:int,mode:string,start_date?:string,end_date_inclusive?:string}> $selection
     * @return array<string,mixed>
     */
    public function createPending(int $customerId, string $definitionCode, array $selection, string $checkoutKey): array
    {
        if ($customerId < 1 || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $checkoutKey) !== 1
            || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $definitionCode) !== 1) {
            throw new \InvalidArgumentException('The composition checkout details are invalid.');
        }
        $normalized = $this->normalizeSelection($selection);
        $selectionHash = hash('sha256', json_encode(
            ['definition_code' => $definitionCode, 'selection' => $this->canonicalSelection($normalized)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        for ($attempt = 0; ; ++$attempt) {
            try {
                return $this->transactions->run(function () use ($customerId, $definitionCode, $normalized, $checkoutKey, $selectionHash): array {
                    $existing = $this->compositions->checkoutForCustomerKey($customerId, $checkoutKey, true);
                    if ($existing !== null) {
                        if (!hash_equals((string) $existing['selection_sha256'], $selectionHash)) {
                            throw new \DomainException('This checkout key was already used for a different composition.');
                        }
                        return $existing;
                    }
                    if (!$this->compositions->activeCustomer($customerId, true)) {
                        throw new \DomainException('Your account is unavailable for checkout.');
                    }
                    // This locking read is only used to include definition-owned finished stock in the lock set.
                    $preflightDefinition = $this->compositions->definition($definitionCode, true);
                    if ($preflightDefinition === null || $preflightDefinition['status'] !== 'active') {
                        throw new \DomainException('This composition is no longer available.');
                    }
                    $productIds = array_map(static fn (array $row): int => (int) $row['product_id'], $normalized);
                    if ($preflightDefinition['strategy'] === 'finished_stock') {
                        $productIds[] = (int) $preflightDefinition['finished_product_id'];
                    }
                    $productIds = array_values(array_unique($productIds));
                    $this->inventory->lockProducts($productIds);
                    // Reload current rules and products only after the complete sorted inventory lock set is held.
                    $definition = $this->compositions->definition($definitionCode, true);
                    if ($definition === null || $definition['status'] !== 'active'
                        || ($definition['strategy'] === 'finished_stock'
                            && !in_array((int) $definition['finished_product_id'], $productIds, true))) {
                        throw new \DomainException('Composition rules changed while checkout was prepared. Please retry.');
                    }
                    $resolved = $this->resolve($definition, $normalized, true);
                    $resolvedIds = array_values(array_unique(array_map(
                        static fn (array $selectionRow): int => (int) $selectionRow['product_id'],
                        $resolved['selections'],
                    )));
                    if (array_diff($resolvedIds, $productIds) !== []) {
                        throw new \DomainException('Composition choices changed while checkout was prepared. Please retry.');
                    }
                    // Read product/category/family state after inventory locks, then calculate fresh server snapshots.
                    foreach ($resolved['selections'] as &$selectionRow) {
                        $product = $this->compositions->product((int) $selectionRow['product_id'], true);
                        if ($product === null || !$this->productIsActive($product)) {
                            throw new \DomainException('One or more composition items are no longer available.');
                        }
                        $this->assertSelectionProduct($selectionRow, $product, $definition);
                        $selectionRow['product'] = $product;
                        $selectionRow['product_name_snapshot'] = (string) $product['name'];
                        $selectionRow['sku_snapshot'] = (string) $product['sku'];
                        $selectionRow['unit_price_minor'] = $selectionRow['mode'] === 'sale' ? (int) $product['retail_price_minor'] : null;
                        $selectionRow['daily_rate_minor'] = $selectionRow['mode'] === 'rent' ? (int) $product['rental_daily_rate_minor'] : null;
                    }
                    unset($selectionRow);

                    $lines = $this->mergeSelections($resolved['selections']);
                    $total = $this->total($lines);
                    foreach ($lines as $line) {
                        if ($line['mode'] === 'sale'
                            && $this->capacity->retailAvailable((int) $line['product_id']) < (int) $line['quantity']) {
                            throw new \DomainException('A purchased component no longer has enough available stock. No composition items were reserved.');
                        }
                    }

                    $orderKey = 'cmp_' . substr(hash('sha256', $customerId . ':' . $checkoutKey), 0, 64);
                    $orderId = $this->compositions->createOrderAnchor($customerId, $orderKey);
                    $saleLines = array_values(array_filter($lines, static fn (array $line): bool => $line['mode'] === 'sale'));
                    $orderRole = $saleLines === [] ? 'payment_anchor' : 'sale';
                    $saleItems = [];
                    foreach ($saleLines as $line) {
                        $saleItems[$line['line_key']] = $this->compositions->insertSaleLine($orderId, $line);
                    }

                    $rentalLines = array_values(array_filter($lines, static fn (array $line): bool => $line['mode'] === 'rent'));
                    $rentalId = null;
                    $rentalItems = [];
                    if ($rentalLines !== []) {
                        $rentalKey = 'cmp_' . substr(hash('sha256', 'rental:' . $customerId . ':' . $checkoutKey), 0, 64);
                        $rentalId = $this->compositions->createRental($customerId, $rentalKey);
                        usort($rentalLines, static fn (array $a, array $b): int =>
                            [$a['product_id'], $a['start_date'], $a['end_date_exclusive']] <=> [$b['product_id'], $b['start_date'], $b['end_date_exclusive']]
                        );
                        foreach ($rentalLines as $line) {
                            if ($this->capacity->availableForPeriod(
                                (int) $line['product_id'], (string) $line['start_date'], (string) $line['end_date_exclusive'],
                            ) < (int) $line['quantity']) {
                                throw new \DomainException('A rented component is no longer available for those dates. No composition items were reserved.');
                            }
                            $rentalItems[$line['line_key']] = $this->compositions->insertRentalLine($rentalId, $line);
                        }
                    }

                    $checkoutId = $this->compositions->insertCheckout([
                        'customer_id' => $customerId, 'definition_id' => $definition['definition_id'],
                        'definition_code' => $definition['definition_code'], 'definition_name' => $definition['name'],
                        'checkout_key' => $checkoutKey, 'selection_sha256' => $selectionHash,
                        'order_id' => $orderId, 'order_role' => $orderRole, 'rental_id' => $rentalId,
                        'total_minor' => $total,
                    ]);
                    foreach ($lines as $line) {
                        $this->compositions->insertCheckoutLine(
                            $checkoutId, $line,
                            $line['mode'] === 'sale' ? $saleItems[$line['line_key']] : null,
                            $line['mode'] === 'rent' ? $rentalItems[$line['line_key']] : null,
                        );
                    }
                    $created = $this->compositions->checkoutForCustomerKey($customerId, $checkoutKey)
                        ?? throw new \LogicException('Created composition checkout could not be loaded.');
                    $created['existing'] = false;
                    return $created;
                });
            } catch (DatabaseException $error) {
                if (in_array($error->getCode(), [1205, 1213], true) && $attempt < 2) {
                    usleep(random_int(10000, 50000));
                    continue;
                }
                if ($error->getCode() === 1062) {
                    $existing = $this->compositions->checkoutForCustomerKey($customerId, $checkoutKey);
                    if ($existing !== null && hash_equals((string) $existing['selection_sha256'], $selectionHash)) {
                        return $existing;
                    }
                    if ($existing !== null) {
                        throw new \DomainException('This checkout key was already used for a different composition.');
                    }
                }
                if (in_array($error->getCode(), [1205, 1213], true)) {
                    throw new \DomainException('Composition availability changed while checkout was prepared. Please retry.');
                }
                throw $error;
            }
        }
    }

    /** @return array{0:string,1:string} */
    private function canonicalSelection(array $selection): array
    {
        $merged = [];
        foreach ($selection as $row) {
            $identity = implode('|', [$row['component_key'], (string) $row['product_id'], $row['mode'],
                (string) ($row['start_date'] ?? ''), (string) ($row['end_date_exclusive'] ?? '')]);
            $merged[$identity] = ($merged[$identity] ?? 0) + (int) $row['quantity'];
        }
        $parts = [];
        foreach ($merged as $identity => $quantity) {
            $parts[] = $identity . '|' . $quantity;
        }
        sort($parts, SORT_STRING);
        return $parts;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeSelection(array $selection): array
    {
        if (count($selection) > 40) {
            throw new \InvalidArgumentException('A composition can contain at most 40 selected components.');
        }
        $normalized = [];
        foreach ($selection as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Composition selections must be valid component rows.');
            }
            $key = $row['component_key'] ?? null;
            $productId = $row['product_id'] ?? null;
            $quantity = $row['quantity'] ?? null;
            $mode = $row['mode'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key) !== 1
                || !is_int($productId) || $productId < 1 || !is_int($quantity) || $quantity < 1 || $quantity > 10000
                || !is_string($mode) || !in_array($mode, ['sale', 'rent'], true)) {
                throw new \InvalidArgumentException('A composition component or quantity is invalid.');
            }
            $normalizedRow = ['component_key' => $key, 'product_id' => $productId, 'quantity' => $quantity, 'mode' => $mode];
            if ($mode === 'rent') {
                $start = $row['start_date'] ?? null;
                $endInclusive = $row['end_date_inclusive'] ?? null;
                if (!is_string($start) || !is_string($endInclusive)) {
                    throw new \InvalidArgumentException('Choose valid dates for every rented component.');
                }
                $period = RentalPeriod::fromInclusive($start, $endInclusive);
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
                if ($period['start_date'] < $today) {
                    throw new \InvalidArgumentException('Rental dates cannot be in the past.');
                }
                $normalizedRow['start_date'] = $period['start_date'];
                $normalizedRow['end_date_exclusive'] = $period['end_date_exclusive'];
                $normalizedRow['end_date_inclusive'] = $endInclusive;
            }
            $normalized[] = $normalizedRow;
        }
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function resolve(array $definition, array $selection, bool $lock = false): array
    {
        if ($definition['strategy'] === 'finished_stock') {
            if (count($selection) !== 1 || $definition['components'] !== []) {
                throw new \DomainException('Choose one sale or rental quantity for this finished-stock SKU.');
            }
            $chosen = $selection[0];
            if ($chosen['component_key'] !== 'finished' || $chosen['product_id'] !== (int) $definition['finished_product_id']) {
                throw new \DomainException('A finished-stock composition reserves its own SKU only.');
            }
            $product = $this->compositions->product((int) $definition['finished_product_id'], $lock);
            if ($product === null || !$this->productIsActive($product)) {
                throw new \DomainException('The finished composition is not available.');
            }
            $component = [
                'component_key' => 'finished', 'component_label' => $definition['name'],
                'component_id' => null, 'product_id' => (int) $product['product_id'], 'quantity' => $chosen['quantity'],
                'mode' => $chosen['mode'], 'product' => $product,
                'start_date' => $chosen['start_date'] ?? null, 'end_date_exclusive' => $chosen['end_date_exclusive'] ?? null,
            ];
            $this->assertSelectionProduct($component, $product, $definition);
            return ['selections' => [$component]];
        }
        $rulesByKey = [];
        foreach ($definition['components'] as $rule) {
            $rulesByKey[$rule['component_key']] = $rule;
        }
        $grouped = [];
        foreach ($selection as $row) {
            $key = $row['component_key'];
            if (!isset($rulesByKey[$key])) {
                throw new \DomainException('A selected component is not allowed in this composition.');
            }
            $rule = $rulesByKey[$key];
            $group = implode('|', [$key, $row['product_id'], $row['mode'], $row['start_date'] ?? '', $row['end_date_exclusive'] ?? '']);
            if (!isset($grouped[$group])) {
                $grouped[$group] = $row;
            } else {
                $grouped[$group]['quantity'] += $row['quantity'];
            }
            if ($grouped[$group]['quantity'] > (int) $rule['max_quantity']) {
                throw new \DomainException('A selected component exceeds its allowed quantity.');
            }
        }
        $selection = array_values($grouped);
        $byRule = [];
        foreach ($selection as $row) {
            $byRule[$row['component_key']][] = $row;
        }
        foreach ($rulesByKey as $key => $rule) {
            $selected = $byRule[$key] ?? [];
            if (($rule['required'] && $selected === [])
                || count($selected) < ($rule['required'] ? (int) $rule['min_selections'] : 0)
                || count($selected) > (int) $rule['max_selections']) {
                throw new \DomainException('Choose the required number of ' . $rule['label'] . ' components.');
            }
        }
        if ($selection === []) {
            throw new \DomainException('Choose at least one component for this composition.');
        }
        $selectedProductsByRule = [];
        $productsBySelection = [];
        foreach ($selection as $index => $row) {
            $rule = $rulesByKey[$row['component_key']];
            if ($row['quantity'] < (int) $rule['min_quantity'] || $row['quantity'] > (int) $rule['max_quantity']) {
                throw new \DomainException('A selected component has an invalid quantity.');
            }
            $product = $this->compositions->product((int) $row['product_id'], $lock);
            if ($product === null || !$this->productIsActive($product)) {
                throw new \DomainException('One or more composition items are archived or unavailable.');
            }
            $productsBySelection[$index] = $product;
            $selectedProductsByRule[$row['component_key']][] = [
                'component_id' => (int) $rule['component_id'], 'product_id' => (int) $product['product_id'],
                'product_kind' => (string) $product['product_kind'],
            ];
        }
        foreach ($selection as $index => &$row) {
            $rule = $rulesByKey[$row['component_key']];
            $product = $productsBySelection[$index];
            $component = [
                'component_key' => $row['component_key'], 'component_label' => $rule['label'],
                'component_id' => (int) $rule['component_id'], 'product_id' => (int) $product['product_id'],
                'quantity' => (int) $row['quantity'], 'mode' => $row['mode'],
                'start_date' => $row['start_date'] ?? null, 'end_date_exclusive' => $row['end_date_exclusive'] ?? null,
            ];
            $this->assertSourceAllowed($rule, $product, $selectedProductsByRule, $lock);
            $this->assertSelectionProduct($component, $product, $definition);
            $component['product'] = $product;
            $row = $component;
        }
        unset($row);
        usort($selection, static fn (array $a, array $b): int =>
            [$a['component_key'], $a['product_id'], $a['mode'], $a['start_date'] ?? '', $a['end_date_exclusive'] ?? '']
            <=> [$b['component_key'], $b['product_id'], $b['mode'], $b['start_date'] ?? '', $b['end_date_exclusive'] ?? '']
        );
        return ['selections' => $selection];
    }

    private function assertSourceAllowed(array $rule, array $product, array $selectedProductsByRule, bool $lock = false): void
    {
        $productId = (int) $product['product_id'];
        if ($rule['source_type'] === 'product' && !in_array($productId, $rule['allowed_product_ids'], true)) {
            throw new \DomainException('This product is not on the composition allowlist.');
        }
        if ($rule['source_type'] === 'family') {
            if ($product['family_id'] === null || !in_array((int) $product['family_id'], $rule['allowed_family_ids'], true)
                || $product['family_archived_at'] !== null || $product['family_product_kind'] !== $product['product_kind']) {
                throw new \DomainException('This product is not in an allowed active family.');
            }
        }
        if ($rule['source_type'] === 'compatible_pot') {
            $anchorId = (int) ($rule['compatibility_anchor_component_id'] ?? 0);
            $anchorKey = null;
            foreach ($selectedProductsByRule as $key => $products) {
                foreach ($products as $anchor) {
                    if ((int) ($anchor['component_id'] ?? 0) === $anchorId) {
                        $anchorKey = $key;
                        break 2;
                    }
                }
            }
            if ($anchorKey === null || $product['product_kind'] !== 'accessory' || $product['accessory_subtype'] !== 'pot') {
                throw new \DomainException('Choose a pot compatible with a selected plant.');
            }
            $compatible = false;
            foreach ($selectedProductsByRule[$anchorKey] as $anchor) {
                if ($anchor['product_kind'] === 'plant'
                    && $this->compositions->explicitlyCompatible((int) $anchor['product_id'], $productId, $lock)) {
                    $compatible = true;
                    break;
                }
            }
            if (!$compatible) {
                throw new \DomainException('The selected pot is not explicitly compatible with the selected plant.');
            }
        }
    }

    private function isAllowedByRule(array $rule, array $product): bool
    {
        if ($rule['source_type'] === 'product') {
            return in_array((int) $product['product_id'], $rule['allowed_product_ids'], true);
        }
        if ($rule['source_type'] === 'family') {
            return $product['family_id'] !== null
                && in_array((int) $product['family_id'], $rule['allowed_family_ids'], true)
                && $product['family_archived_at'] === null
                && $product['family_product_kind'] === $product['product_kind'];
        }
        return false;
    }

    private function assertSelectionProduct(array $selection, array $product, array $definition): void
    {
        $mode = $selection['mode'];
        $key = $selection['component_key'];
        $rule = null;
        foreach ($definition['components'] as $candidate) {
            if ($candidate['component_key'] === $key) {
                $rule = $candidate;
                break;
            }
        }
        if ($rule !== null) {
            if ($rule['permitted_mode'] === 'sale' && $mode !== 'sale'
                || $rule['permitted_mode'] === 'rent' && $mode !== 'rent') {
                throw new \DomainException('This component cannot be selected in that sale or rental mode.');
            }
            if ($rule['component_role'] === 'plant' && $product['product_kind'] !== 'plant') {
                throw new \DomainException('Plant components must be stock-bearing plant SKUs.');
            }
            if (in_array($rule['component_role'], ['pot', 'container'], true)
                && !($product['product_kind'] === 'accessory' && $product['accessory_subtype'] === 'pot')) {
                throw new \DomainException('Container components must be stock-bearing pot SKUs.');
            }
            if ($rule['component_role'] === 'flower' && !in_array($product['product_kind'], ['cut_flower', 'floral_arrangement'], true)) {
                throw new \DomainException('Flower components must be explicit flower SKUs.');
            }
            if ($rule['component_role'] === 'foliage' && $product['product_kind'] !== 'cut_foliage') {
                throw new \DomainException('Foliage components must be explicit cut-foliage SKUs.');
            }
        }
        if (in_array($product['product_kind'], ['cut_flower', 'cut_foliage', 'floral_arrangement'], true) && $mode !== 'sale') {
            throw new \DomainException('Flowers, cut foliage and floral arrangements are sale-only.');
        }
        if (in_array($product['product_kind'], ['plant_arrangement', 'floral_arrangement'], true)
            && ($definition['strategy'] !== 'finished_stock'
                || (int) $product['product_id'] !== (int) $definition['finished_product_id'])) {
            throw new \DomainException('Nested composition definitions are not supported; select stock-bearing SKU leaves.');
        }
        if ($mode === 'sale') {
            if (!(bool) $product['buy_enabled'] || $product['retail_price_minor'] === null || (int) $product['retail_price_minor'] < 0) {
                throw new \DomainException('This component is not available to buy.');
            }
        } else {
            if (!(bool) $product['rent_enabled'] || $product['rental_daily_rate_minor'] === null || (int) $product['rental_daily_rate_minor'] < 0) {
                throw new \DomainException('This component is not available for rental.');
            }
        }
    }

    private function productIsActive(array $product): bool
    {
        return $product['archived_at'] === null && $product['category_archived_at'] === null
            && ($product['family_id'] === null || ($product['family_archived_at'] === null && $product['family_product_kind'] === $product['product_kind']));
    }

    /** Merge repeated stock leaves by mode and rental interval, retaining every selected choice in snapshots. */
    private function mergeSelections(array $selection): array
    {
        $merged = [];
        foreach ($selection as $row) {
            $product = $row['product'];
            $key = implode('|', [
                (string) $row['product_id'], $row['mode'], $row['start_date'] ?? '', $row['end_date_exclusive'] ?? '',
            ]);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'line_key' => $key, 'product_id' => (int) $row['product_id'],
                    'product_name_snapshot' => (string) $product['name'], 'sku_snapshot' => (string) $product['sku'],
                    'mode' => $row['mode'], 'quantity' => 0, 'start_date' => $row['start_date'] ?? null,
                    'end_date_exclusive' => $row['end_date_exclusive'] ?? null,
                    'unit_price_minor' => $row['mode'] === 'sale' ? (int) $product['retail_price_minor'] : null,
                    'daily_rate_minor' => $row['mode'] === 'rent' ? (int) $product['rental_daily_rate_minor'] : null,
                    'components' => [],
                ];
            }
            if ($row['quantity'] > PHP_INT_MAX - $merged[$key]['quantity']) {
                throw new \OverflowException('Composition quantity is too large.');
            }
            $merged[$key]['quantity'] += (int) $row['quantity'];
            $merged[$key]['components'][] = [
                'component_id' => $row['component_id'], 'component_key' => $row['component_key'],
                'component_label' => $row['component_label'], 'product_id' => (int) $row['product_id'],
                'product_name_snapshot' => (string) $product['name'], 'sku_snapshot' => (string) $product['sku'],
                'mode' => $row['mode'], 'quantity' => (int) $row['quantity'],
                'unit_price_minor' => $row['mode'] === 'sale' ? (int) $product['retail_price_minor'] : null,
                'daily_rate_minor' => $row['mode'] === 'rent' ? (int) $product['rental_daily_rate_minor'] : null,
                'start_date' => $row['start_date'] ?? null, 'end_date_exclusive' => $row['end_date_exclusive'] ?? null,
            ];
        }
        ksort($merged, SORT_STRING);
        return array_values($merged);
    }

    private function priceBreakdown(array $lines): array
    {
        return [
            'sale_total_minor' => $this->total(array_values(array_filter($lines, static fn (array $line): bool => $line['mode'] === 'sale'))),
            'rental_total_minor' => $this->total(array_values(array_filter($lines, static fn (array $line): bool => $line['mode'] === 'rent'))),
        ];
    }

    private function total(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $unit = $line['mode'] === 'sale'
                ? (int) $line['unit_price_minor']
                : RentalPeriod::totalMinor((int) $line['quantity'], (string) $line['start_date'], (string) $line['end_date_exclusive'], (int) $line['daily_rate_minor']);
            if ($line['mode'] === 'sale') {
                if ($unit !== 0 && (int) $line['quantity'] > intdiv(PHP_INT_MAX, $unit)) {
                    throw new \OverflowException('Composition total exceeds the supported integer range.');
                }
                $unit *= (int) $line['quantity'];
            }
            if ($unit > PHP_INT_MAX - $total) {
                throw new \OverflowException('Composition total exceeds the supported integer range.');
            }
            $total += $unit;
        }
        return $total;
    }
}
