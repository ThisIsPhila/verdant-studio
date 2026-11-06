<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Compositions\CompositionService;
use Verdant\Media\MediaPresenter;
use Verdant\Storefront\ArrangementDiscoveryService;
use Verdant\Support\CommerceSummary;
use Verdant\Support\ValidationException;

final class CompositionController
{
    public function __construct(
        private readonly CompositionService $compositions,
        private readonly ?MediaPresenter $mediaPresenter = null,
        private readonly ?ArrangementDiscoveryService $arrangementOffers = null,
    )
    {
    }

    public function index(array $query, ?array $actor): void
    {
        $definitions = $this->compositions->activeDefinitions();
        $selection = [];
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $savedId = self::positiveId($query['saved_id'] ?? null);
        if ($code === '' && $savedId === null) {
            foreach ($definitions as $available) {
                if ($available['definition_code'] === 'starter-potted-plant') { $code = $available['definition_code']; break; }
            }
        }

        if ($savedId !== null) {
            $saved = $actor === null ? null : $this->compositions->saved((int) $actor['customer_id'], $savedId);
            if ($saved === null) {
                View::render('error', ['title' => 'Arrangement unavailable', 'message' => 'This saved arrangement is unavailable.', 'actor' => $actor], 404);
                return;
            }
            $code = $saved['definition_code_snapshot'];
            $selection = $saved['components'];
        }
        $definition = $code === '' ? null : $this->compositions->definition($code);
        if ($code !== '' && $definition === null) {
            View::render('error', ['title' => 'Arrangement unavailable', 'message' => 'This arrangement is no longer available.', 'actor' => $actor], 404);
            return;
        }
        $plantId = self::positiveId($query['plant_id'] ?? null);
        if ($savedId === null && $plantId !== null && in_array($code, ['starter-plant-compatible-pot', 'starter-potted-plant'], true) && $definition !== null) {
            foreach ($definition['components'] as $component) {
                if ($component['source_type'] === 'compatible_pot') continue;
                foreach ($component['product_options'] as $option) {
                    if ((int) $option['product_id'] !== $plantId || ($option['product_kind'] ?? '') !== 'plant') continue;
                    $mode = $option['buy_enabled'] ? 'sale' : ($option['rent_enabled'] ? 'rent' : '');
                    if ($mode === '' || ($component['permitted_mode'] === 'sale' && $mode !== 'sale')
                        || ($component['permitted_mode'] === 'rent' && $mode !== 'rent')) continue;
                    $selection = [['component_key' => $component['component_key'], 'product_id' => $plantId,
                        'quantity' => (int) $component['min_quantity'], 'mode' => $mode]];
                    break 2;
                }
            }
        }
        if ($savedId === null && $selection !== [] && $definition !== null && in_array($code, ['starter-plant-compatible-pot', 'starter-potted-plant'], true)) {
            $requestedMode = ($query['mode'] ?? '') === 'rent' ? 'rent' : $selection[0]['mode'];
            if ($requestedMode === 'rent') {
                foreach ($definition['components'] as $component) foreach ($component['product_options'] ?? [] as $option) {
                    if ((int) $option['product_id'] === (int) $selection[0]['product_id'] && !empty($option['rent_enabled'])) $selection[0]['mode'] = 'rent';
                }
            }
            $potId = self::positiveId($query['pot_id'] ?? null);
            if ($potId !== null) {
                foreach ($definition['components'] as $component) {
                    if ($component['source_type'] !== 'compatible_pot') continue;
                    foreach ($this->compositions->compatiblePots($code, $component['component_key'], (int) $selection[0]['product_id']) as $pot) {
                        if ((int) $pot['product_id'] !== $potId || empty($pot[$selection[0]['mode'] === 'rent' ? 'rent_enabled' : 'buy_enabled'])) continue;
                        $selection[] = ['component_key' => $component['component_key'], 'product_id' => $potId,
                            'quantity' => (int) $component['min_quantity'], 'mode' => $selection[0]['mode']];
                        break 2;
                    }
                }
            }
        }
        if ($selection === [] && $definition !== null) {
            $selection = $this->defaultSelection($definition);
        }
        $this->render($actor, $definitions, $definition, $selection, [], null);
    }

