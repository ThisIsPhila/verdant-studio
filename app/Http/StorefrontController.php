<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Catalogue\CollectionService;
use Verdant\Compositions\CompositionService;
use Verdant\Storefront\StorefrontSectionService;

/** Assemble editorial content over the existing discovery services. */
final class StorefrontController
{
    public function __construct(
        private readonly StorefrontSectionService $sections,
        private readonly CollectionService $collections,
        private readonly CompositionService $compositions,
        private readonly ?\Verdant\Catalogue\CatalogueService $catalogue = null,
        private readonly ?\Verdant\Media\MediaPresenter $media = null,
        private readonly ?\Verdant\Storefront\ArrangementDiscoveryService $arrangements = null,
    ) {
    }

    public function landing(?array $actor): void
    {
        $definitions = $this->compositions->activeDefinitions();
        $products = $this->collections->products([], null, 100);
        $rentals = $this->collections->browse(['mode' => 'rent'])['products'];
        $collections = $this->collections->collections();
        $rows = $this->sections->sectionsForPage('home');
        $arrangementSummaries = [];
        if (in_array('arrangement_grid', array_column($rows, 'section_type'), true)) {
            foreach ($this->arrangements?->browse([])['arrangements'] ?? [] as $summary) {
                $arrangementSummaries[$summary['definition_code']] = $summary;
            }
        }
        $thumbnails = [];
        $details = [];
        foreach ($rows as &$row) {
            if ($row['section_type'] === 'arrangement_grid') {
                $row['definitions'] = $row['arrangement_id'] === null
                    ? array_slice($definitions, 0, 3)
                    : array_values(array_filter($definitions,
                        static fn (array $item): bool => (int) $item['definition_id'] === (int) $row['arrangement_id']));
                foreach ($row['definitions'] as &$definition) {
                    $definition['price_minor'] = $arrangementSummaries[$definition['definition_code']]['estimated_buy_floor_minor'] ?? null;
                }
                unset($definition);
            }
            if ($row['section_type'] === 'collection') {
                $row['collections'] = $row['collection_id'] === null ? [] : array_values(array_filter($collections,
                    static fn (array $item): bool => (int) $item['collection_id'] === (int) $row['collection_id']));
            }
            if (in_array($row['section_type'], ['product_grid', 'rental_collection'], true)) {
                $rentalSection = $row['section_type'] === 'rental_collection';
                $items = $rentalSection ? $rentals : $products;
                if (!$rentalSection && $row['collection_id'] !== null) {
                    foreach ($collections as $collection) {
                        if ((int) $collection['collection_id'] === (int) $row['collection_id']) {
                            $items = $this->collections->browse([], $collection['collection_code'])['products'];
                            break;
                        }
                    }
                }
                $items = $rentalSection ? \Verdant\Storefront\VisionLandingPresenter::featured($items, 4, ['olive tree', 'bird of paradise', 'fiddle leaf fig', 'monstera']) : \Verdant\Storefront\VisionLandingPresenter::featured($items);
                foreach ($items as &$item) {
                    $id = (int) $item['product_id'];
                    if (!array_key_exists($id, $thumbnails)) {
                        $detail = $details[$id] ??= $this->catalogue?->product($id);
                        $thumbnails[$id] = $this->media?->productThumbnail($item + ($detail ?? []), $detail['compatible_pots'] ?? []);
                    }
                    $item['thumbnail'] = $thumbnails[$id];
                    $item['vision_image'] = \Verdant\Storefront\VisionLandingPresenter::image($item);
                    $detail = $details[$id] ?? null;
                    $item['card_pot_id'] = null;
                    foreach ($detail['compatible_pots'] ?? [] as $cardPot) {
                        if (!empty($cardPot['buy_enabled'])) {
                            $item['card_pot_id'] = (int) $cardPot['product_id'];
                            $item['card_price_minor'] = (int) $item['retail_price_minor'] + (int) $cardPot['retail_price_minor'];
                            break;
                        }
                    }
                }
                unset($item);
                $row[$rentalSection ? 'rentals' : 'products'] = $items;
            }
            if ($row['section_type'] === 'configuration_entry') {
                $row['pair_options'] = [];
                foreach ($products as $candidate) {
                    if (($candidate['product_kind'] ?? '') !== 'plant') {
                        continue;
                    }
                    $id = (int) $candidate['product_id'];
                    $detail = $details[$id] ??= $this->catalogue?->product($id);
                    $pots = array_values(array_filter($detail['compatible_pots'] ?? [],
                        static fn (array $pot): bool => !empty($pot['buy_enabled'])));
                    if ($pots === [] || empty($candidate['buy_enabled'])) {
                        continue;
                    }
                    $row['pair_options'][] = [
                        'id' => $id,
                        'name' => $candidate['name'],
                        'size' => $this->media?->sizeLabel($candidate) ?? '',
                        'family' => (int) ($detail['family']['family_id'] ?? 0),
                        'price' => (int) ($candidate['retail_price_minor'] ?? 0),
                        'thumbnail' => $this->media?->productThumbnail($candidate, [$pots[0]]),
                        'image_path' => $candidate['image_path'] ?? null,
                        'pots' => array_map(fn (array $pot): array => [
                            'id' => (int) $pot['product_id'],
                            'name' => $pot['name'],
                            'family' => (int) ($pot['family_id'] ?? 0),
                            'price' => (int) ($pot['retail_price_minor'] ?? 0),
                            'thumbnail' => $this->media?->productThumbnail($pot),
                            'image_path' => $pot['image_path'] ?? null,
                        ], $pots),
                    ];
                    if (count($row['pair_options']) === 1) {
                        $row['pair_gallery'] = $this->media?->productGallery($candidate, [$pots[0]]);
                    }
                }
            }
            if ($row['section_type'] === 'hero' && $row['media_path'] === '/assets/images/botanical.svg') {
                $row['media_path'] = '/assets/images/product-media/hero-scenes-v02.png';
                $row['media_alt'] = 'Monstera in a stone planter in warm sunlight';
                $row['media_crop'] = [0, 0, 836, 470];
            }
        }
        unset($row);
        View::render('landing', ['title' => 'Nature, curated by hand', 'actor' => $actor, 'pageSections' => $rows]);
    }
}
