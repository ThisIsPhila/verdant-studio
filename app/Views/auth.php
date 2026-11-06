<?php
$visionPage = true;
$hero = ['image' => '/assets/vision/images/hero-account.jpg', 'title' => $mode === 'register' ? 'Grow with Verdant' : 'Welcome back', 'body' => 'Your plants, purchases and rental bookings, all in one place.'];
require __DIR__ . '/vision/page-hero.php';
?>
<section class="vision-container vision-auth-layout">
    <div><h2 class="text-4xl"><?= $mode === 'register' ? 'Join our growing community' : 'Your corner of the nursery' ?></h2><p class="vision-muted">Find your next plant, keep your favourite arrangements and follow your orders.</p><a class="btn-outline" href="/catalogue">Explore the collection →</a></div>
    <section class="vision-auth-card"><h2 class="text-3xl"><?= $mode === 'register' ? 'Create your account' : 'Sign in to your account' ?></h2><p class="vision-muted"><?= $mode === 'register' ? 'A place for your botanical purchases and rental bookings.' : 'Access your orders, rentals and saved configurations.' ?></p><?php if ($passwordResetComplete ?? false): ?><p class="alert" role="status">Your password has been changed. Sign in with your new password.</p><?php endif ?><?php require __DIR__ . '/vision/auth-form.php'; ?></section>
</section>
<script src="/assets/js/vision-password.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/vision-password.js')) ?>" defer></script>
