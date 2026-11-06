<section class="editorial-section">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Curated arrangements</p>
            <h2><?= $e($section['heading']) ?></h2>
            <p><?= $e($section['body']) ?></p>
        </div>
        <?php if ($section['link_path'] !== null): ?>
            <a class="text-link" href="<?= $e($section['link_path']) ?>">View all arrangements →</a>
        <?php endif ?>
    </div>
    <div class="collection-grid">
        <?php foreach ($section['definitions'] as $definition): ?>
            <?php
            $component = [
                'title' => $definition['name'],
                'price_minor' => $definition['price_minor'] ?? null,
                'description' => $definition['description'] ?? 'Thoughtfully paired plants and planters for your space.',
                'items' => array_map(static fn (array $item): string => $item['source_type'] === 'compatible_pot' ? 'Planter' : $item['label'], $definition['components']),
                'kind' => $label($definition['arrangement_kind']),
                'href' => '/configure?code=' . rawurlencode($definition['definition_code']),
                'action_label' => 'Shop arrangement',
                'image' => ['src' => $section['media_path'], 'alt' => $section['media_alt']],
            ];
            require __DIR__ . '/../components/arrangement-card.php';
            ?>
        <?php endforeach ?>
    </div>
    <?php if ($section['definitions'] === []): ?>
        <p>No arrangements are currently available. Explore individual products instead.</p>
    <?php endif ?>
</section>
