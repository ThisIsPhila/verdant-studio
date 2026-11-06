<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Account recovery') ?> · Verdant Studio</title>
    <link rel="stylesheet" href="/assets/css/password-recovery.css?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/css/password-recovery.css')) ?>">
    <?php if (($template ?? '') === 'password-recovery/reset'): ?>
    <script src="/assets/js/password-recovery.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/password-recovery.js')) ?>" defer></script>
    <?php endif ?>
</head>
<body class="password-recovery">
    <a class="recovery-skip" href="#main">Skip to content</a>
    <header class="recovery-header"><a href="<?= $e(\Verdant\Http\Url::local('/login')) ?>" class="recovery-brand">VERDANT<span>BOTANICAL NURSERY &amp; PLANT STUDIO</span></a></header>
    <main id="main" class="recovery-main" tabindex="-1">
        <section class="recovery-card" aria-labelledby="recovery-heading">
            <?= $content ?>
        </section>
    </main>
    <footer class="recovery-footer">Your account details are protected. This page does not load third-party services.</footer>
</body>
</html>