    public function options(array $input, ?array $actor): void
    {
        $code = self::string($input['code'] ?? null);
        $selection = [];
        try {
            $selection = $this->selection($input['selection'] ?? null);
            $this->render($actor, $this->compositions->activeDefinitions(), $this->compositions->definition($code), $selection, [], null, 200, true);
        } catch (\InvalidArgumentException $error) {
            $this->render($actor, $this->compositions->activeDefinitions(), $this->compositions->definition($code), $selection, ['composition' => $error->getMessage()], null, 422);
        }
    }

    public function quote(array $input, ?array $actor): void
    {
        $code = self::string($input['code'] ?? null);
        $selection = [];
        try {
            $selection = $this->selection($input['selection'] ?? null);
            $quote = $this->compositions->quote($code, $selection);
            $definition = $quote['definition'];
            $this->render($actor, $this->compositions->activeDefinitions(), $definition, $selection, [], $quote);
        } catch (\InvalidArgumentException|\DomainException|\OverflowException $error) {
            $definitions = $this->compositions->activeDefinitions();
            $definition = $this->compositions->definition($code);
            $this->render($actor, $definitions, $definition, $selection, ['composition' => $error->getMessage()], null, 422);
        }
    }

    public function save(array $input, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $code = self::string($input['code'] ?? null);
        $selection = [];
        $name = trim(self::string($input['name'] ?? null));
        try {
            $selection = $this->selection($input['selection'] ?? null);
            $savedId = $this->compositions->save((int) $actor['customer_id'], $name, $code, $selection);
            AuthController::redirect('/compositions?saved=' . $savedId);
        } catch (\InvalidArgumentException|\DomainException|\OverflowException $error) {
            $this->render($actor, $this->compositions->activeDefinitions(), $this->compositions->definition($code),
                $selection, ['composition' => $error->getMessage()], null, 422);
        }
    }

    public function prepare(array $input, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $code = self::string($input['code'] ?? null);
        $selection = [];
        try {
            $selection = $this->selection($input['selection'] ?? null);
            $key = hash('sha256', $code . json_encode($selection, JSON_THROW_ON_ERROR));
            $_SESSION['_composition_checkout_keys'][$key] ??= bin2hex(random_bytes(24));
            $checkout = $this->compositions->createPending(
                (int) $actor['customer_id'], $code, $selection, $_SESSION['_composition_checkout_keys'][$key],
            );
            AuthController::redirect('/compositions/checkout?id=' . $checkout['composition_checkout_id']);
        } catch (\InvalidArgumentException|\DomainException|\OverflowException $error) {
            $this->render($actor, $this->compositions->activeDefinitions(), $this->compositions->definition($code),
                $selection, ['composition' => $error->getMessage()], null, 422);
        }
    }

    public function checkout(array $query, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $id = self::positiveId($query['id'] ?? null);
        $checkout = $id === null ? null : $this->compositions->checkout((int) $actor['customer_id'], $id);
        if ($checkout === null) {
            View::render('error', ['title' => 'Arrangement unavailable', 'actor' => $actor, 'message' => 'This arrangement checkout is unavailable.'], 404);
            return;
        }
        View::render('composition-pending', ['title' => 'Arrangement checkout', 'actor' => $actor, 'checkout' => $checkout,
            'summary' => CommerceSummary::fromCompositionCheckout($checkout)]);
    }

    public function saved(array $actor): void
    {
        View::render('saved-compositions', [
            'title' => 'Saved arrangements', 'actor' => $actor,
            'saved' => $this->compositions->savedList((int) $actor['customer_id']),
            'checkouts' => $this->compositions->checkouts((int) $actor['customer_id']),
        ]);
    }

    public function archiveSaved(array $input, array $actor): void
    {
        $id = self::positiveId($input['saved_composition_id'] ?? null);
        if ($id === null || !$this->compositions->archiveSaved((int) $actor['customer_id'], $id)) {
            View::render('error', ['title' => 'Arrangement unavailable', 'message' => 'This saved arrangement is unavailable.', 'actor' => $actor], 404);
            return;
        }
        AuthController::redirect('/compositions/saved');
    }

