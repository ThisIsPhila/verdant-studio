<section class="admin-page-heading admin-content-heading">
    <p class="eyebrow">Administration</p>
    <h1>Product media</h1>
    <p>Assign only assets already registered in this project. These settings change product presentation only; catalogue compatibility, stock and prices remain unchanged.</p>
    <p class="muted">No file uploads or external image URLs are accepted. Dimensions marked illustrative are not physical product claims.</p>
</section>

<nav class="admin-content-links" aria-label="Media sections"><?php if ($selected === null): ?><a href="#product-assignment-heading">Product assignments</a><a href="#scene-assignment-heading">Backgrounds</a><?php endif ?><a href="#assignments">Current assignments</a><a href="#registered-assets">Asset library</a></nav>
<?php if ($saved === '1'): ?><div class="admin-notice" role="status">Media assignment saved.</div><?php elseif ($saved === 'archived'): ?><div class="admin-notice" role="status">Media assignment archived. Its history is retained.</div><?php elseif ($saved === 'restored'): ?><div class="admin-notice" role="status">Media assignment restored.</div><?php endif ?>
<?php if ($errors): ?><div class="admin-notice" role="alert"><h2>Changes were not saved</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>

<?php
$value = static fn (string $key, string $default = ''): string => is_string($values[$key] ?? null) ? $values[$key] : $default;
$selectedIsArchived = $selected !== null && $selected['archived_at'] !== null;
$selectedTarget = $value('target_type', 'product');
$assetsByKind = ['plant' => [], 'pot' => [], 'background' => []];
foreach ($assets as $asset) if (isset($assetsByKind[$asset['kind']])) {
    if (!isset($asset['name']) && $asset['kind'] === 'background') {
        $asset['name'] = match ($asset['id']) {
            'bg-product-cream-v01' => 'Warm cream product background',
            'bg-individual-stone-v01' => 'Stone product surface',
            'bg-individual-homes-v01' => 'Homes room',
            'bg-individual-entry-v01' => 'Entry setting',
            default => 'Approved background',
        };
    }
    $assetsByKind[$asset['kind']][] = $asset;
}
?>

<?php if ($selected !== null): ?>
    <section class="admin-panel" aria-labelledby="selected-assignment-title">
        <h2 id="selected-assignment-title"><?= $selectedIsArchived ? 'Archived assignment' : 'Edit assignment' ?></h2>
        <?php if ($selectedIsArchived): ?><p>This assignment is archived and cannot be edited until restored.</p>
        <?php elseif ($selected['product_id'] === null): ?><p>Scene background · <?= $e(substr((string) $selected['target_key'], strlen('scene:'))) ?></p>
        <?php else: ?><p><?= $e($selected['product_id']) ?> · <?= $e($selected['media_kind']) ?></p><?php endif ?>
    </section>
<?php endif ?>

