<?php
require_once __DIR__ . '/../components/_helpers.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Administrator sign in') ?> · Verdant Studio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500&family=Jost:wght@300;400;500&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/css/admin.css')) ?>">
</head>
<body class="admin-app">
<a class="skip-link" href="#main">Skip to content</a>
<header class="admin-header">
    <div class="admin-brand-group">
        <a class="admin-logo" href="/login">VERDANT<span>ADMINISTRATION</span></a>
    </div>
</header>
<main id="main" class="admin-main admin-auth-main" tabindex="-1">
    <?php if (!empty($auth_split)): ?>
    <section class="admin-auth-card" aria-labelledby="admin-sign-in-heading">
        <div class="admin-auth-media"><img src="/assets/vision/images/hero-plants.jpg" alt="" fetchpriority="high"></div>
        <div class="admin-auth-form"><?= $content ?></div>
    </section>
    <?php else: ?>
    <section class="admin-panel" aria-labelledby="admin-sign-in-heading">
        <?= $content ?>
    </section>
    <?php endif ?>
</main>
</body>
</html>
