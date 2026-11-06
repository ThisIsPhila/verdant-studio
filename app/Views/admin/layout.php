<?php
require_once __DIR__ . '/../components/_helpers.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Administration') ?> · Verdant Studio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500&family=Jost:wght@300;400;500&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/css/admin.css')) ?>">
    <?php foreach (['admin-content', 'admin-calendar', 'admin-email', 'support'] as $componentStylesheet):
        $componentFile = dirname(__DIR__, 3) . '/public/assets/css/' . $componentStylesheet . '.css';
        if (!is_file($componentFile)) continue; ?>
    <link rel="stylesheet" href="/assets/css/<?= $e($componentStylesheet) ?>.css?v=<?= $e((string) filemtime($componentFile)) ?>">
    <?php endforeach; ?>
    <script src="/assets/js/admin-navigation.js" defer></script>
    <?php if (!empty($calendarPage)): ?>
    <script src="/assets/js/admin-rental-calendar.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/admin-rental-calendar.js')) ?>" defer></script>
    <?php endif ?>
</head>
<body class="layout-internal admin-app">
<a class="skip-link" href="#main">Skip to content</a>
<header class="admin-header">
    <div class="admin-brand-group">
        <button class="admin-menu-toggle" type="button" aria-expanded="false" aria-controls="admin-navigation" hidden>Menu</button>
        <a class="admin-logo" href="/admin">VERDANT<span>INTERNAL · ADMIN</span></a>
    </div>
    <div class="admin-identity">
        <?= verdant_view_icon('account') ?>
        <div><?= $e($actor['name'] ?? 'Administrator') ?><small>Administrator</small></div>
        <form action="/logout" method="post">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <button class="quiet-button" type="submit">Sign out</button>
        </form>
    </div>
</header>
<div class="admin-layout">
    <aside id="admin-navigation" class="admin-sidebar">
        <?php require __DIR__ . '/nav.php'; ?>
        <a class="admin-store-link" href="<?= $e(\Verdant\Config\TrustedOrigins::store()) ?>">View store ↗</a>
    </aside>
    <main id="main" class="admin-main" tabindex="-1"><?= $content ?></main>
</div>
</body>
</html>