    /**
     * First-load defaults: the first available option of every required
     * component, so the builder opens with a complete selection, a quote
     * and its preview instead of an empty form. Returns no selection when
     * any required component has nothing available.
     */
    private function defaultSelection(array $definition): array
    {
        if (($definition['strategy'] ?? '') === 'finished_stock') {
            return [];
        }
        $selection = [];
        $chosenByKey = [];
        foreach ($definition['components'] as $component) {
            if (empty($component['required'])) {
                continue;
            }
            $permitted = (string) ($component['permitted_mode'] ?? 'either');
            if (($component['source_type'] ?? '') === 'compatible_pot') {
                $anchor = null;
                foreach ($definition['components'] as $candidate) {
                    if ((int) ($candidate['component_id'] ?? 0) === (int) ($component['compatibility_anchor_component_id'] ?? 0)) {
                        $anchor = $candidate;
                        break;
                    }
                }
                $anchorChoice = $anchor === null ? null : ($chosenByKey[$anchor['component_key']] ?? null);
                if ($anchorChoice === null) {
                    return [];
                }
                $mode = (string) $anchorChoice['mode'];
                foreach ($this->compositions->compatiblePots(
                    (string) $definition['definition_code'], (string) $component['component_key'], (int) $anchorChoice['product_id'],
                ) as $pot) {
                    if (empty($pot[$mode === 'rent' ? 'rent_enabled' : 'buy_enabled'])) {
                        continue;
                    }
                    $row = ['component_key' => (string) $component['component_key'], 'product_id' => (int) $pot['product_id'],
                        'quantity' => (int) $component['min_quantity'], 'mode' => $mode];
                    $selection[] = $row;
                    $chosenByKey[$row['component_key']] = $row;
                    break;
                }
                if (!isset($chosenByKey[$component['component_key']])) {
                    return [];
                }
                continue;
            }
            $picked = null;
            foreach ($component['product_options'] ?? [] as $option) {
                $mode = match (true) {
                    $permitted === 'sale' => !empty($option['buy_enabled']) ? 'sale' : '',
                    $permitted === 'rent' => !empty($option['rent_enabled']) ? 'rent' : '',
                    default => !empty($option['buy_enabled']) ? 'sale' : (!empty($option['rent_enabled']) ? 'rent' : ''),
                };
                if ($mode === '') {
                    continue;
                }
                $picked = ['component_key' => (string) $component['component_key'], 'product_id' => (int) $option['product_id'],
                    'quantity' => (int) $component['min_quantity'], 'mode' => $mode];
                break;
            }
            if ($picked === null) {
                return [];
            }
            $selection[] = $picked;
            $chosenByKey[$picked['component_key']] = $picked;
        }
        return $selection;
    }

