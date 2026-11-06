<a class="recovery-back" href="<?= $e(\Verdant\Http\Url::local('/login')) ?>">← Back to sign in</a>
<p class="recovery-eyebrow">ACCOUNT ACCESS</p>
<h1 id="recovery-heading">Reset your password</h1>
<p>Enter the email address you use to sign in. It is your Verdant username.</p>
<p class="recovery-note">If you have forgotten that email address or can no longer access it, <a href="<?= $e($supportUrl) ?>">contact customer support</a>.</p>
<form method="post" action="<?= $e(\Verdant\Http\Url::local('/password-reset/request')) ?>" class="recovery-form">
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <div class="recovery-field">
        <label for="recovery-email">Email address</label>
        <input id="recovery-email" name="email" type="email" autocomplete="username" maxlength="254" required>
    </div>
    <button type="submit" class="recovery-primary">Send reset instructions</button>
</form>
