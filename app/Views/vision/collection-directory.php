<?php
$hero = [
    'image' => '/assets/vision/images/hero.jpg',
    'title' => 'Collections for spaces and occasions.',
    'body' => 'Explore plants and arrangements curated from the current catalogue for homes, workspaces and gatherings.',
    'eyebrow' => 'Curated botanical ideas',
    'id' => 'collections-title',
];
require __DIR__ . '/page-hero.php';
$collectionImages = [
    'homes' => '/assets/vision/images/homes.jpg',
    'green-events' => '/assets/vision/images/gatherings.jpg',
    'floral-inspiration' => '/assets/vision/images/p-wild-bouquet.jpg',
    'desk-greenery' => '/assets/vision/images/workspaces.jpg',
];
?>
<section class="vision-discovery-section vision-collection-directory" aria-labelledby="collections-heading">
    <div class="vision-discovery-heading"><div><p class="eyebrow">Find a starting point</p><h2 id="collections-heading">Browse collections.</h2><p>Each collection is a curated view over existing products and active arrangement definitions.</p></div></div>
    <?php if (empty($collections)): ?>
        <div class="vision-empty-editorial"><h3>No collections are available right now.</h3><p>Browse the full catalogue for current products.</p><a class="btn-solid" href="/catalogue">Browse the catalogue <span aria-hidden="true">→</span></a></div>
    <?php else: ?>
        <div class="vision-collection-grid">
            <?php foreach ($collections as $item): ?>
                <?php
                $collectionCode = (string) ($item['collection_code'] ?? '');
                $component = [
                    'title' => (string) ($item['name'] ?? 'Collection'),
                    'description' => (string) ($item['description'] ?? ''),
                    'eyebrow' => (int) ($item['product_count'] ?? 0) . ' current products',
                    'href' => '/collections/' . rawurlencode($collectionCode),
                    'link_label' => 'Explore collection',
                    'image' => ['src' => $collectionImages[$collectionCode] ?? '/assets/vision/images/hero-plants.jpg', 'alt' => (string) ($item['name'] ?? 'Botanical collection')],
                ];
                require __DIR__ . '/../components/collection-card.php';
                ?>
            <?php endforeach ?>
        </div>
    <?php endif ?>
    <p class="vision-homes-more"><a href="/catalogue">Browse full catalogue</a></p>
</section>
