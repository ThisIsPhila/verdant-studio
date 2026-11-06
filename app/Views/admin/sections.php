<?php $displayPageLabel = $page === 'home' ? 'Landing page' : ($page === 'homes' ? 'Spaces · Homes' : $pages[$page]['label']); ?>
<section class="admin-page-heading admin-content-heading"><p class="eyebrow">Administration</p><h1>Storefront sections</h1><p>Edit approved text and local links for each page. Section controls cannot change catalogue, stock, order or payment data.</p></section>
<?php if ($saved): ?><div class="admin-notice" role="status">Section changes saved.</div><?php endif ?>
<?php if ($published): ?><div class="admin-notice" role="status">The policy snapshot was published. Previous versions remain in history.</div><?php endif ?>
<?php if ($errors): ?><div class="admin-notice" role="alert"><h2>Changes were not saved</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<?php if ($diagnostics): ?><section class="admin-panel" aria-labelledby="section-warnings"><h2 id="section-warnings">Content warnings</h2><ul><?php foreach ($diagnostics as $diagnostic): foreach ($diagnostic['warnings'] as $warning): ?><li><?= $e($diagnostic['section_key']) ?>: <?= $e($warning) ?></li><?php endforeach; endforeach ?></ul></section><?php endif ?>

<section class="admin-panel" aria-labelledby="select-page-heading">
    <h2 id="select-page-heading">Choose a page</h2>
    <form class="admin-page-selector" method="get" action="/admin/sections">
        <div class="field"><label for="section-page">Storefront page</label><select id="section-page" name="page">
            <?php $pageValue = is_string($values['page'] ?? null) ? $values['page'] : $page; ?>
            <?php if (!isset($pages[$pageValue])): ?><option value="<?= $e($pageValue) ?>" selected>Submitted page (unsupported)</option><?php endif ?>
            <?php
            $pageGroups = ['Main website' => ['home' => 'Landing page', 'catalogue' => 'Shop · all products'], 'Spaces' => ['homes' => 'Homes'], 'Collections & services' => ['arrangements' => 'Arrangements', 'rentals' => 'Rentals']];
            foreach ($pageGroups as $group => $groupPages): ?>
                <optgroup label="<?= $e($group) ?>">
                <?php foreach ($groupPages as $key => $pageLabel): if (!isset($pages[$key])) continue; ?>
                    <option value="<?= $e($key) ?>" <?= $pageValue === $key ? 'selected' : '' ?>><?= $e($pageLabel) ?></option>
                <?php endforeach ?>
                </optgroup>
            <?php endforeach ?>
            <?php if ($informationPages): ?><optgroup label="Information pages">
                <?php foreach ($informationPages as $key => $definition): ?><option value="<?= $e($key) ?>" <?= $pageValue === $key ? 'selected' : '' ?>><?= $e($definition['label']) ?><?= ($definition['policy'] ?? false) ? ' · policy draft' : '' ?></option><?php endforeach ?>
            </optgroup><?php endif ?>
        </select></div>
        <button type="submit">Open page sections</button>
    </form>
</section>

<?php if (isset($informationPages[$page])): ?>
<section class="admin-panel" aria-label="Information page preview">
    <a href="/admin/information-preview?page=<?= $e(rawurlencode($page)) ?>">Preview current sections</a>
    <?php if ($policyState === null || $policyState['published_revision'] !== null): ?>
        · <a href="<?= $e($informationPages[$page]['route']) ?>">View public page</a>
    <?php endif ?>
</section>
<?php endif ?>

