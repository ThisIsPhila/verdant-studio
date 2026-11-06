<nav class="account-navigation" aria-label="Account sections">
    <?php foreach ([['account-orders','Orders','bag'],['account-rentals','Rentals','calendar'],['account-saved','Saved configurations','heart'],['account-support','Support','menu'],['account-profile','Profile','account']] as [$navId,$navName,$navIcon]): ?>
        <a class="account-navigation__link" href="#<?= $e($navId) ?>" aria-label="<?= $e($navName) ?>" title="<?= $e($navName) ?>"><?= verdant_view_icon($navIcon) ?><span class="sr-only lg:not-sr-only"><?= $e($navName) ?></span></a>
    <?php endforeach ?>
</nav>
