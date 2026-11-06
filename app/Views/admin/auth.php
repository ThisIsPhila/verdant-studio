<p class="admin-eyebrow">Administrator access</p>
<h1 id="admin-sign-in-heading">Sign in to the admin site</h1>
<p>Use an account approved for administrator access. Customer accounts cannot open the admin workspace.</p>
<?php if ($passwordResetComplete ?? false): ?><div class="admin-alert" role="status">Your password has been changed. Sign in with your new password.</div><?php endif ?>
<?php if ($errors): ?><div class="admin-alert" role="alert"><h2>Sign-in failed</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<form method="post" action="/login">
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <?php if ($returnTo !== null): ?><input type="hidden" name="return_to" value="<?= $e($returnTo) ?>"><?php endif ?>
    <div class="field">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required value="<?= $e($values['email']) ?>">
    </div>
    <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <p><a href="<?= $e(\Verdant\Http\Url::local('/password-reset/request')) ?>">Forgot your password?</a></p>
    <button type="submit">Sign in</button>
</form>