    private function render(?array $actor, array $definitions, ?array $definition, array $selection, array $errors, ?array $quote, int $status = 200, bool $refreshCompatibility = false): void
    {
        $plantByComponent = [];
        foreach ($selection as $row) {
            if ($row['mode'] === 'sale' || $row['mode'] === 'rent') {
                $plantByComponent[$row['component_key']] = (int) $row['product_id'];
            }
        }
        $compatible = [];
        if ($definition !== null) {
            foreach ($definition['components'] as $component) {
                if ($component['source_type'] !== 'compatible_pot') {
                    continue;
                }
                $anchor = null;
                foreach ($definition['components'] as $candidate) {
                    if ((int) $candidate['component_id'] === (int) $component['compatibility_anchor_component_id']) {
                        $anchor = $candidate['component_key'];
                        break;
                    }
                }
                $compatible[$component['component_key']] = $anchor === null || !isset($plantByComponent[$anchor])
                    ? [] : $this->compositions->compatiblePots(
                        (string) $definition['definition_code'], (string) $component['component_key'], $plantByComponent[$anchor],
                    );
            }
        }
        if ($definition !== null && $this->mediaPresenter !== null) {
            foreach ($definition['components'] as &$presentationComponent) {
                foreach ($presentationComponent['product_options'] as &$option) {
                    $option['display_size'] = $this->mediaPresenter->sizeLabel($option);
                    $option['thumbnail'] = $this->mediaPresenter->productThumbnail($option, []);
                }
                unset($option);
            }
            unset($presentationComponent);
            foreach ($compatible as &$potOptions) {
                foreach ($potOptions as &$option) $option['thumbnail'] = $this->mediaPresenter->productThumbnail($option, []);
                unset($option);
            }
            unset($potOptions);
        }
        $mediaNotice = '';
        if ($refreshCompatibility && $definition !== null) {
            [$selection, $mediaNotice] = $this->retainCompatiblePotMaterial($definition, $selection, $compatible);
        }

        $mediaProduct = null;
        $mediaGallery = null;
        if ($this->mediaPresenter !== null && $definition !== null && is_array($definition['components'] ?? null)) {
            $selectionByKey = [];
            foreach ($selection as $row) $selectionByKey[$row['component_key']][] = $row;
            foreach ($definition['components'] as $potComponent) {
                if ($potComponent['source_type'] !== 'compatible_pot') continue;
                $plantComponent = null;
                foreach ($definition['components'] as $candidate) {
                    if ((int) $candidate['component_id'] === (int) $potComponent['compatibility_anchor_component_id']) {
                        $plantComponent = $candidate;
                        break;
                    }
                }
                $plantChoice = $plantComponent === null ? null : ($selectionByKey[$plantComponent['component_key']][0] ?? null);
                $potChoice = $selectionByKey[$potComponent['component_key']][0] ?? null;
                if ($plantChoice === null || $potChoice === null) continue;
                foreach ($plantComponent['product_options'] as $option) {
                    if ((int) $option['product_id'] === (int) $plantChoice['product_id'] && $option['product_kind'] === 'plant') {
                        $mediaProduct = $option;
                        break;
                    }
                }
                if ($mediaProduct === null) continue;
                $mediaGallery = $this->mediaPresenter->configurationGallery(
                    $mediaProduct,
                    $compatible[$potComponent['component_key']] ?? [],
                    $potChoice['product_id'],
                );
                break;
            }
            if ($mediaProduct === null) {
                // Arrangements without a compatible-pot component still show
                // a preview: their first selected plant where one exists,
                // otherwise their first selected product.
                foreach ([true, false] as $plantsOnly) {
                    foreach ($selection as $row) {
                        foreach ($definition['components'] as $component) {
                            if (($component['component_key'] ?? null) !== ($row['component_key'] ?? null)) continue;
                            foreach ($component['product_options'] ?? [] as $option) {
                                if ((int) ($option['product_id'] ?? 0) === (int) ($row['product_id'] ?? 0)
                                    && (!$plantsOnly || ($option['product_kind'] ?? '') === 'plant')) {
                                    $mediaProduct = $option;
                                    break 4;
                                }
                            }
                        }
                    }
                }
                if ($mediaProduct !== null) {
                    $mediaGallery = $this->mediaPresenter->productGallery($mediaProduct, [], true);
                }
            }
        }
        if ($quote === null && $selection !== [] && $errors === [] && $definition !== null) {
            try { $quote = $this->compositions->quote((string) $definition['definition_code'], $selection); }
            catch (\InvalidArgumentException|\DomainException|\OverflowException) { /* Incomplete choices remain editable; Get estimate reports field errors. */ }
        }
        $arrangementOffers = [];
        if ($definition === null && $this->arrangementOffers !== null) {
            try {
                $arrangementOffers = $this->arrangementOffers->browse([])['arrangements'] ?? [];
            } catch (ValidationException) {
                $arrangementOffers = [];
            }
        }
        View::render('compositions', [
            'title' => 'Build an arrangement', 'actor' => $actor, 'definitions' => $definitions,
            'arrangementOffers' => $arrangementOffers,
            'definition' => $definition, 'selection' => $selection, 'compatible' => $compatible,
            'finishedProduct' => $definition !== null && $definition['strategy'] === 'finished_stock'
                ? $this->compositions->finishedProductForDefinition((string) $definition['definition_code']) : null,
            'quote' => $quote, 'summary' => $quote === null ? null : CommerceSummary::fromQuote($quote),
            'mediaProduct' => $mediaProduct, 'mediaGallery' => $mediaGallery, 'mediaNotice' => $mediaNotice,
            'errors' => $errors, 'savedNotice' => self::positiveId($_GET['saved'] ?? null) !== null,
        ], $status);
    }

