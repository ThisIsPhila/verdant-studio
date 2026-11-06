<?php

declare(strict_types=1);

/**
 * Reviewed local media registry for Phase 014.
 * Coordinates refer to native source pixels. Geometry is illustrative and must
 * not be used as a buyer-facing measurement until confirmed by nursery stock.
 */
return [
    'version' => 1,
    'scene' => ['width' => 800, 'height' => 1000, 'ground_y' => 900, 'ratio' => '4:5'],
    'environments' => [
        ['id' => 'bg-product-cream-v01', 'name' => 'Warm cream product background', 'kind' => 'background', 'path' => '/assets/images/product-media/BG-PRODUCT-CREAM-v01.svg', 'source' => 'BG-PRODUCT-CREAM-v01.svg', 'source_width' => 800, 'source_height' => 1000, 'crop' => [0, 0, 800, 1000], 'ground' => [0.5, 0.9], 'version' => 1, 'provenance' => 'Owner-requested clear warm-cream solid background, authored locally to match the approved product imagery; no image-generation service used.', 'quality' => 'solid color background'],
        ['id' => 'bg-individual-stone-v01', 'kind' => 'background', 'path' => '/assets/images/product-media/BG-INDIVIDUAL-STONE-v01.png', 'source' => 'BG-INDIVIDUAL-STONE-v01.png', 'source_width' => 1122, 'source_height' => 1402, 'crop' => [0, 0, 1122, 1402], 'ground' => [0.5, 0.9], 'version' => 1, 'provenance' => 'Generated from selected Figma product page reference', 'quality' => 'native closest-zoom review pending'],
        ['id' => 'bg-individual-homes-v01', 'kind' => 'background', 'path' => '/assets/images/product-media/BG-INDIVIDUAL-HOMES-v01.png', 'source' => 'BG-INDIVIDUAL-HOMES-v01.png', 'source_width' => 1122, 'source_height' => 1402, 'crop' => [0, 0, 1122, 1402], 'ground' => [0.5, 0.9], 'version' => 1, 'provenance' => 'Generated from selected Figma Homes page reference', 'quality' => 'native closest-zoom review pending'],
        ['id' => 'bg-individual-entry-v01', 'kind' => 'background', 'path' => '/assets/images/product-media/BG-INDIVIDUAL-ENTRY-v01.png', 'source' => 'BG-INDIVIDUAL-ENTRY-v01.png', 'source_width' => 1122, 'source_height' => 1402, 'crop' => [0, 0, 1122, 1402], 'ground' => [0.5, 0.9], 'version' => 1, 'provenance' => 'Generated from selected Figma configuration page reference', 'quality' => 'native closest-zoom review pending'],
    ],
    'plants' => [
        ['id'=>'plant-monstera-small-v01','name'=>'Monstera','profile'=>'small','path'=>'/assets/images/product-media/source/PL-S-01-v01.png','crop'=>[0,0,561,701],'root'=>[292,521],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-S-01-v01.png cell 1; generated asset sheet and crop recipe retained in asset catalogue','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-zz-small-v01','name'=>'Zamioculcas','profile'=>'small','path'=>'/assets/images/product-media/source/PL-S-01-v01.png','crop'=>[561,0,561,701],'root'=>[286,526],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-S-01-v01.png cell 2','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-box-topiary-small-v01','name'=>'Box topiary','profile'=>'small','path'=>'/assets/images/product-media/source/PL-S-01-v01.png','crop'=>[0,701,561,701],'root'=>[289,507],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-S-01-v01.png cell 3','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-fiddle-fig-medium-v01','name'=>'Fiddle leaf fig','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-01-v01.png','crop'=>[0,0,561,701],'root'=>[287,559],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-01-v01.png cell 1','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-bird-paradise-medium-v01','name'=>'Bird of paradise','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-01-v01.png','crop'=>[561,0,561,701],'root'=>[292,559],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-01-v01.png cell 2','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-zz-medium-v01','name'=>'Zamioculcas','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-01-v01.png','crop'=>[0,701,561,701],'root'=>[290,507],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-01-v01.png cell 3','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-olive-medium-v01','name'=>'Olive tree','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-01-v01.png','crop'=>[561,701,561,701],'root'=>[267,516],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-01-v01.png cell 4','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-cherry-blossom-medium-v01','name'=>'Cherry blossom','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-02-v01.png','crop'=>[0,0,630,744],'root'=>[320,591],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-02-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-box-topiary-medium-v01','name'=>'Box topiary','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-02-v01.png','crop'=>[630,0,492,750],'root'=>[244,591],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-02-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-pittosporum-medium-v01','name'=>'Pittosporum','profile'=>'medium','path'=>'/assets/images/product-media/source/PL-M-02-v01.png','crop'=>[0,750,630,652],'root'=>[310,465],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-M-02-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-monstera-large-v01','name'=>'Monstera','profile'=>'large','path'=>'/assets/images/product-media/source/PL-L-01-v01.png','crop'=>[0,0,580,585],'root'=>[303,558],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-L-01-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-fiddle-fig-large-v01','name'=>'Fiddle leaf fig','profile'=>'large','path'=>'/assets/images/product-media/source/PL-L-01-v01.png','crop'=>[580,0,542,585],'root'=>[272,559],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-L-01-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-bird-paradise-large-v01','name'=>'Bird of paradise','profile'=>'large','path'=>'/assets/images/product-media/source/PL-L-01-v01.png','crop'=>[0,700,580,550],'root'=>[301,515],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-L-01-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-olive-large-v01','name'=>'Olive tree','profile'=>'large','path'=>'/assets/images/product-media/source/PL-L-01-v01.png','crop'=>[580,705,542,550],'root'=>[260,515],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-L-01-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-cherry-blossom-large-v01','name'=>'Cherry blossom','profile'=>'large','path'=>'/assets/images/product-media/source/PL-L-02-v01.png','crop'=>[0,0,580,760],'root'=>[297,605],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-L-02-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
        ['id'=>'plant-pittosporum-large-v01','name'=>'Pittosporum','profile'=>'large','path'=>'/assets/images/product-media/source/PL-L-02-v01.png','crop'=>[580,0,542,760],'root'=>[273,605],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'PL-L-02-v01.png asset-specific crop and root','scale_status'=>'illustrative_unverified'],
    ],
    'pots' => [
        ['id'=>'pot-stone-small-v01','style'=>'stone','profile'=>'small','path'=>'/assets/images/product-media/source/POT-S-01-v01.png','crop'=>[0,0,631,701],'canvas_offset'=>35,'anchor'=>[298,192,620],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-S-01-v01.png cell 1; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-terracotta-small-v01','style'=>'terracotta','profile'=>'small','path'=>'/assets/images/product-media/source/POT-S-01-v01.png','crop'=>[561,0,561,701],'canvas_offset'=>70,'anchor'=>[272,191,620],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-S-01-v01.png cell 2; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-charcoal-small-v01','style'=>'charcoal','profile'=>'small','path'=>'/assets/images/product-media/source/POT-S-01-v01.png','crop'=>[0,701,631,701],'canvas_offset'=>35,'anchor'=>[295,147,584],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-S-01-v01.png cell 3; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-stone-medium-v01','style'=>'stone','profile'=>'medium','path'=>'/assets/images/product-media/source/POT-M-01-v01.png','crop'=>[0,0,631,701],'canvas_offset'=>35,'anchor'=>[298,170,634],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-M-01-v01.png cell 1; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-terracotta-medium-v01','style'=>'terracotta','profile'=>'medium','path'=>'/assets/images/product-media/source/POT-M-01-v01.png','crop'=>[561,0,561,701],'canvas_offset'=>70,'anchor'=>[270,170,634],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-M-01-v01.png cell 2; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-charcoal-medium-v01','style'=>'charcoal','profile'=>'medium','path'=>'/assets/images/product-media/source/POT-M-01-v01.png','crop'=>[0,701,631,701],'canvas_offset'=>35,'anchor'=>[293,116,583],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-M-01-v01.png cell 3; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-sand-medium-v01','style'=>'sand','profile'=>'medium','path'=>'/assets/images/product-media/source/POT-M-01-v01.png','crop'=>[561,701,561,701],'canvas_offset'=>70,'anchor'=>[280,120,583],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-M-01-v01.png cell 4; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-stone-large-v01','style'=>'stone','profile'=>'large','path'=>'/assets/images/product-media/source/POT-L-01-v01.png','crop'=>[0,0,631,701],'canvas_offset'=>35,'anchor'=>[298,170,620],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-L-01-v01.png cell 1; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-terracotta-large-v01','style'=>'terracotta','profile'=>'large','path'=>'/assets/images/product-media/source/POT-L-01-v01.png','crop'=>[561,0,561,701],'canvas_offset'=>70,'anchor'=>[267,170,620],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-L-01-v01.png cell 2; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-charcoal-large-v01','style'=>'charcoal','profile'=>'large','path'=>'/assets/images/product-media/source/POT-L-01-v01.png','crop'=>[0,701,631,701],'canvas_offset'=>35,'anchor'=>[299,120,560],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-L-01-v01.png cell 3; attached generated shadow','scale_status'=>'illustrative_unverified'],
        ['id'=>'pot-sand-large-v01','style'=>'sand','profile'=>'large','path'=>'/assets/images/product-media/source/POT-L-01-v01.png','crop'=>[561,701,561,701],'canvas_offset'=>70,'anchor'=>[268,120,560],'source_width'=>1122,'source_height'=>1402,'version'=>1,'provenance'=>'POT-L-01-v01.png cell 4; attached generated shadow','scale_status'=>'illustrative_unverified'],
    ],
    // These names were read from the local seeded MySQL catalogue on 2026-10-14.
    // Standard-size species matches are identity-only; S/M/L dimensions remain unverified.
    'sku_matches' => [
        ['sku'=>'PL-MON-S','asset_id'=>'plant-monstera-small-v01','match'=>'exact species and Small variant'],
        ['sku'=>'PL-MON-M','asset_id'=>'plant-monstera-large-v01','match'=>'nearest profile; no Medium monstera layer exists yet'],
        ['sku'=>'PL-MON-L','asset_id'=>'plant-monstera-large-v01','match'=>'exact species and Large variant'],
        ['sku'=>'PL-FIG-S','asset_id'=>'plant-fiddle-fig-medium-v01','match'=>'nearest profile; no Small fig layer exists yet'],
        ['sku'=>'PL-FIG-M','asset_id'=>'plant-fiddle-fig-medium-v01','match'=>'exact species and Medium variant'],
        ['sku'=>'PL-FIG-L','asset_id'=>'plant-fiddle-fig-large-v01','match'=>'exact species and Large variant'],
        ['sku'=>'PL-BIRD-S','asset_id'=>'plant-bird-paradise-medium-v01','match'=>'nearest profile; no Small bird layer exists yet'],
        ['sku'=>'PL-BIRD-M','asset_id'=>'plant-bird-paradise-medium-v01','match'=>'exact species and Medium variant'],
        ['sku'=>'PL-BIRD-L','asset_id'=>'plant-bird-paradise-large-v01','match'=>'exact species and Large variant'],
        ['sku'=>'PL-FIG','asset_id'=>'plant-fiddle-fig-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'PL-BIRD','asset_id'=>'plant-bird-paradise-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'PL-ZZ','asset_id'=>'plant-zz-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'TREE-OLIVE','asset_id'=>'plant-olive-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'TREE-CHERRY','asset_id'=>'plant-cherry-blossom-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'TOP-BOX','asset_id'=>'plant-box-topiary-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'SHR-PITT','asset_id'=>'plant-pittosporum-medium-v01','match'=>'species identity; catalogue has no measured size label'],
        ['sku'=>'POT-STONE-S','asset_id'=>'pot-stone-small-v01','match'=>'exact material and Small variant'],
        ['sku'=>'POT-STONE-L','asset_id'=>'pot-stone-large-v01','match'=>'exact material and Large variant'],
        ['sku'=>'POT-TERRA','asset_id'=>'pot-terracotta-medium-v01','match'=>'material identity only; standalone product image is allowed by Planters & pots category; plant compatibility remains unverified'],
        ['sku'=>'POT-CHAR','asset_id'=>'pot-charcoal-medium-v01','match'=>'material identity only; standalone product image is allowed by Planters & pots category; plant compatibility remains unverified'],
        ['sku'=>'POT-SAND','asset_id'=>'pot-sand-medium-v01','match'=>'material identity only; standalone product image is allowed by Planters & pots category; plant compatibility remains unverified'],
    ],
];