<?php if ($selected === null || !$selectedIsArchived): ?>
    <?php if ($selected === null || $selectedTarget === 'product'): ?>
    <section class="admin-panel" aria-labelledby="product-assignment-heading">
        <h2 id="product-assignment-heading"><?= $selected === null ? 'Assign media to a product' : 'Product assignment details' ?></h2>
        <?php if ($selected === null && !$products): ?><p>No active plants or pots are currently eligible for media assignment.</p><?php else: ?>
        <form method="post" action="/admin/media/save" class="admin-media-form">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <input type="hidden" name="target_type" value="product">
            <?php if ($selected !== null): ?>
                <input type="hidden" name="binding_id" value="<?= $e($selected['binding_id']) ?>">
                <input type="hidden" name="product_id" value="<?= $e($selected['product_id']) ?>">
                <input id="media-kind" type="hidden" name="media_kind" value="<?= $e($selected['media_kind']) ?>">
                <input type="hidden" name="expected_version" value="<?= $e($value('expected_version', (string) $selected['lock_version'])) ?>">
            <?php endif ?>
            <div class="field"><label for="media-product">Product SKU</label>
                <select id="media-product" name="product_id" required <?= $selected !== null ? 'disabled' : '' ?>>
                    <option value="">Choose an active plant or pot</option>
                    <?php foreach ($products as $product): $productId = (string) $product['product_id']; ?>
                        <option value="<?= $e($productId) ?>" <?= $value('product_id') === $productId ? 'selected' : '' ?>><?= $e($product['name']) ?> · <?= $e($product['sku']) ?> · <?= $e($label($product['product_kind'])) ?><?= ($product['variant_label'] ?? '') !== '' ? ' · ' . $e($product['variant_label']) : '' ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php if ($selected === null): ?><div class="field"><label for="media-kind">Media kind</label><select id="media-kind" name="media_kind"><option value="plant" <?= $value('media_kind', 'plant') === 'plant' ? 'selected' : '' ?>>Plant cutout</option><option value="pot" <?= $value('media_kind') === 'pot' ? 'selected' : '' ?>>Pot</option></select></div><?php endif ?>
            <?php
            $pickerId = 'media-asset'; $pickerName = 'asset_id'; $pickerLabel = 'Registered asset';
            $pickerValue = $value('asset_id'); $pickerEmptyLabel = 'Choose a local registered asset';
            $pickerOptions = array_merge(...array_values($assetsByKind));
            $pickerKindControl = 'media-kind'; $pickerContextControl = ''; $pickerStandaloneAsset = '';
            $pickerRequired = true;
            require __DIR__ . '/../components/admin-asset-picker.php';
            ?><?php if (isset($errors['asset_id'])): ?><p class="field-error"><?= $e($errors['asset_id']) ?></p><?php endif ?>
            <div class="field"><label for="media-measurement">Dimension confidence</label><select id="media-measurement" name="measurement_status"><option value="unknown" <?= $value('measurement_status', 'unknown') === 'unknown' ? 'selected' : '' ?>>Unknown — no physical measurement</option><option value="illustrative" <?= $value('measurement_status') === 'illustrative' ? 'selected' : '' ?>>Illustrative — not verified</option><option value="measured" <?= $value('measurement_status') === 'measured' ? 'selected' : '' ?>>Measured — owner verified</option></select></div>
            <div class="field"><label for="plant-height">Plant visible height (mm)</label><input id="plant-height" name="visible_height_mm" type="number" min="1" max="5000" step="1" value="<?= $e($value('visible_height_mm')) ?>"><small>Use for a plant only; leave blank unless the selected confidence requires a measurement.</small><?php if (isset($errors['visible_height_mm'])): ?><p class="field-error"><?= $e($errors['visible_height_mm']) ?></p><?php endif ?></div>
            <div class="field"><label for="pot-height">Pot height (mm)</label><input id="pot-height" name="pot_height_mm" type="number" min="1" max="5000" step="1" value="<?= $e($value('pot_height_mm')) ?>"><small>Use for a pot only. Unknown and illustrative dimensions do not enable the people-scale view.</small><?php if (isset($errors['pot_height_mm'])): ?><p class="field-error"><?= $e($errors['pot_height_mm']) ?></p><?php endif ?></div>
            <?php if (isset($errors['product_id'])): ?><p class="field-error"><?= $e($errors['product_id']) ?></p><?php endif ?>
            <?php if (isset($errors['measurement_status'])): ?><p class="field-error"><?= $e($errors['measurement_status']) ?></p><?php endif ?>
            <?php if (isset($errors['version'])): ?><p class="field-error"><?= $e($errors['version']) ?></p><?php endif ?>
            <button type="submit">Save media assignment</button>
        </form>
        <?php endif ?>
    </section>
    <?php endif ?>

    <?php if ($selected === null || $selectedTarget === 'scene'): ?>
    <section class="admin-panel" aria-labelledby="scene-assignment-heading">
        <h2 id="scene-assignment-heading"><?= $selected === null ? 'Assign a scene background' : 'Scene background details' ?></h2>
        <p class="muted">A scene background sits behind the selected product layers. It does not alter the registered plant or pot source images.</p>
        <form method="post" action="/admin/media/save" class="admin-media-form">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <input type="hidden" name="target_type" value="scene">
            <?php if ($selected !== null): ?><input type="hidden" name="binding_id" value="<?= $e($selected['binding_id']) ?>"><input type="hidden" name="expected_version" value="<?= $e($value('expected_version', (string) $selected['lock_version'])) ?>"><?php endif ?>
            <div class="field"><label for="media-scene">Storefront presentation</label><select id="media-scene" name="scene_key" required <?= $selected !== null ? 'disabled' : '' ?>>
                <?php foreach ($scenes as $scene => $defaultAsset): $sceneLabel = match ($scene) { 'product-detail' => 'Plant and pot product scene', 'homes' => 'Homes room scene', 'configuration' => 'Configuration scene', 'standalone-pot' => 'Standalone pot on a stone surface', default => $label($scene) }; ?><option value="<?= $e($scene) ?>" <?= $value('scene_key', $selected === null ? (string) array_key_first($scenes) : '') === $scene ? 'selected' : '' ?>><?= $e($sceneLabel) ?></option><?php endforeach ?>
            </select><?php if ($selected !== null): ?><input type="hidden" name="scene_key" value="<?= $e($value('scene_key')) ?>"><?php endif ?><?php if (isset($errors['scene'])): ?><p class="field-error"><?= $e($errors['scene']) ?></p><?php endif ?></div>
            <?php
            $pickerId = 'scene-asset'; $pickerName = 'asset_id'; $pickerLabel = 'Registered background asset';
            $pickerValue = $value('asset_id'); $pickerEmptyLabel = 'Choose a local background';
            $pickerOptions = $assetsByKind['background']; $pickerKindControl = '';
            $pickerContextControl = 'media-scene'; $pickerStandaloneAsset = 'bg-individual-stone-v01'; $pickerRequired = true;
            require __DIR__ . '/../components/admin-asset-picker.php';
            ?><?php if (isset($errors['asset_id'])): ?><p class="field-error"><?= $e($errors['asset_id']) ?></p><?php endif ?>
            <?php $standaloneContext = $value('scene_key', $selected === null ? (string) array_key_first($scenes) : '') === 'standalone-pot'; ?>
            <div class="field" data-standalone-pot-controls <?= $standaloneContext ? '' : 'hidden' ?>><label for="pot-camera-scale">Standalone pot camera framing</label><select id="pot-camera-scale" name="camera_scale">
                <?php $cameraScale = $value('camera_scale', '1.00'); foreach (['0.85', '0.90', '0.95', '1.00', '1.05', '1.10', '1.15'] as $scale): ?><option value="<?= $e($scale) ?>" <?= $cameraScale === $scale ? 'selected' : '' ?>><?= $e((int) round(((float) $scale) * 100)) ?>%<?= $scale === '1.00' ? ' · Default' : '' ?></option><?php endforeach ?>
            </select><small>This changes the camera view only; it does not change stored planter dimensions.</small><?php if (isset($errors['camera_scale'])): ?><p class="field-error"><?= $e($errors['camera_scale']) ?></p><?php endif ?></div>
            <div class="field admin-pot-preview" data-standalone-pot-preview <?= $standaloneContext ? '' : 'hidden' ?>><span class="admin-pot-preview__label">Framing preview</span><canvas data-standalone-pot-canvas width="800" height="1000" role="img" aria-label="Standalone pot preview on the selected approved surface"></canvas><button type="button" class="quiet-button" data-reset-pot-framing>Reset framing</button><p class="muted">Preview uses a representative registered pot. Product scale and measurement records are unchanged.</p>
                <?php $potPreviewAsset = $assetsByKind['pot'][0] ?? null; $backgroundPreviewAssets = $assetsByKind['background']; ?>
                <?php if ($potPreviewAsset !== null): ?><script type="application/json" data-standalone-pot-preview-data><?= json_encode(['pot' => array_intersect_key($potPreviewAsset, array_flip(['id', 'kind', 'path', 'crop', 'source_width', 'source_height', 'profile', 'root', 'canvas_offset', 'anchor'])), 'backgrounds' => array_map(static fn (array $asset): array => array_intersect_key($asset, array_flip(['id', 'kind', 'path', 'crop', 'source_width', 'source_height'])), $backgroundPreviewAssets)], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><?php endif ?>
            </div>
            <?php if (isset($errors['version'])): ?><p class="field-error"><?= $e($errors['version']) ?></p><?php endif ?>
            <button type="submit">Save scene background</button>
        </form>
    </section>
    <?php endif ?>
<?php endif ?>

<section id="registered-assets" class="admin-panel" aria-labelledby="registered-assets-heading">
    <h2 id="registered-assets-heading">Registered local assets</h2>
    <?php foreach ($assetsByKind as $kind => $kindAssets): if (!$kindAssets) continue; ?>
        <h3 class="admin-layer-heading admin-layer-heading--<?= $e($kind) ?>"><?= $e($label($kind)) ?> · <?= $e(count($kindAssets)) ?> assets</h3>
        <div class="admin-media-asset-grid">
            <?php foreach ($kindAssets as $asset): ?><article class="admin-media-asset admin-media-asset--<?= $e($kind) ?>">
                <?php $previewAsset = array_intersect_key($asset, array_flip(['id', 'kind', 'path', 'crop', 'source_width', 'source_height', 'profile', 'root', 'canvas_offset', 'anchor'])); ?>
                <div class="admin-media-asset__preview" data-asset-crop-preview>
                    <img src="<?= $e($asset['path']) ?>" alt="" width="150" height="150" loading="lazy" hidden>
                    <span class="admin-media-asset__preview-fallback" data-crop-fallback>Crop preview unavailable</span>
                    <?php if (isset($asset['crop'])): ?><script type="application/json" data-asset-descriptor><?= json_encode($previewAsset, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><?php endif ?>
                </div>
                <div><strong><?= $e($asset['name'] ?? $asset['id']) ?></strong><br><code><?= $e($asset['id']) ?></code>
                    <p class="muted"><?= $e($asset['source_width'] ?? '—') ?> × <?= $e($asset['source_height'] ?? '—') ?> source px<?= isset($asset['profile']) ? ' · ' . $e($asset['profile']) : '' ?></p>
                    <details><summary>Source image and crop details</summary><p><?= $e($asset['provenance'] ?? 'Provenance not recorded.') ?></p><p>Crop <?= $e(is_array($asset['crop'] ?? null) ? implode(', ', $asset['crop']) : 'not specified') ?></p><?php if (($asset['kind'] ?? '') !== 'background'): ?><img class="admin-media-source" src="<?= $e($asset['path']) ?>" alt="Complete source image sheet for <?= $e($asset['name'] ?? $asset['id']) ?>" loading="lazy"><?php endif ?></details>
                </div>
            </article><?php endforeach ?>
        </div>
    <?php endforeach ?>
</section>

<section id="assignments" class="admin-panel" aria-labelledby="media-assignments-heading">
    <h2 id="media-assignments-heading">Assignments</h2>
    <?php if (!$bindings): ?><p>No media assignments exist yet.</p><?php else: ?>
    <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Product media assignments"><table class="admin-table"><thead><tr><th>Target</th><th>Kind / asset</th><th>Measurements</th><th>State</th><th>Version / updated</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($bindings as $binding): $archived = $binding['archived_at'] !== null; $asset = null; foreach ($assets as $candidate) if ($candidate['id'] === $binding['asset_id']) { $asset = $candidate; break; } ?>
        <tr><td><?php if ($binding['product_id'] === null): ?>Scene · <?= $e(substr((string) $binding['target_key'], strlen('scene:'))) ?><?php else: ?><?= $e($binding['product_name'] ?? 'Unavailable product') ?><br><small><?= $e($binding['sku'] ?? 'Archived product') ?> · #<?= $e($binding['product_id']) ?></small><?php endif ?></td>
            <td><?= $e($label($binding['media_kind'])) ?><br><code><?= $e($binding['asset_id']) ?></code><?php if ($asset === null): ?><br><small>Asset is no longer registered</small><?php endif ?><?php if (($binding['target_key'] ?? '') === 'scene:standalone-pot'): ?><br><small>Camera framing <?= $e((int) round(((float) ($binding['camera_scale'] ?? 1.0)) * 100)) ?>% · presentation only</small><?php endif ?></td>
            <td><?= $e($label($binding['measurement_status'])) ?><?php if ($binding['visible_height_mm'] !== null): ?><br>Plant <?= $e($binding['visible_height_mm']) ?> mm<?php endif ?><?php if ($binding['pot_height_mm'] !== null): ?><br>Pot <?= $e($binding['pot_height_mm']) ?> mm<?php endif ?></td>
            <td><?= $archived ? 'Archived' : 'Active' ?><?php if (($binding['product_archived_at'] ?? null) !== null || ($binding['category_archived_at'] ?? null) !== null): ?><br><small>Target is archived</small><?php endif ?></td>
            <td><?= $e($binding['lock_version']) ?><br><small><?= $e($binding['updated_at']) ?></small></td><td>
                <a href="/admin/media?binding_id=<?= $e($binding['binding_id']) ?>" <?= (int) $selectedId === (int) $binding['binding_id'] ? 'aria-current="page"' : '' ?>>Review</a>
                <?php if (!$archived): ?><form method="post" action="/admin/media/archive" class="inline-form"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="binding_id" value="<?= $e($binding['binding_id']) ?>"><input type="hidden" name="expected_version" value="<?= $e($binding['lock_version']) ?>"><button type="submit">Archive</button></form>
                <?php else: ?><form method="post" action="/admin/media/restore" class="inline-form"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="binding_id" value="<?= $e($binding['binding_id']) ?>"><input type="hidden" name="expected_version" value="<?= $e($binding['lock_version']) ?>"><button type="submit">Restore</button></form><?php endif ?>
            </td></tr>
        <?php endforeach ?>
    </tbody></table></div>
    <?php endif ?>
</section>

<?php if ($selected !== null): ?>
<section class="admin-panel" aria-labelledby="media-history-heading">
    <h2 id="media-history-heading">Assignment history</h2>
    <?php if (!$history): ?><p>No history has been recorded for this assignment.</p><?php else: ?><div class="admin-scroll-table" tabindex="0" role="region" aria-label="Assignment history"><table class="admin-table"><thead><tr><th>Event</th><th>Old asset</th><th>New asset</th><th>Dimensions</th><th>Presentation</th><th>Version / recorded at</th></tr></thead><tbody>
        <?php foreach ($history as $event): ?><tr><td><?= $e($label($event['action'])) ?><?php if ($event['actor_customer_id'] !== null): ?><br><small>Admin #<?= $e($event['actor_customer_id']) ?></small><?php else: ?><br><small>System default</small><?php endif ?></td><td><?= $e($event['previous_asset_id'] ?? '—') ?></td><td><?= $e($event['new_asset_id']) ?></td><td><?= $e($label($event['new_measurement_status'])) ?><?php if ($event['new_visible_height_mm'] !== null): ?><br>Plant <?= $e($event['new_visible_height_mm']) ?> mm<?php endif ?><?php if ($event['new_pot_height_mm'] !== null): ?><br>Pot <?= $e($event['new_pot_height_mm']) ?> mm<?php endif ?></td><td><?php if (($selected['target_key'] ?? '') === 'scene:standalone-pot'): ?>Camera <?= ($event['previous_camera_scale'] ?? null) === null ? '—' : $e((int) round(((float) $event['previous_camera_scale']) * 100)) . '%' ?> → <?= $e((int) round(((float) ($event['new_camera_scale'] ?? 1.0)) * 100)) ?>%<?php else: ?>—<?php endif ?></td><td><?= $e($event['resulting_version']) ?><br><small><?= $e($event['occurred_at']) ?></small></td></tr><?php endforeach ?>
    </tbody></table></div><?php endif ?>
</section>
<?php endif ?>
<script src="/assets/js/media/renderer.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/media/renderer.js')) ?>" defer></script>
<script src="/assets/js/media/admin-picker.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/media/admin-picker.js')) ?>" defer></script>
