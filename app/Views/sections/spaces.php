<section class="editorial-section spaces-section">
    <div class="collection-grid">
        <?php if ($section['collections'] !== []): ?>
            <?php foreach ($section['collections'] as $collection): ?>
                <?php
                $component = [
                    'title' => $collection['name'],
                    'description' => $collection['description'],
                    'href' => '/collections/' . rawurlencode($collection['collection_code']),
                    'image' => ['src' => $section['media_path'], 'alt' => $section['media_alt']],
                ];
                require __DIR__ . '/../components/collection-card.php';
                ?>
            <?php endforeach ?>
        <?php else: ?>
            <?php
            $component = [
                'eyebrow' => 'Homes',
                'title' => 'Bring nature home.',
                'description' => 'Plants, pots and styling for calmer, healthier living spaces.',
                'href' => '/collections/homes',
                'link_label' => 'Shop for homes',
                'image' => ['src' => $section['media_path'], 'alt' => $section['media_alt']],
            ];
            require __DIR__ . '/../components/collection-card.php';
            ?>
            <?php foreach ([
                ['name' => 'Workspaces', 'title' => 'Greener, brighter teams.', 'body' => 'Plants and rentals for offices and commercial spaces.'],
                ['name' => 'Gatherings', 'title' => 'Unforgettable, naturally.', 'body' => 'Plants and floral styling for events, weddings and special occasions.'],
            ] as $space): ?>
                <?php
                $component = [
                    'eyebrow' => $space['name'],
                    'title' => $space['title'],
                    'description' => $space['body'],
                    'href' => '/#' . strtolower($space['name']),
                    'link_label' => $space['name'] === 'Gatherings' ? 'Explore gatherings' : 'Shop for workspaces',
                    'pending' => true,
                ];
                require __DIR__ . '/../components/collection-card.php';
                ?>
            <?php endforeach ?>
        <?php endif ?>
    </div>
</section>
