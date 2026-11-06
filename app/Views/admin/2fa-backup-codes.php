<p class="admin-eyebrow">Administrator access</p>
<h1>Save your backup codes</h1>
<p>Store these codes somewhere safe. Each code signs in once if your authenticator app is unavailable, and old codes stop working when new ones are issued.</p>
<ul>
    <?php foreach ($codes as $code): ?><li><code><?= $e($code) ?></code></li><?php endforeach ?>
</ul>
<p><a href="<?= $e($continueUrl) ?>">Continue</a></p>
