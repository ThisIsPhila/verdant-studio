<p class="admin-eyebrow">Administrator access</p>
<h1>Set up two-factor authentication</h1>
<p>Administrator sign-in requires a second step. Use Google Authenticator, Authy, or any compatible app.</p>
<?php if ($errors): ?><div class="admin-alert" role="alert"><h2>Code not accepted</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<ol>
    <li>Open your authenticator app and choose to enter a setup key manually.</li>
    <li>Enter this key exactly as shown: <code><?= $e($secret) ?></code></li>
    <li>Alternatively, add this provisioning link in an app that accepts one: <input type="text" readonly value="<?= $e($uri) ?>" aria-label="Provisioning link" onclick="this.select()"></li>
    <li>Enter the 6-digit code the app shows for Verdant below.</li>
</ol>
<form method="post" action="/admin/2fa/setup">
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <div class="field">
        <label for="totp-setup-code">Authentication code</label>
        <input id="totp-setup-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
    </div>
    <button type="submit">Confirm and continue</button>
</form>