<?php if ($policyState !== null): ?>
<section class="admin-panel" aria-labelledby="policy-publication-heading">
    <h2 id="policy-publication-heading">Policy publication</h2>
    <p>Section edits stay private while this page is a draft. Publishing records the current visible sections as a fixed version; later edits do not change that published copy.</p>
    <p>Current state: <strong><?= $e($policyState['status']) ?></strong><?php if ($policyState['published_version'] !== null): ?> · published version <?= $e($policyState['published_version']) ?>, effective <?= $e($policyState['effective_date']) ?><?php endif ?> · draft revision <?= $e($policyState['draft_revision']) ?><?php if ($policyState['has_draft_changes']): ?> · unpublished edits<?php endif ?></p>
    <?php if ($errors && (isset($errors['version']) || isset($errors['effective_date']) || isset($errors['revision']) || isset($errors['confirm_publish']))): ?><div class="admin-notice" role="alert"><h3>Policy was not published</h3><ul><?php foreach (['version', 'effective_date', 'revision', 'confirm_publish'] as $field): if (isset($errors[$field])): ?><li><?= $e($errors[$field]) ?></li><?php endif; endforeach ?></ul></div><?php endif ?>
    <form method="post" action="/admin/sections/publish-policy">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <input type="hidden" name="page" value="<?= $e($page) ?>">
        <input type="hidden" name="revision" value="<?= $e($policyState['draft_revision']) ?>">
        <div class="field"><label for="policy-version">Version</label><input id="policy-version" name="version" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,31}" required value="<?= $e($values['version'] ?? '') ?>"><?php if (isset($errors['version'])): ?><p class="field-error"><?= $e($errors['version']) ?></p><?php endif ?></div>
        <div class="field"><label for="policy-effective-date">Effective date</label><input id="policy-effective-date" type="date" name="effective_date" required value="<?= $e($values['effective_date'] ?? date('Y-m-d')) ?>"><?php if (isset($errors['effective_date'])): ?><p class="field-error"><?= $e($errors['effective_date']) ?></p><?php endif ?></div>
        <div class="field"><label><input type="checkbox" name="confirm_publish" value="1" required <?= ($values['confirm_publish'] ?? '') === '1' ? 'checked' : '' ?>> I reviewed this exact draft revision and approve publishing it.</label><?php if (isset($errors['confirm_publish'])): ?><p class="field-error"><?= $e($errors['confirm_publish']) ?></p><?php endif ?></div>
        <button type="submit">Publish policy revision</button>
    </form>
    <?php if ($policyHistory): ?><h3>Published history</h3><div class="admin-scroll-table" tabindex="0" role="region" aria-label="Published policy history"><table class="admin-table"><thead><tr><th>Version</th><th>Effective</th><th>Draft revision</th><th>Published</th></tr></thead><tbody><?php foreach ($policyHistory as $publication): ?><tr><td><?= $e($publication['version']) ?></td><td><?= $e($publication['effective_date']) ?></td><td><?= $e($publication['draft_revision']) ?></td><td><?= $e($publication['published_at']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
</section>
<?php endif ?>

<section class="admin-panel" aria-labelledby="section-list-heading">
    <h2 id="section-list-heading">Sections on <?= $e($displayPageLabel) ?></h2>
    <?php if (!$sections): ?><p>No sections are configured for this page.</p><?php else: ?>
        <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Storefront sections table"><table class="admin-table"><thead><tr><th>Order</th><th>Section</th><th>Content</th><th>State</th><th>Actions</th></tr></thead><tbody>
        <?php $activeIndex = 0; $activeCount = count(array_filter($sections, static fn (array $item): bool => $item['archived_at'] === null)); ?>
        <?php foreach ($sections as $row): $archived = $row['archived_at'] !== null; $currentIndex = $activeIndex; if (!$archived) { ++$activeIndex; } ?>
        <tr><td><?= $e($row['position']) ?></td><td><a href="/admin/sections?page=<?= $e(rawurlencode($page)) ?>&amp;section=<?= $e(rawurlencode($row['section_key'])) ?>" <?= ($selected['section_key'] ?? null) === $row['section_key'] ? 'aria-current="page"' : '' ?>><?= $e($label(str_replace('_', ' ', $row['section_key']))) ?></a><br><small><?= $e($row['section_type']) ?></small></td><td><?= $e($row['heading']) ?></td><td><?= $archived ? 'Archived' : ((int) $row['is_enabled'] === 1 ? 'Visible' : 'Hidden') ?></td><td>
            <?php if (!$archived): ?>
            <form method="post" action="/admin/sections/move" class="inline-form"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="page" value="<?= $e($page) ?>"><input type="hidden" name="section_key" value="<?= $e($row['section_key']) ?>"><input type="hidden" name="direction" value="-1"><button type="submit" aria-label="Move <?= $e($row['section_key']) ?> up" <?= $currentIndex === 0 ? 'disabled' : '' ?>>Move up</button></form>
            <form method="post" action="/admin/sections/move" class="inline-form"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="page" value="<?= $e($page) ?>"><input type="hidden" name="section_key" value="<?= $e($row['section_key']) ?>"><input type="hidden" name="direction" value="1"><button type="submit" aria-label="Move <?= $e($row['section_key']) ?> down" <?= $currentIndex >= $activeCount - 1 ? 'disabled' : '' ?>>Move down</button></form>
            <?php else: ?>
            <form method="post" action="/admin/sections/restore" class="inline-form"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="page" value="<?= $e($page) ?>"><input type="hidden" name="section_key" value="<?= $e($row['section_key']) ?>"><button type="submit">Restore</button></form>
            <?php endif ?>
        </td></tr>
        <?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
</section>

<?php if ($selected): ?>
<section class="admin-panel" aria-labelledby="edit-section-heading">
    <h2 id="edit-section-heading">Edit <?= $e($selected['section_key']) ?></h2>
    <p class="muted">Type: <?= $e($selected['section_type']) ?>. The type and page are fixed by the registry.</p>
    <?php if ($selected['archived_at'] !== null): ?><p>This section is archived. Restore it before editing.</p>
    <?php else: ?>
    <form class="admin-content-form" method="post" action="/admin/sections/save">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="page" value="<?= $e($page) ?>"><input type="hidden" name="section_key" value="<?= $e($selected['section_key']) ?>">
        <div class="field"><label for="section-heading">Heading</label><input id="section-heading" name="heading" maxlength="180" required value="<?= $e($values['heading'] ?? '') ?>"><?php if (isset($errors['heading'])): ?><p class="field-error" id="heading-error"><?= $e($errors['heading']) ?></p><?php endif ?></div>
        <div class="field admin-content-body-field"><label for="section-body">Body</label><textarea id="section-body" name="body" rows="8" maxlength="4000"><?= $e($values['body'] ?? '') ?></textarea><?php if (isset($errors['body'])): ?><p class="field-error" id="body-error"><?= $e($errors['body']) ?></p><?php endif ?></div>
        <?php
        $mediaValue = is_string($values['media_path'] ?? null) ? $values['media_path'] : '';
        $sectionMediaNames = [
            '/assets/vision/images/hero.jpg' => 'Landing hero',
            '/assets/vision/images/hero-configure.jpg' => 'Configuration hero',
            '/assets/vision/images/r-cluster.jpg' => 'Cluster arrangement',
            '/assets/vision/images/r-olive.jpg' => 'Olive arrangement',
            '/assets/vision/images/p-monstera.jpg' => 'Monstera product',
            '/assets/vision/images/homes.jpg' => 'Homes collection',
            '/assets/vision/images/workspaces.jpg' => 'Workspaces inspiration',
            '/assets/vision/images/gatherings.jpg' => 'Gatherings inspiration',
            '/assets/images/botanical.svg' => 'Botanical illustration',
            '/assets/images/catalogue/placeholders/plant.svg' => 'Plant placeholder',
            '/assets/images/catalogue/placeholders/plant-arrangement.svg' => 'Plant arrangement placeholder',
            '/assets/images/catalogue/placeholders/floral-arrangement.svg' => 'Floral arrangement placeholder',
            '/assets/images/catalogue/placeholders/cut-flower.svg' => 'Cut flower placeholder',
            '/assets/images/catalogue/placeholders/cut-foliage.svg' => 'Cut foliage placeholder',
            '/assets/images/catalogue/placeholders/accessory.svg' => 'Accessory placeholder',
            '/assets/images/catalogue/placeholders/other.svg' => 'General product placeholder',
        ];
        $sectionAssetOptions = [];
        foreach ($mediaPaths as $path) if (is_string($path)) $sectionAssetOptions[] = [
            'id' => $path, 'kind' => 'section', 'name' => $sectionMediaNames[$path] ?? 'Approved page image', 'path' => $path,
        ];
        $pickerId = 'section-media'; $pickerName = 'media_path'; $pickerLabel = 'Approved local image';
        $pickerValue = $mediaValue; $pickerEmptyLabel = 'No image'; $pickerOptions = $sectionAssetOptions;
        $pickerKindControl = ''; $pickerContextControl = ''; $pickerStandaloneAsset = ''; $pickerRequired = false;
        require __DIR__ . '/../components/admin-asset-picker.php';
        ?>
        <?php if (isset($errors['media_path'])): ?><p class="field-error" id="media-error"><?= $e($errors['media_path']) ?></p><?php endif ?>
        <div class="field"><label for="section-media-alt">Image alternative text</label><input id="section-media-alt" name="media_alt" maxlength="180" value="<?= $e($values['media_alt'] ?? '') ?>"><?php if (isset($errors['media_alt'])): ?><p class="field-error" id="media-alt-error"><?= $e($errors['media_alt']) ?></p><?php endif ?></div>
        <div class="field"><label for="section-link-label">Link label</label><input id="section-link-label" name="link_label" maxlength="80" value="<?= $e($values['link_label'] ?? '') ?>"><?php if (isset($errors['link_label'])): ?><p class="field-error" id="link-label-error"><?= $e($errors['link_label']) ?></p><?php endif ?></div>
        <div class="field"><label for="section-link-path">Supported local destination</label><select id="section-link-path" name="link_path"><?php $linkValue = is_string($values['link_path'] ?? null) ? $values['link_path'] : ''; ?>
            <option value="" <?= $linkValue === '' ? 'selected' : '' ?>>No link</option>
            <?php if ($linkValue !== '' && !in_array($linkValue, $linkPaths, true) && !preg_match('~^/(?:compositions|configure)\?code=[a-z0-9][a-z0-9-]{0,79}$~D', $linkValue)): ?><option value="<?= $e($linkValue) ?>" selected>Submitted path (not approved)</option><?php endif ?>
            <?php foreach ($linkPaths as $path): ?><option value="<?= $e($path) ?>" <?= $linkValue === $path ? 'selected' : '' ?>><?= $e($path) ?></option><?php endforeach ?>
            <?php foreach ($arrangements as $arrangement): foreach (['/compositions?code=' . $arrangement['definition_code'], '/configure?code=' . $arrangement['definition_code']] as $path): ?><option value="<?= $e($path) ?>" <?= $linkValue === $path ? 'selected' : '' ?>><?= $e($arrangement['name']) ?> · <?= $e($path) ?></option><?php endforeach; endforeach ?>
        </select><?php if (isset($errors['link_path'])): ?><p class="field-error" id="link-error"><?= $e($errors['link_path']) ?></p><?php endif ?></div>
        <div class="field"><label for="section-collection">Existing active collection</label><select id="section-collection" name="collection_id"><?php $collectionValue = (string) ($values['collection_id'] ?? ''); ?><option value="" <?= $collectionValue === '' ? 'selected' : '' ?>>No collection reference</option>
            <?php if ($collectionValue !== '' && !in_array($collectionValue, array_map(static fn (array $item): string => (string) $item['collection_id'], $collections), true)): ?><option value="<?= $e($collectionValue) ?>" selected>Submitted reference (not available)</option><?php endif ?>
            <?php foreach ($collections as $collection): ?><option value="<?= $e($collection['collection_id']) ?>" <?= $collectionValue === (string) $collection['collection_id'] ? 'selected' : '' ?>><?= $e($collection['name']) ?></option><?php endforeach ?>
        </select><?php if (isset($errors['collection_id'])): ?><p class="field-error" id="collection-error"><?= $e($errors['collection_id']) ?></p><?php endif ?></div>
        <div class="field"><label for="section-arrangement">Existing active arrangement</label><select id="section-arrangement" name="arrangement_id"><?php $arrangementValue = (string) ($values['arrangement_id'] ?? ''); ?><option value="" <?= $arrangementValue === '' ? 'selected' : '' ?>>No arrangement reference</option>
            <?php if ($arrangementValue !== '' && !in_array($arrangementValue, array_map(static fn (array $item): string => (string) $item['definition_id'], $arrangements), true)): ?><option value="<?= $e($arrangementValue) ?>" selected>Submitted reference (not available)</option><?php endif ?>
            <?php foreach ($arrangements as $arrangement): ?><option value="<?= $e($arrangement['definition_id']) ?>" <?= $arrangementValue === (string) $arrangement['definition_id'] ? 'selected' : '' ?>><?= $e($arrangement['name']) ?> · <?= $e($arrangement['definition_code']) ?></option><?php endforeach ?>
        </select><?php if (isset($errors['arrangement_id'])): ?><p class="field-error" id="arrangement-error"><?= $e($errors['arrangement_id']) ?></p><?php endif ?></div>
        <button type="submit">Save section</button>
    </form>
    <form method="post" action="/admin/sections/visibility" class="form-row"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="page" value="<?= $e($page) ?>"><input type="hidden" name="section_key" value="<?= $e($selected['section_key']) ?>"><div class="field"><label for="section-visibility">Visibility</label><?php $visibilityValue = $values['is_enabled'] ?? (string) $selected['is_enabled']; ?><select id="section-visibility" name="is_enabled"><option value="1" <?= $visibilityValue === '1' ? 'selected' : '' ?>>Visible</option><option value="0" <?= $visibilityValue === '0' ? 'selected' : '' ?>>Hidden</option><?php if (!in_array($visibilityValue, ['0', '1'], true)): ?><option value="<?= $e($visibilityValue) ?>" selected>Submitted value (invalid)</option><?php endif ?></select><?php if (isset($errors['is_enabled'])): ?><p class="field-error" id="visibility-error"><?= $e($errors['is_enabled']) ?></p><?php endif ?></div><button type="submit">Save visibility</button></form>
    <form method="post" action="/admin/sections/archive"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="page" value="<?= $e($page) ?>"><input type="hidden" name="section_key" value="<?= $e($selected['section_key']) ?>"><button type="submit">Archive section</button></form>
    <?php endif ?>
</section>
<?php endif ?>
<script src="/assets/js/media/admin-picker.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/media/admin-picker.js')) ?>" defer></script>
