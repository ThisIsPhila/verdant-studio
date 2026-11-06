<?php if ($errors): ?><div class="alert" role="alert"><h2>Please check your details</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<form method="post" action="/<?= $e($mode) ?>"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><?php if (($intent ?? null) === 'support'): ?><input type="hidden" name="intent" value="support"><?php endif ?>
<?php if ($mode === 'register'): ?><div class="field"><label for="name">Name</label><input id="name" name="name" autocomplete="name" maxlength="180" required value="<?= $e($values['name']) ?>" <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>><?php if (isset($errors['name'])): ?><span class="field-error" id="name-error"><?= $e($errors['name']) ?></span><?php endif ?></div><?php endif ?>
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="<?= $mode === 'register' ? 'email' : 'username' ?>" maxlength="254" required value="<?= $e($values['email']) ?>" <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>><?php if (isset($errors['email'])): ?><span class="field-error" id="email-error"><?= $e($errors['email']) ?></span><?php endif ?></div>
<?php
$passwordField = [
    'id' => 'password',
    'label' => 'Password',
    'autocomplete' => $mode === 'register' ? 'new-password' : 'current-password',
    'help' => $mode === 'register' ? 'Use a passphrase of at least 12 characters. Maximum 72 bytes; accented characters and emoji may use more than one byte.' : '',
    'error' => $errors['password'] ?? null,
];
require __DIR__ . '/password-field.php';
if ($mode === 'register'):
    $passwordField = [
        'id' => 'password_confirmation',
        'label' => 'Confirm password',
        'toggle_label' => 'password confirmation',
        'autocomplete' => 'new-password',
        'error_id' => 'confirmation-error',
        'error' => $errors['password_confirmation'] ?? null,
    ];
    require __DIR__ . '/password-field.php';
?><div class="field"><label for="phone">Phone <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="30" value="<?= $e($values['phone']) ?>" <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>><?php if (isset($errors['phone'])): ?><span class="field-error" id="phone-error"><?= $e($errors['phone']) ?></span><?php endif ?></div><?php endif ?>
<?php if ($mode === 'login'): ?><p class="auth-recovery-link"><a href="<?= $e(\Verdant\Http\Url::local('/password-reset/request')) ?>">Forgot your password?</a><?php if ($verificationPending ?? false): ?> · <a href="<?= $e(\Verdant\Http\Url::local('/verify-email/resend?email=' . rawurlencode($values['email'] ?? ''))) ?>">Resend verification email</a><?php endif ?></p><?php endif ?>
<button class="button button--primary" type="submit"><?= $mode === 'register' ? 'Create account' : 'Sign in' ?></button></form><p><?= $mode === 'register' ? 'Already part of the nursery?' : 'New to Verdant?' ?> <a href="/<?= $mode === 'register' ? 'login' : 'register' ?><?= ($intent ?? null) === 'support' ? '?intent=support' : '' ?>"><?= $mode === 'register' ? 'Sign in' : 'Create an account' ?></a></p>
