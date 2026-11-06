<?php

declare(strict_types=1);

/** Synthetic allowlists; no compatibility or product facts are inferred here.
 * Retired: starter-potted-plant and starter-custom-plant-arrangement name
 * legacy SKUs (PL-FIG, PL-BIRD) absent from the starter catalogue. */
return [
    [
        'code' => 'starter-plant-compatible-pot',
        'name' => 'Monstera with a compatible planter',
        'arrangement_kind' => 'plant_arrangement',
        'strategy' => 'fixed',
        'components' => [
            [
                'key' => 'plant', 'label' => 'Monstera plant', 'role' => 'plant', 'source_type' => 'family',
                'family_code' => 'starter-monstera', 'required' => 1, 'min_selections' => 1, 'max_selections' => 1,
                'min_quantity' => 1, 'max_quantity' => 1, 'mode' => 'either', 'sort' => 10,
            ],
            [
                'key' => 'pot', 'label' => 'Explicitly compatible planter', 'role' => 'pot', 'source_type' => 'compatible_pot',
                'anchor_key' => 'plant', 'required' => 1, 'min_selections' => 1, 'max_selections' => 1,
                'min_quantity' => 1, 'max_quantity' => 1, 'mode' => 'either', 'sort' => 20,
            ],
        ],
    ],
    [
        'code' => 'starter-finished-living-wall',
        'name' => 'Stocked living wall panel',
        'arrangement_kind' => 'plant_arrangement',
        'strategy' => 'finished_stock',
        'finished_product_sku' => 'VG-N',
        'components' => [],
    ],
    [
        'code' => 'starter-bouquet-rental-container',
        'name' => 'Seasonal flower stems with a rented planter',
        'arrangement_kind' => 'floral_arrangement',
        'strategy' => 'custom',
        'components' => [
            [
                'key' => 'flower', 'label' => 'Flower stems', 'role' => 'flower', 'source_type' => 'product',
                'product_skus' => ['FL-ROSE', 'FL-LILY', 'FL-PROTEA'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 3, 'min_quantity' => 1, 'max_quantity' => 10,
                'mode' => 'sale', 'sort' => 10,
            ],
            [
                'key' => 'foliage', 'label' => 'Optional cut foliage', 'role' => 'foliage', 'source_type' => 'product',
                'product_skus' => ['FL-EUC'], 'required' => 0,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 4,
                'mode' => 'sale', 'sort' => 20,
            ],
            [
                'key' => 'container', 'label' => 'Rented planter', 'role' => 'container', 'source_type' => 'family',
                'family_code' => 'starter-stone-planter', 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'rent', 'sort' => 30,
            ],
        ],
    ],
    [
        'code' => 'set-calm-corner',
        'name' => 'Calm Corner',
        'arrangement_kind' => 'plant_arrangement',
        'strategy' => 'custom',
        'components' => [
            [
                'key' => 'tree', 'label' => 'Olive tree', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['TREE-OLIVE'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 10,
            ],
            [
                'key' => 'plant', 'label' => 'Monstera', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-MON-M'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 20,
            ],
            [
                'key' => 'accent', 'label' => 'ZZ plant', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-ZZ'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 30,
            ],
            [
                'key' => 'pots', 'label' => 'Stone planters', 'role' => 'pot', 'source_type' => 'product',
                'product_skus' => ['POT-STONE-S', 'POT-STONE-L'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 2, 'max_quantity' => 2,
                'mode' => 'either', 'sort' => 40,
            ],
        ],
    ],
    [
        'code' => 'set-entryway-greens',
        'name' => 'Entryway Greens',
        'arrangement_kind' => 'plant_arrangement',
        'strategy' => 'custom',
        'components' => [
            [
                'key' => 'feature', 'label' => 'Bird of paradise', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-BIRD-M'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 10,
            ],
            [
                'key' => 'accent', 'label' => 'ZZ plant', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-ZZ'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 20,
            ],
            [
                'key' => 'plant', 'label' => 'Fiddle leaf fig', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-FIG-M'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 30,
            ],
            [
                'key' => 'pots', 'label' => 'Terracotta planters', 'role' => 'pot', 'source_type' => 'product',
                'product_skus' => ['POT-TERRA'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 2, 'max_quantity' => 2,
                'mode' => 'either', 'sort' => 40,
            ],
        ],
    ],
    [
        'code' => 'set-living-room-layers',
        'name' => 'Living-room Layers',
        'arrangement_kind' => 'plant_arrangement',
        'strategy' => 'custom',
        'components' => [
            [
                'key' => 'tall', 'label' => 'Fiddle leaf fig', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-FIG-L'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 10,
            ],
            [
                'key' => 'plant', 'label' => 'Monstera', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-MON-L'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 20,
            ],
            [
                'key' => 'accent', 'label' => 'ZZ plant', 'role' => 'plant', 'source_type' => 'product',
                'product_skus' => ['PL-ZZ'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
                'mode' => 'either', 'sort' => 30,
            ],
            [
                'key' => 'pots', 'label' => 'Stone planters', 'role' => 'pot', 'source_type' => 'product',
                'product_skus' => ['POT-STONE-S', 'POT-STONE-L'], 'required' => 1,
                'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 3, 'max_quantity' => 3,
                'mode' => 'either', 'sort' => 40,
            ],
        ],
    ],
];
