<?php
$component = [
    'eyebrow' => 'Indoor plants · Outdoor gardens · Floral studio',
    'title' => $section['heading'],
    'title_lines' => $section['heading'] === 'Nature, curated by hand.' ? ['Nature, curated', 'by hand.'] : null,
    'tagline' => 'A greener, more beautiful tomorrow.',
    'annotation' => 'Plants for a brighter tomorrow.',
    'body' => $section['body'],
    'image' => [
        'src' => $section['media_path'],
        'alt' => $section['media_alt'],
        'crop' => $section['media_crop'] ?? null,
    ],
    'actions' => array_values(array_filter([
        $section['link_path'] === null ? null : [
            'label' => $section['link_label'],
            'href' => $section['link_path'],
        ],
        ['label' => 'Browse collections', 'href' => '/collections', 'variant' => 'secondary'],
    ])),
];
require __DIR__ . '/../components/hero.php';
