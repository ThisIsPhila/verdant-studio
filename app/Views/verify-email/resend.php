<p class="recovery-eyebrow">ACCOUNT ACCESS</p>
<h1 id="recovery-heading">Resend verification email</h1>
<p>Enter the email address you registered with and we will send a fresh verification link.</p>
<form method="post" action="<?= $e(\Verdant\Http\Url::local('/verify-email/resend')) ?>">
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <div class="recovery-field">
        <label for="verification-email">Email address</label>
        <input id="verification-email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $e($email) ?>">
    </div>
    <button class="recovery-primary" type="submit">Send a new link</button>
</form>
<p class="recovery-note">If you cannot access that address, <a href="<?= $e($supportUrl) ?>">contact customer support</a>.</p>
