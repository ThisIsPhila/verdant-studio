<p class="admin-eyebrow">Administrator access</p>
<h1>Check your authenticator app</h1>
<p>Enter the 6-digit code from your authenticator app to finish signing in.</p>
<?php if ($errors): ?><div class="admin-alert" role="alert"><h2>Code not accepted</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<form method="post" action="/admin/2fa">
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <div class="field">
        <label for="totp-code">Authentication code</label>
        <input id="totp-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
    </div>
    <button type="submit">Verify and sign in</button>
</form>
<p>Lost your device? Enter one of your 8-character backup codes instead.</p>
