<header class="admin-page-heading">
    <div>
        <p>Administration · Security</p>
        <h1>Reset two-factor authentication</h1>
        <p><a href="/admin/2fa/codes">← Two-factor authentication</a></p>
    </div>
</header>
<section class="admin-panel" aria-labelledby="totp-reset-form-heading">
    <h2 id="totp-reset-form-heading">Find the administrator</h2>
    <?php if (($result ?? null) !== null): ?><p class="admin-notice" role="status"><?= $e($result) ?></p><?php endif ?>
    <form method="post" action="/admin/2fa/reset">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <div class="field">
            <label for="totp-reset-email">Administrator email address</label>
            <input id="totp-reset-email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $e($email) ?>">
        </div>
        <button type="submit">Reset two-factor authentication</button>
    </form>
</section>
