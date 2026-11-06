<?php

declare(strict_types=1);

namespace Verdant\Media;

final class MediaAssetRegistry
{
    private array $assets = [];
    private array $skuMatches = [];

    public function __construct(?array $configuration = null)
    {
        $configuration ??= require dirname(__DIR__, 2) . '/config/product-media.php';
        foreach (['plants' => 'plant', 'pots' => 'pot', 'environments' => 'background'] as $section => $kind) {
            foreach ($configuration[$section] ?? [] as $asset) {
                $asset['kind'] = $kind;
                if (!isset($asset['id'], $asset['path']) || isset($this->assets[$asset['id']])) {
                    throw new \InvalidArgumentException('The local media registry contains an invalid or duplicate asset.');
                }
                $this->assets[$asset['id']] = $asset;
            }
        }
        foreach ($configuration['sku_matches'] ?? [] as $match) {
            if (isset($this->skuMatches[$match['sku']])) {
                throw new \InvalidArgumentException('The local media registry contains duplicate SKU mappings.');
            }
            $this->skuMatches[$match['sku']] = $match;
        }
    }

    public function asset(string $id): ?array
    {
        return $this->assets[$id] ?? null;
    }

    /** Publicly documented registry entries for protected local media management. */
    public function allAssets(): array
    {
        return array_values($this->assets);
    }

    public function skuMatches(): array
    {
        return $this->skuMatches;
    }

    public function backgroundScenes(): array
    {
        return [
            'product-detail' => 'bg-product-cream-v01',
            'homes' => 'bg-individual-homes-v01',
            'configuration' => 'bg-product-cream-v01',
            'standalone-pot' => 'bg-product-cream-v01',
        ];
    }
}
