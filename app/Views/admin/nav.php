<?php
$activeAdminPath = \Verdant\Admin\AdminNavigation::activePath($_SERVER['REQUEST_URI'] ?? '/admin');
$adminGroups = \Verdant\Admin\AdminNavigation::groups();
?>
<nav class="admin-nav" aria-label="Administration">
    <a class="admin-nav-item<?= $activeAdminPath === '/admin' ? ' is-active' : '' ?>" href="/admin"<?= $activeAdminPath === '/admin' ? ' aria-current="page"' : '' ?>>
        <?= verdant_view_icon('leaf') ?><span>Overview</span>
    </a>
    <?php foreach ($adminGroups as $groupLabel => $links): ?>
    <section class="admin-nav-group" aria-label="<?= $e($groupLabel) ?>">
        <h2 class="admin-nav-group-label"><?= $e($groupLabel) ?></h2>
        <div class="admin-nav-group-links">
        <?php foreach ($links as [$href, $text, $icon]): ?>
    <a class="admin-nav-item<?= $activeAdminPath === $href ? ' is-active' : '' ?>" href="<?= $e($href) ?>"<?= $activeAdminPath === $href ? ' aria-current="page"' : '' ?>>
        <?= verdant_view_icon($icon) ?><span><?= $e($text) ?><?php if ($href === '/admin/enquiries' && ($supportUnread ?? 0) > 0): ?> <span class="support-unread" aria-label="<?= $e($supportUnread) ?> unread support messages"><?= $e($supportUnread) ?></span><?php endif ?></span>
    </a>
        <?php endforeach ?>
        </div>
    </section>
    <?php endforeach ?>
</nav>
