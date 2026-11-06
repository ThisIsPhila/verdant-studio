<header class="admin-page-heading">
    <div>
        <p>Administration · Security</p>
        <h1>Access allowlist</h1>
        <p>Only listed addresses may sign in to administration, on top of the administrator role. Customers can never sign in here.</p>
    </div>
</header>

<?php if (!empty($notice)): ?><p class="admin-notice" role="status"><?= $e($notice) ?></p><?php endif ?>
<?php if (!empty($errors)): ?><div class="alert" role="alert"><h2>Access list not updated</h2><ul><?php foreach ($errors as $error): ?><li><?= $e((string) $error) ?></li><?php endforeach ?></ul></div><?php endif ?>

<section class="admin-panel" aria-labelledby="allowlist-add-title">
    <div class="admin-panel-heading">
        <h2 id="allowlist-add-title">Add an administrator</h2>
        <p>The address must already belong to an active administrator account.</p>
    </div>
    <form action="/admin/access/add" method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <div class="field">
            <label for="allowlist-email">Email address</label>
            <input id="allowlist-email" name="email" type="email" maxlength="254" required value="<?= $e((string) ($email ?? '')) ?>">
        </div>
        <button type="submit" class="btn-solid">Add to allowlist</button>
    </form>
</section>

<section class="admin-panel" aria-labelledby="allowlist-entries-title">
    <div class="admin-panel-heading">
        <h2 id="allowlist-entries-title">Listed administrators</h2>
        <p><?= $e(count($entries ?? [])) ?> listed</p>
    </div>
    <?php if (($entries ?? []) === []): ?>
        <p class="admin-empty" role="status">No administrators are listed.</p>
    <?php else: ?>
        <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Allowlisted administrators">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Email</th>
                        <th scope="col">Added by</th>
                        <th scope="col">Listed</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <th scope="row"><?= $e($entry['email']) ?></th>
                            <td><?= $e($entry['added_by'] ?? 'Migration seed') ?></td>
                            <td><?= $e($entry['created_at']) ?></td>
                            <td>
                                <form action="/admin/access/remove" method="post" class="admin-inline-form">
                                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                                    <input type="hidden" name="email" value="<?= $e($entry['email']) ?>">
                                    <button type="submit" class="quiet-button">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
