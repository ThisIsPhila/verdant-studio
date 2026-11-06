<?php

declare(strict_types=1);

/** Synthetic editorial membership only; these entries assert no product care or safety facts. */
return [
    [
        'code' => 'desk-greenery',
        'name' => 'A greener desk',
        'description' => 'A synthetic demonstration edit of existing catalogue plants, planters and tools for a desk inspiration journey.',
        'products' => [
            ['PL-MON-S', 'Included in this synthetic desk inspiration edit.', 10],
            ['PL-ZZ', 'Included in this synthetic desk inspiration edit.', 20],
            ['POT-STONE-S', 'Included in this synthetic desk inspiration edit.', 30],
            ['POT-TERRA', 'Included in this synthetic desk inspiration edit.', 40],
            ['TOOL-CAN', 'Included in this synthetic desk inspiration edit.', 50],
        ],
        'compositions' => [],
    ],
    [
        'code' => 'green-events',
        'name' => 'Green events',
        'description' => 'A synthetic demonstration edit of existing statement plants and living-wall SKUs for event-space inspiration.',
        'products' => [
            ['VG-N', 'Included in this synthetic green-event inspiration edit.', 10],
            ['VG-M', 'Included in this synthetic green-event inspiration edit.', 20],
            ['VG-L', 'Included in this synthetic green-event inspiration edit.', 30],
            ['TREE-OLIVE', 'Included in this synthetic green-event inspiration edit.', 40],
            ['TREE-CHERRY', 'Included in this synthetic green-event inspiration edit.', 50],
            ['PL-BIRD', 'Included in this synthetic green-event inspiration edit.', 60],
            ['POT-CHAR', 'Included in this synthetic green-event inspiration edit.', 70],
        ],
        'compositions' => [],
    ],
    [
        'code' => 'floral-inspiration',
        'name' => 'Flowers for the moment',
        'description' => 'A synthetic demonstration edit of existing cut-flower, foliage and bouquet SKUs. Flowers are sold; they are not rentable.',
        'products' => [
            ['FL-ROSE', 'Included in this synthetic floral inspiration edit.', 10],
            ['FL-LILY', 'Included in this synthetic floral inspiration edit.', 20],
            ['FL-PROTEA', 'Included in this synthetic floral inspiration edit.', 30],
            ['FL-EUC', 'Included in this synthetic floral inspiration edit.', 40],
            ['BQ-WHITE', 'Included in this synthetic floral inspiration edit.', 50],
            ['BQ-WILD', 'Included in this synthetic floral inspiration edit.', 60],
            ['BQ-PASTEL', 'Included in this synthetic floral inspiration edit.', 70],
        ],
        'compositions' => [
            ['starter-bouquet-rental-container', 'A configured arrangement keeps flowers as purchased components and the planter as a separate rental component.', 10],
        ],
    ],
    [
        'code' => 'homes',
        'name' => 'Homes',
        'description' => 'A synthetic residential inspiration edit linking existing catalogue pieces and configured plant arrangements. Product care and compatibility come only from current catalogue and configuration data.',
        'products' => [
            ['TREE-OLIVE', 'Included in this synthetic Homes inspiration edit.', 10],
            ['PL-MON-S', 'Included in this synthetic Homes inspiration edit.', 20],
            ['PL-BIRD', 'Included in this synthetic Homes inspiration edit.', 30],
            ['POT-STONE-S', 'Included in this synthetic Homes inspiration edit.', 40],
            ['POT-CHAR', 'Included in this synthetic Homes inspiration edit.', 50],
        ],
        'compositions' => [
            ['starter-plant-compatible-pot', 'Configured planter choices are restricted by the composition compatibility rules.', 10],
            ['starter-custom-plant-arrangement', 'Choose from the definition’s explicitly allowed plant SKUs and check current eligibility.', 20],
            ['set-calm-corner', 'Named set for the Homes inspiration edit.', 30],
            ['set-entryway-greens', 'Named set for the Homes inspiration edit.', 40],
            ['set-living-room-layers', 'Named set for the Homes inspiration edit.', 50],
        ],
    ],
];
