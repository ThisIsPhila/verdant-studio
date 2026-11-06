<p class="recovery-eyebrow">ACCOUNT ACCESS</p>
<h1 id="recovery-heading">Choose a new password</h1>
<p>Use a passphrase of at least 12 characters. It can contain up to 72 bytes.</p>
<p id="recovery-status" class="recovery-status" role="status" aria-live="polite">Checking your reset link…</p>
<div id="recovery-link-invalid" class="recovery-alert" role="alert" hidden>
    <p>This reset link is invalid or has expired. Request a new link to continue.</p>
    <a href="<?= $e(\Verdant\Http\Url::local('/password-reset/request')) ?>">Request a new reset link</a>
</div>
<form id="recovery-reset-form" method="post" action="<?= $e(\Verdant\Http\Url::local('/password-reset')) ?>"
      class="recovery-form" data-validation-url="<?= $e($validationUrl) ?>" hidden>
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <input id="recovery-token" type="hidden" name="token" value="<?= $e($token) ?>">
    <?php if ($errors): ?><div class="recovery-alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
    <div class="recovery-field">
        <label for="new-password">New password</label>
        <input id="new-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
    </div>
    <div class="recovery-field">
        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
    </div>
    <button type="submit" class="recovery-primary">Save new password</button>
</form>
<noscript><p class="recovery-note">Enable JavaScript to validate the private reset link before setting a new password.</p></noscript>
