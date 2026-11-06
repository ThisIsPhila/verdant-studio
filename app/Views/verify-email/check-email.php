<p class="recovery-eyebrow">ACCOUNT ACCESS</p>
<h1 id="recovery-heading">Check your email</h1>
<p>Your account has been created. We sent a verification link to <?= $e($email) ?>; use it within 24 hours to activate your account.</p>
<p class="recovery-note">No message yet? <a href="<?= $e(\Verdant\Http\Url::local('/verify-email/resend?email=' . rawurlencode($email))) ?>">Resend the verification email</a>.</p>
