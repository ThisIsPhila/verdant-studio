<?php
require_once __DIR__ . '/../components/_helpers.php';
$visionCurrentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$visionNavItems = [
    ['/catalogue', 'Shop'],
    ['/catalogue?mode=rent', 'Rentals'],
    ['/arrangements', 'Arrangements'],
    ['/configure', 'Configure'],
];
$visionActiveNav = match (true) {
    $visionCurrentPath === '/catalogue' && ($_GET['mode'] ?? '') === 'rent' => 'Rentals',
    $visionCurrentPath === '/catalogue' || $visionCurrentPath === '/product' => 'Shop',
    $visionCurrentPath === '/arrangements' || str_starts_with($visionCurrentPath ?? '', '/collections') => 'Arrangements',
    in_array($visionCurrentPath, ['/configure', '/compositions'], true) => 'Configure',
    default => null,
};
$visionBasketCount = array_sum(array_filter($_SESSION['_retail_cart'] ?? [], static fn ($q): bool => is_int($q) && $q > 0));
?>
<header class="site-header border-b bg-card">
  <div class="vision-header__inner">
    <a class="block leading-none" href="/"
      ><div
        class="font-display tracking-[0.3em] text-xl !tracking-[0.15em] min-[360px]:text-[1.375rem] min-[360px]:!tracking-[0.2em] sm:text-3xl sm:!tracking-[0.3em]"
      >
        VERDANT
      </div>
      <div
        class="mt-1 tracking-[0.25em] text-muted-foreground uppercase text-[0.3125rem] min-[360px]:text-[0.375rem] sm:text-[0.5rem]"
      >
        Botanical nursery &amp; plant studio
      </div></a
    >
    <nav class="vision-header__navigation" aria-label="Main navigation">
      <?php foreach ($visionNavItems as [$navHref, $navLabel]): ?>
        <a href="<?= $e($navHref) ?>" <?= $visionActiveNav === $navLabel ? 'aria-current="page"' : '' ?>><?= $e($navLabel) ?></a>
      <?php endforeach ?>
    </nav>
    <div class="vision-header__utilities">
      <details class="vision-header__search">
        <summary aria-label="Search products"><?= verdant_view_icon('search') ?></summary>
        <form class="vision-header__search-panel" action="/catalogue" method="get" role="search">
          <label for="header-search">Search products</label>
          <input id="header-search" type="search" name="q" maxlength="120" placeholder="Search plants, pots and more" value="<?= $e($filters['q'] ?? '') ?>">
          <button type="submit" class="vision-small-button">Search</button>
        </form>
      </details>
      <?php foreach ([['/account#account-saved', 'Saved', 'heart'], ['/cart', 'Basket', 'bag'], ['/account', 'Account', 'account']] as [$utilityHref, $utilityLabel, $utilityIcon]): ?>
        <a class="vision-header__utility" href="<?= $e($utilityHref) ?>" aria-label="<?= $e($utilityLabel) ?>">
          <?= verdant_view_icon($utilityIcon) ?>
          <?php if ($utilityIcon === 'account' && ($supportUnread ?? 0) > 0): ?><span class="vision-header__count" aria-label="<?= $e($supportUnread) ?> unread support messages"><?= $e($supportUnread) ?></span><?php endif ?>
          <?php if ($utilityIcon === 'bag'): ?><span class="vision-header__count" aria-label="<?= $e($visionBasketCount) ?> items in basket"><?= $e($visionBasketCount) ?></span><?php endif ?>
        </a>
      <?php endforeach ?>
      <details data-site-menu class="vision-header__menu">
        <summary aria-label="Open navigation menu"><?= verdant_view_icon('menu') ?></summary>
        <nav aria-label="Mobile navigation">
          <?php foreach ($visionNavItems as [$navHref, $navLabel]): ?>
            <a href="<?= $e($navHref) ?>" <?= $visionActiveNav === $navLabel ? 'aria-current="page"' : '' ?>><?= $e($navLabel) ?></a>
          <?php endforeach ?>
        </nav>
      </details>
    </div>
  </div>
</header>
