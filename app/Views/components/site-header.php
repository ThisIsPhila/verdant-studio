<?php
$internalLayout = ($layoutName ?? 'customer') === 'internal';
$cartCount = array_sum(array_filter(
    $_SESSION['_retail_cart'] ?? [],
    static fn ($quantity): bool => is_int($quantity) && $quantity > 0
));
$shopLinks = [
    'Plants' => '/catalogue?kind=plant',
    'Flowers' => '/catalogue?kind=flower',
    'Pots' => '/catalogue?kind=pot',
    'Tools' => '/catalogue?kind=tool',
    'Rentals' => '/catalogue?mode=rent',
    'Journal' => '#journal',
    'About' => '#about',
];
require_once __DIR__ . '/_helpers.php';
?>
<header class="site-header<?= $internalLayout ? ' site-header--internal' : '' ?>">
    <a class="brand" href="<?= $internalLayout ? '/admin' : '/' ?>">
        VERDANT<span>Botanical nursery &amp; plant studio</span>
    </a>
    <?php if ($internalLayout): ?>
        <nav aria-label="Account">
            <a href="/catalogue">View store</a>
            <a href="/account">My account</a>
        </nav>
    <?php else: ?>
        <nav class="site-header__primary" aria-label="Main">
            <?php foreach ($shopLinks as $shopLabel => $shopHref): ?>
                <a href="<?= $e($shopHref) ?>"><?= $e($shopLabel) ?></a>
            <?php endforeach ?>
        </nav>
        <nav class="site-header__utilities" aria-label="Shopping tools">
            <a class="site-header__search" href="/catalogue#catalogue-heading" aria-label="Browse and filter products">
                <?= verdant_view_icon('search') ?>
                <span class="site-header__utility-label">Search</span>
            </a>
            <a href="<?= !empty($actor) ? '/account' : '/login' ?>" aria-label="My account">
                <?= verdant_view_icon('account') ?>
                <span class="site-header__utility-label">Account</span>
            </a>
            <a href="/cart" aria-label="Basket (<?= $e($cartCount) ?>)">
                <?= verdant_view_icon('bag') ?>
                <span class="site-header__utility-label">Cart</span> (<?= $e($cartCount) ?>)
            </a>
        </nav>
        <details class="site-menu<?= !empty($actor) ? ' site-menu--account' : '' ?>" data-site-menu>
            <summary aria-label="Open navigation menu"><?= verdant_view_icon('menu') ?></summary>
            <div class="site-menu__panel">
                <nav aria-label="Mobile navigation">
                    <?php foreach ($shopLinks as $shopLabel => $shopHref): ?>
                        <a href="<?= $e($shopHref) ?>"><?= $e($shopLabel) ?></a>
                    <?php endforeach ?>
                    <a href="/catalogue#catalogue-heading">Browse &amp; filter</a>
                    <?php if (!empty($actor)): ?>
                        <a href="/account">My account</a>
                        <?php if (($actor['role'] ?? null) === 'admin'): ?>
                            <a href="/admin">Administration</a>
                        <?php endif ?>
                    <?php else: ?>
                        <a href="/login">Sign in</a>
                        <a href="/register">Create account</a>
                    <?php endif ?>
                </nav>
                <?php if (!empty($actor)): ?>
                    <form action="/logout" method="post">
                        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                        <button type="submit">Sign out</button>
                    </form>
                <?php endif ?>
            </div>
        </details>
    <?php endif ?>
</header>
