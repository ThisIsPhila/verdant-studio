<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\CollectionService;
use Verdant\Support\ValidationException;

final class CatalogueController
{
    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly ?\Verdant\Storefront\StorefrontSectionService $sections = null,
        private readonly ?\Verdant\Storefront\RentalDiscoveryService $rentalDiscovery = null,
        private readonly ?\Verdant\Media\MediaPresenter $mediaPresenter = null,
        private readonly ?\Verdant\Storefront\ArrangementDiscoveryService $arrangementDiscovery = null,
    ) {
    }

    public function index(array $query, ?array $actor = null): void
    {
        $errors = [];
        $status = 200;
        try {
            $data = $this->catalogue->catalogue($query);
        } catch (ValidationException $error) {
            $errors = $error->errors;
            $status = 422;
            $data = $this->catalogue->catalogue([]);
        }
        $rentalMode = ($data['filters']['mode'] ?? '') === 'rent';
        $rentalDates = ['start_date' => '', 'end_date' => '', 'has_dates' => false];
        $rentalQuotes = [];
        if ($rentalMode && $this->rentalDiscovery !== null) {
            try {
                $rentalDates = \Verdant\Storefront\RentalDiscoveryService::normalizeDates($query);
                if ($rentalDates['has_dates']) {
                    $rentalQuotes = $this->rentalDiscovery->quotes(
                        is_array($data['products'] ?? null) ? $data['products'] : [],
                        $rentalDates['start_date'], $rentalDates['end_date'],
                    );
                }
            } catch (ValidationException $error) {
                $errors = array_merge($errors, $error->errors);
                $status = 422;
                foreach (['start_date', 'end_date'] as $dateKey) {
                    $dateValue = $query[$dateKey] ?? '';
                    $rentalDates[$dateKey] = is_string($dateValue) && strlen($dateValue) <= 10 ? $dateValue : '';
                }
            }
        }
        if ($rentalMode) {
            $rentalSets = [];
            if ($this->arrangementDiscovery !== null) {
                try {
                    $rentSets = $this->arrangementDiscovery->browse(['mode' => 'rent'])['arrangements'] ?? [];
                    $namedSets = array_values(array_filter(
                        is_array($rentSets) ? $rentSets : [],
                        static fn($set): bool => str_starts_with((string) ($set['definition_code'] ?? ''), 'set-'),
                    ));
                    $rentalSets = array_slice($namedSets !== [] ? $namedSets : (is_array($rentSets) ? $rentSets : []), 0, 3);
                } catch (ValidationException) {
                    $rentalSets = [];
                }
            }
            $data['rentalSets'] = $rentalSets;
            $eligibleProducts = [];
            foreach (is_array($data['products'] ?? null) ? $data['products'] : [] as $product) {
                if (!\Verdant\Storefront\RentalDiscoveryService::isEligibleProduct($product)) {
                    continue;
                }
                $product['display_mode'] = 'rent';
                if (isset($rentalQuotes[(int) ($product['product_id'] ?? 0)])) {
                    $product['rental_quote'] = $rentalQuotes[(int) $product['product_id']];
                }
                $eligibleProducts[] = $product;
            }
            $data['products'] = $eligibleProducts;
        }
        if ($this->sections !== null) {
            $data['pageSections'] = $this->sections->sectionsForPage($rentalMode ? 'rentals' : 'catalogue');
            if ($rentalMode) foreach ($data['pageSections'] as &$intro) {
                if (($intro['section_type'] ?? '') === 'hero' && ($intro['media_path'] ?? '') === '/assets/images/catalogue/placeholders/plant.svg') {
                    $intro['media_path'] = '/assets/images/product-media/hero-scenes-v02.png';
                    $intro['media_alt'] = 'Potted plants in a warm interior';
                    $intro['media_crop'] = [0, 470, 836, 470];
                }
            }
            unset($intro);
        }
        if ($this->mediaPresenter !== null) {
            foreach ($data['products'] as &$cardProduct) {
                $detail = $this->catalogue->product((int) $cardProduct['product_id']);
                $cardProduct['display_size'] = $this->mediaPresenter->sizeLabel($cardProduct);
                $cardProduct['card_pot_id'] = null;
                foreach ($detail['compatible_pots'] ?? [] as $pot) {
                    if (!empty($pot['buy_enabled']) && !empty($cardProduct['buy_enabled'])) {
                        $cardProduct['card_pot_id'] = (int) $pot['product_id'];
                        $cardProduct['card_price_minor'] = (int) $cardProduct['retail_price_minor'] + (int) $pot['retail_price_minor'];
                        break;
                    }
                }
                $cardProduct['thumbnail'] = $this->mediaPresenter->productThumbnail($cardProduct + ($detail ?? []), $detail['compatible_pots'] ?? []);
            }
            unset($cardProduct);
        }
        [$filterPanel, $queryFilters] = self::filterPresentation($data, $rentalDates);
        View::render('catalogue', $data + [
            'title' => 'Botanical catalogue', 'actor' => $actor, 'errors' => $errors,
            'filterPanel' => $filterPanel, 'queryFilters' => $queryFilters, 'pageSections' => [],
            'rentalMode' => $rentalMode, 'rentalDates' => $rentalDates,
        ], $status);
    }

    /** Prepare component inputs from service data; no filter rules or business calculations live in the view. */
    private static function filterPresentation(array $data, array $rentalDates = []): array
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        unset($filters['budget_minor'], $filters['page']);
        $queryFilters = array_filter($filters, static fn (mixed $value): bool => is_string($value) && $value !== '');
        foreach (['start_date', 'end_date'] as $dateKey) {
            $dateValue = $rentalDates[$dateKey] ?? '';
            if (is_string($dateValue) && $dateValue !== '') {
                $queryFilters[$dateKey] = $dateValue;
            }
        }
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
        $fields = [];

        $categoryOptions = [['value' => '', 'label' => 'All categories']];
        $categoryNames = [];
        foreach ($categories as $category) {
            if (!isset($category['category_id'], $category['name'])) {
                continue;
            }
            $value = (string) $category['category_id'];
            $name = (string) $category['name'];
            $categoryOptions[] = ['value' => $value, 'label' => $name];
            $categoryNames[$value] = $name;
        }
        $fields[] = ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'value' => (string) ($filters['category_id'] ?? ''), 'options' => $categoryOptions];

        $fieldLabels = [
            'kind' => 'Product type', 'mode' => 'Mode', 'size' => 'Size', 'colour' => 'Colour',
            'light_level' => 'Plant light', 'watering_level' => 'Plant watering', 'toxicity' => 'Plant toxicity',
        ];
        foreach ($fieldLabels as $key => $caption) {
            if (!isset($options[$key]) || !is_array($options[$key])) {
                continue;
            }
            $choices = [['value' => '', 'label' => match ($key) {
                'kind' => 'All types', 'mode' => 'Buy or rent', 'size' => 'Any size', 'colour' => 'Any colour',
                'light_level', 'watering_level', 'toxicity' => 'Any verified value', default => 'All',
            }]];
            $values = $key === 'size' ? array_keys(CollectionService::SIZE_FILTER_LABELS) : $options[$key];
            foreach ($values as $option) {
                if (!is_string($option) || $option === '') {
                    continue;
                }
                $label = match ($key) {
                    'mode' => $option === 'buy' ? 'Buy' : 'Rent',
                    'size' => CollectionService::SIZE_FILTER_LABELS[$option] ?? ucfirst(str_replace('_', ' ', $option)),
                    default => ucfirst(str_replace('_', ' ', $option)),
                };
                $choices[] = ['value' => $option, 'label' => $label];
            }
            $fields[] = ['name' => $key, 'label' => $caption, 'type' => 'select', 'value' => (string) ($filters[$key] ?? ''), 'options' => $choices];
        }
        if (array_key_exists('budget', $filters)) {
            $fields[] = ['name' => 'budget', 'label' => 'Maximum budget (ZAR)', 'type' => 'text', 'inputmode' => 'decimal', 'value' => (string) $filters['budget'], 'help' => 'Buy price or rental rate per day; up to two decimal places.', 'id' => 'budget'];
        }
        if (($filters['mode'] ?? '') === 'rent') {
            $fields[] = ['name' => 'start_date', 'label' => 'Rental start date', 'type' => 'date', 'value' => (string) ($rentalDates['start_date'] ?? ''), 'id' => 'filter-rental-start'];
            $fields[] = ['name' => 'end_date', 'label' => 'Rental end date', 'type' => 'date', 'value' => (string) ($rentalDates['end_date'] ?? ''), 'id' => 'filter-rental-end'];
        }

        $active = [];
        $chipLabels = [
            'category_id' => 'Category', 'kind' => 'Product type', 'mode' => 'Mode', 'size' => 'Size', 'colour' => 'Colour', 'budget' => 'Budget',
            'light_level' => 'Light', 'watering_level' => 'Watering', 'toxicity' => 'Toxicity', 'q' => 'Search',
        ];
        foreach ($queryFilters as $key => $value) {
            if (in_array($key, ['start_date', 'end_date', 'sort'], true)) {
                continue;
            }
            if ($key === 'category_id') {
                $displayValue = $categoryNames[$value] ?? $value;
            } elseif ($key === 'mode') {
                $displayValue = $value === 'buy' ? 'Buy' : 'Rent';
            } elseif ($key === 'budget') {
                $displayValue = '≤ R ' . $value . (($filters['mode'] ?? '') === 'rent' ? ' / day' : '');
            } elseif ($key === 'q') {
                $displayValue = $value;
            } else {
                $displayValue = ucfirst(str_replace('_', ' ', $value));
            }
            $remaining = $queryFilters;
            unset($remaining[$key]);
            $active[] = [
                'label' => ($chipLabels[$key] ?? 'Filter') . ': ' . $displayValue,
                'remove_href' => '/catalogue' . ($remaining === [] ? '' : '?' . http_build_query($remaining)),
            ];
        }
        if (($rentalDates['has_dates'] ?? false) === true) {
            $remaining = $queryFilters;
            unset($remaining['start_date'], $remaining['end_date']);
            $active[] = [
                'label' => 'Rental dates: ' . $rentalDates['start_date'] . ' to ' . $rentalDates['end_date'],
                'remove_href' => '/catalogue' . ($remaining === [] ? '' : '?' . http_build_query($remaining)),
            ];
        }

        return [[
            'action' => '/catalogue', 'legend' => 'Filter & sort', 'fields' => $fields,
            'active' => $active, 'submit_label' => 'Apply filters',
        ], $queryFilters];
    }

    /** Verified copy where present; otherwise a factual summary composed from stored fields. */
    private static function displayDescription(array $product): string
    {
        $description = (string) ($product['description'] ?? '');
        if ($description !== '') {
            return $description;
        }
        $parts = [];
        $size = (string) ($product['size_label'] ?? '');
        if ($size !== '' && !in_array(strtolower($size), ['unknown', 'standard'], true)) {
            $parts[] = $size;
        }
        foreach (['selling_unit', 'category_name'] as $key) {
            if ((string) ($product[$key] ?? '') !== '') {
                $parts[] = (string) $product[$key];
            }
        }
        $parts[] = match ([!empty($product['can_buy']), !empty($product['can_rent'])]) {
            [true, true] => 'Available to buy or rent',
            [true, false] => 'Available to buy',
            [false, true] => 'Available to rent',
            default => 'Currently unavailable',
        };
        return implode(' · ', $parts);
    }

    public function detail(mixed $id, ?array $actor = null, array $selection = []): void
    {
        $product = is_string($id) && ctype_digit($id) && strlen($id) <= 18
            ? $this->catalogue->product((int) $id) : null;
        if ($product === null) {
            View::render('error', ['title' => 'Product unavailable', 'message' => 'This product is unavailable. Explore the catalogue for something else.', 'actor' => $actor], 404);
            return;
        }

        $selectedVariant = null;
        foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
            if ((int) ($variant['product_id'] ?? 0) === (int) $product['product_id']) {
                $selectedVariant = $variant;
                break;
            }
        }

        $hasRetailPrice = (is_int($product['retail_price_minor'] ?? null)
                || is_string($product['retail_price_minor'] ?? null))
            && ctype_digit((string) $product['retail_price_minor']);
        $hasRentalPrice = (is_int($product['rental_daily_rate_minor'] ?? null)
                || is_string($product['rental_daily_rate_minor'] ?? null))
            && ctype_digit((string) $product['rental_daily_rate_minor']);
        $saleOnlyFlowerKinds = ['cut_flower', 'cut_foliage', 'floral_arrangement'];
        $product['selected_variant'] = $selectedVariant;
        $product['can_buy'] = (bool) ($product['buy_enabled'] ?? false) && $hasRetailPrice;
        $product['can_rent'] = (bool) ($product['rent_enabled'] ?? false) && $hasRentalPrice
            && !in_array($product['product_kind'] ?? '', $saleOnlyFlowerKinds, true);

        $plantChoices = ($product['product_kind'] ?? '') === 'plant'
            ? array_values(array_filter($this->catalogue->catalogue(['kind' => 'plant'])['products'],
                static fn (array $choice): bool => ($choice['product_kind'] ?? '') === 'plant'))
            : [];

        if (($product['product_kind'] ?? '') === 'plant') {
            $choicesById = [];
            foreach (array_merge([$product], $product['variants'] ?? [], $plantChoices) as $choice) {
                $choicesById[(int) $choice['product_id']] = $choice;
            }
            $plantChoices = array_values($choicesById);
        }

        $selectedPot = null;
        $pots = is_array($product['compatible_pots'] ?? null) ? $product['compatible_pots'] : [];
        $potRequest = $selection['pot_id'] ?? null;
        $retainRequest = $selection['retain_pot_id'] ?? null;
        if ($potRequest !== null && (!is_string($potRequest) || !ctype_digit($potRequest) || strlen($potRequest) > 18)) {
            View::render('error', ['title' => 'Pot unavailable', 'message' => 'Choose a pot available for this plant.', 'actor' => $actor], 422);
            return;
        }
        if ($potRequest !== null && $potRequest !== '0') {
            foreach ($pots as $pot) if ((string) $pot['product_id'] === $potRequest) $selectedPot = $pot;
            if ($selectedPot === null) {
                View::render('error', ['title' => 'Pot unavailable', 'message' => 'Choose a pot available for this plant.', 'actor' => $actor], 422);
                return;
            }
        } elseif ($potRequest === null && is_string($retainRequest) && ctype_digit($retainRequest) && strlen($retainRequest) <= 18) {
            $previous = $this->catalogue->product((int) $retainRequest);
            $familyId = $previous['family']['family_id'] ?? null;
            foreach ($pots as $pot) {
                if ((string) $pot['product_id'] === $retainRequest || ($familyId !== null && (int) ($pot['family_id'] ?? 0) === (int) $familyId)) {
                    $selectedPot = $pot;
                    break;
                }
            }
        } elseif ($potRequest === null && $retainRequest === null) {
            $selectedPot = $pots[0] ?? null;
        }
        $selectedPot ??= $pots[0] ?? null;
        if ($this->mediaPresenter !== null) {
            foreach ($pots as &$pot) {
                $pot['thumbnail'] = $this->mediaPresenter->productThumbnail($pot, []);
            }
            unset($pot);
        }
        $product['compatible_pots'] = $pots;
        if ($selectedPot !== null) {
            foreach ($pots as $pot) {
                if ((int) ($pot['product_id'] ?? 0) === (int) ($selectedPot['product_id'] ?? 0)) {
                    $selectedPot = $pot;
                    break;
                }
            }
        }
        $requiresPot = ($product['product_kind'] ?? '') === 'plant';
        $product['selected_pot'] = $selectedPot;
        $product['display_size'] = $this->mediaPresenter?->sizeLabel($product) ?? ($product['size_label'] ?? '');
        $product['display_description'] = self::displayDescription($product);
        foreach ($plantChoices as &$choice) $choice['display_size'] = $this->mediaPresenter?->sizeLabel($choice) ?? ($choice['size_label'] ?? '');
        unset($choice);
        usort($plantChoices, static function (array $left, array $right): int {
            $byName = strcasecmp($left['name'], $right['name']);
            if ($byName !== 0) return $byName;
            $sizes = ['Small' => 0, 'Medium' => 1, 'Large' => 2];
            return ($sizes[$left['display_size']] ?? 3) <=> ($sizes[$right['display_size']] ?? 3);
        });
        $product['selection_can_buy'] = $product['can_buy'] && (!$requiresPot || $selectedPot !== null) && ($selectedPot === null || !empty($selectedPot['buy_enabled']));
        $product['selection_can_rent'] = $product['can_rent'] && (!$requiresPot || $selectedPot !== null) && ($selectedPot === null || !empty($selectedPot['rent_enabled']));
        $product['selection_retail_price_minor'] = (int) ($product['retail_price_minor'] ?? 0) + (int) ($selectedPot['retail_price_minor'] ?? 0);
        $product['selection_rental_rate_minor'] = (int) ($product['rental_daily_rate_minor'] ?? 0) + (int) ($selectedPot['rental_daily_rate_minor'] ?? 0);
        $mediaGallery = $this->mediaPresenter?->productGallery($product, $selectedPot === null ? [] : [$selectedPot]);
        View::render('product', ['product' => $product, 'title' => $product['name'], 'actor' => $actor, 'mediaGallery' => $mediaGallery, 'plantChoices' => $plantChoices]);
    }
}
