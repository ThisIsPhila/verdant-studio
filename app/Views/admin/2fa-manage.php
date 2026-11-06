<header class="admin-page-heading">
    <div>
        <p>Administration · Security</p>
        <h1>Two-factor authentication</h1>
    </div>
</header>
<?php if (($notice ?? null) !== null): ?><p class="admin-notice" role="status"><?= $e($notice) ?></p><?php endif ?>
<section class="admin-panel" aria-labelledby="totp-codes-heading">
    <h2 id="totp-codes-heading">Backup codes</h2>
    <p><?= $e($remaining) ?> of 10 backup codes remain unused. Issuing a new set immediately retires every old code.</p>
    <form method="post" action="/admin/2fa/codes">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <button type="submit">Issue new backup codes</button>
    </form>
</section>
<section class="admin-panel" aria-labelledby="totp-reset-heading">
    <h2 id="totp-reset-heading">Locked-out colleague</h2>
    <p>If another administrator has lost their device and backup codes, reset their two-factor authentication so they enrol again at the next sign-in.</p>
    <p><a href="<?= $e($resetUrl) ?>">Reset another administrator</a></p>
</section>
