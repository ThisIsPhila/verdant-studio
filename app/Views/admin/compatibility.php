<header class="admin-page-heading"><div>
    <p>Catalogue management · Product selection</p>
    <h1>Item compatibility</h1>
    <p>Choose which pots are offered with each plant. Record whether their physical fit has been checked.</p>
</div></header>
<?php if (($errors ?? []) !== []): ?><div class="admin-error" role="alert"><strong>Please correct the compatibility details.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
<section class="admin-panel">
        <div class="admin-panel-heading"><h2>Record a compatible pair</h2></div>
    <?php if ($plants === [] || $pots === []): ?><p class="account-empty">Create an active plant SKU and classify an active accessory SKU as a pot before adding a pairing.</p><?php else: ?>
    <form class="admin-form" action="/admin/compatibility/save" method="post">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <label>Plant <select name="plant_product_id" required><option value="">Choose a plant</option><?php foreach ($plants as $plant): ?><option value="<?= $e($plant['product_id']) ?>" <?= (string) ($values['plant_product_id'] ?? '') === (string) $plant['product_id'] ? 'selected' : '' ?>><?= $e($plant['name']) ?><?= ($plant['variant_label'] ?? '') !== '' ? ' · ' . $e($plant['variant_label']) : '' ?> · <?= $e($plant['sku']) ?></option><?php endforeach ?></select></label>
        <label>Pot <select name="pot_product_id" required><option value="">Choose a pot</option><?php foreach ($pots as $pot): ?><option value="<?= $e($pot['product_id']) ?>" <?= (string) ($values['pot_product_id'] ?? '') === (string) $pot['product_id'] ? 'selected' : '' ?>><?= $e($pot['name']) ?><?= ($pot['variant_label'] ?? '') !== '' ? ' · ' . $e($pot['variant_label']) : '' ?> · <?= $e($pot['sku']) ?></option><?php endforeach ?></select></label>
        <label>Physical fit <select name="source_status" required><option value="demonstration" <?= ($values['source_status'] ?? 'demonstration') === 'demonstration' ? 'selected' : '' ?>>Fit not checked</option><option value="owner_verified" <?= ($values['source_status'] ?? '') === 'owner_verified' ? 'selected' : '' ?>>Fit checked</option></select></label>
        <div class="admin-record-status"><button type="submit">Save pairing</button></div>
    </form><?php endif ?>
</section>
<section class="admin-panel" aria-labelledby="compatibility-list-heading">
    <div class="admin-panel-heading"><h2 id="compatibility-list-heading">Recorded pairings</h2></div>
    <div class="admin-scroll-table" role="region" aria-label="Item compatibility records" tabindex="0">
        <table class="admin-table"><thead><tr><th scope="col">Plant</th><th scope="col">Pot</th><th scope="col">Physical fit</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>
        <?php foreach ($compatibilities as $pair): ?><tr><th scope="row"><?= $e($pair['plant_name']) ?><?= ($pair['plant_variant'] ?? '') !== '' ? ' · ' . $e($pair['plant_variant']) : '' ?> · <?= $e($pair['plant_sku']) ?></th><td><?= $e($pair['pot_name']) ?><?= ($pair['pot_variant'] ?? '') !== '' ? ' · ' . $e($pair['pot_variant']) : '' ?> · <?= $e($pair['pot_sku']) ?></td><td><?= $pair['source_status'] === 'demonstration' ? 'Fit not checked' : 'Fit checked' ?></td><td><span class="admin-status<?= $pair['archived_at'] === null ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $pair['archived_at'] === null ? 'Active' : 'Archived' ?></span></td><td><form class="inline-form" action="/admin/compatibility/archive" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="compatibility_id" value="<?= $e($pair['compatibility_id']) ?>"><input type="hidden" name="archive" value="<?= $pair['archived_at'] === null ? '1' : '0' ?>"><button type="submit"><?= $pair['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form></td></tr><?php endforeach ?>
        <?php if ($compatibilities === []): ?><tr><td colspan="5">No pairings recorded.</td></tr><?php endif ?>
        </tbody></table>
    </div>
    <p class="help">Pairings do not combine inventory or promise verified physical fit. Each selected SKU keeps its own price, stock and buy/rent rules.</p>
</section>
