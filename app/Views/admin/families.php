<header class="admin-page-heading"><div>
    <p>Catalogue management · Product identity</p>
    <h1>Product families</h1>
    <p>Group related SKUs while keeping each variant’s stock and price separate.</p>
</div></header>
<?php if (($errors ?? []) !== []): ?><div class="admin-error" role="alert"><strong>Please correct the family details.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
<section class="admin-panel">
    <div class="admin-panel-heading"><h2><?= isset($values['family_id']) ? 'Edit family' : 'Create a family' ?></h2></div>
    <form class="admin-form" action="/admin/family/save" method="post">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <?php if (isset($values['family_id'])): ?><input type="hidden" name="family_id" value="<?= $e($values['family_id']) ?>"><?php else: ?>
        <label>Family code <input name="family_code" maxlength="80" pattern="[a-z0-9][a-z0-9-]{0,79}" required value="<?= $e($values['family_code'] ?? '') ?>"><span class="help">Stable lowercase identifier, such as monstera or stone-planter.</span></label>
        <?php endif ?>
        <label>Family name <input name="name" maxlength="180" required value="<?= $e($values['name'] ?? '') ?>"></label>
        <label>Product kind <select name="product_kind" required><?php foreach (['plant', 'cut_flower', 'cut_foliage', 'accessory', 'plant_arrangement', 'floral_arrangement', 'other'] as $kind): ?><option value="<?= $e($kind) ?>" <?= ($values['product_kind'] ?? 'plant') === $kind ? 'selected' : '' ?>><?= $e($label($kind)) ?></option><?php endforeach ?></select></label>
        <div class="admin-record-status"><button type="submit">Save family</button><a href="/admin/families">Cancel</a></div>
    </form>
</section>
<section class="admin-panel" aria-labelledby="family-list-heading">
    <div class="admin-panel-heading"><h2 id="family-list-heading">Families and variant SKUs</h2></div>
    <div class="admin-scroll-table" role="region" aria-label="Product families" tabindex="0">
        <table class="admin-table"><thead><tr><th scope="col">Family</th><th scope="col">Code</th><th scope="col">Kind</th><th scope="col">Active SKUs</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($families as $family): ?><tr><th scope="row"><?= $e($family['name']) ?></th><td><?= $e($family['family_code']) ?></td><td><?= $e($label($family['product_kind'])) ?></td><td><?= $e($family['active_variant_count']) ?></td><td><span class="admin-status<?= $family['archived_at'] === null ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $family['archived_at'] === null ? 'Active' : 'Archived' ?></span></td><td>
            <?php if ($family['archived_at'] === null): ?><a href="/admin/families?edit=<?= $e($family['family_id']) ?>">Edit</a><?php endif ?>
            <form class="inline-form" action="/admin/family/archive" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="family_id" value="<?= $e($family['family_id']) ?>"><input type="hidden" name="archive" value="<?= $family['archived_at'] === null ? '1' : '0' ?>"><button type="submit"><?= $family['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form>
        </td></tr><?php endforeach ?>
        <?php if ($families === []): ?><tr><td colspan="6">No families yet.</td></tr><?php endif ?>
        </tbody></table>
    </div>
    <p class="help">Families group existing product SKUs. Each SKU keeps its own physical stock, price and rental capability. Archive active member SKUs before archiving their family.</p>
</section>