    /** Preserve a pot finish only by selecting a matching item from the newly server-resolved eligible list. */
    private function retainCompatiblePotMaterial(array $definition, array $selection, array $compatible): array
    {
        $notice = '';
        foreach ($definition['components'] as $potComponent) {
            if ($potComponent['source_type'] !== 'compatible_pot') continue;
            $oldChoiceIndex = null;
            foreach ($selection as $index => $row) {
                if ($row['component_key'] === $potComponent['component_key']) { $oldChoiceIndex = $index; break; }
            }
            if ($oldChoiceIndex === null) continue;
            $oldId = (int) $selection[$oldChoiceIndex]['product_id'];
            $eligible = $compatible[$potComponent['component_key']] ?? [];
            foreach ($eligible as $pot) {
                if ((int) $pot['product_id'] === $oldId) continue 2;
            }
            $previous = $this->compositions->activePotForPresentation($oldId);
            $match = null;
            if ($previous !== null) {
                foreach ($eligible as $pot) {
                    $previousFamily = self::potMaterialKey($previous);
                    if ($previousFamily !== null && $previousFamily === self::potMaterialKey($pot)) {
                        $match = $pot;
                        break;
                    }
                }
            }
            if ($match === null && !empty($potComponent['required'])) {
                $mode = $selection[$oldChoiceIndex]['mode'];
                foreach ($eligible as $pot) {
                    if (!empty($pot[$mode === 'rent' ? 'rent_enabled' : 'buy_enabled'])) {
                        $match = $pot;
                        $notice = 'The pot was updated to one compatible with your selected plant.';
                        break;
                    }
                }
            }
            if ($match !== null) {
                $selection[$oldChoiceIndex]['product_id'] = (int) $match['product_id'];
                continue;
            }
            array_splice($selection, $oldChoiceIndex, 1);
            $notice = 'The previous pot was not compatible with this plant, so it was cleared. Choose one of the compatible pots now shown.';
        }
        return [$selection, $notice];
    }

    private static function potMaterialKey(array $pot): ?string
    {
        // A family is the current explicit catalogue grouping for matching material across pot size variants.
        $familyId = (int) ($pot['family_id'] ?? 0);
        return $familyId > 0 ? 'family:' . $familyId : null;
    }

    /** @return list<array{component_key:string,product_id:int,quantity:int,mode:string,start_date?:string,end_date_inclusive?:string}> */
    private function selection(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $key => $row) {
            if (!is_array($row) || !is_string($row['component_key'] ?? null)) {
                continue;
            }
            $productRaw = $row['product_id'] ?? '';
            if ($productRaw === '' || $productRaw === null) {
                continue;
            }
            $productId = self::positiveId($productRaw);
            $quantity = self::positiveId($row['quantity'] ?? null);
            if ($productId === null || $quantity === null || $quantity > 10000 || !in_array($row['mode'] ?? null, ['sale', 'rent'], true)) {
                throw new \InvalidArgumentException('Choose a valid product, mode and positive quantity for each component.');
            }
            $entry = [
                'component_key' => $row['component_key'], 'product_id' => $productId,
                'quantity' => $quantity, 'mode' => $row['mode'],
            ];
            if ($entry['mode'] === 'rent') {
                $entry['start_date'] = self::string($row['start_date'] ?? null);
                $entry['end_date_inclusive'] = self::string($row['end_date_inclusive'] ?? null);
            }
            $rows[] = $entry;
        }
        return $rows;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function positiveId(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0
            ? (int) $value : (is_int($value) && $value > 0 ? $value : null);
    }
}
