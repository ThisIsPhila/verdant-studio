<?php
$visionPage = true;
$hero = ['image' => '/assets/vision/images/hero-basket.jpg', 'title' => 'Your basket', 'body' => 'Plants and floral styling for brighter spaces.'];
require __DIR__ . '/vision/page-hero.php';
?>
<section class="vision-container vision-basket-layout">
    <div>
        <h2 class="text-4xl">Items to purchase</h2><p class="vision-muted">These items will be yours to keep.</p>
        <?php if ($checkoutValidated ?? false): ?><p class="session-note" role="status">Your basket is ready for checkout.</p><?php endif ?>
        <?php if (($errors ?? []) !== []): ?><div class="alert" role="alert"><h2>Please review your basket</h2><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
        <?php if (!$items): ?><div class="empty-state"><h2>Your basket is empty</h2><p>Explore the collection and add something you would like to own.</p></div><?php else: ?>
            <ul class="basket-cards" aria-label="Basket items"><?php foreach ($items as $item): require __DIR__ . '/vision/basket-row.php'; endforeach ?></ul>
        <?php endif ?>
        <a class="btn-outline" href="/catalogue">← Continue shopping</a>
    </div>
    <aside class="vision-basket-summary">
        <h2 class="text-4xl">Order summary</h2>
        <p class="vision-summary-line"><span>Purchase items (<?= $e(count($items)) ?>)</span><span><?= $e($money($total_minor)) ?></span></p>
        <p class="vision-basket-total"><span>Total</span><strong><?= $e($money($total_minor)) ?></strong></p>
        <?php if ($valid): ?><form action="/checkout" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><button type="submit" class="btn-solid">Continue to checkout →</button></form><p class="vision-muted">Prices and availability are checked before payment. Items are not reserved yet.</p><?php elseif ($items): ?><p>Update or remove unavailable items before continuing.</p><?php endif ?>
        <section class="vision-basket-help"><h3>Rental arrangements</h3><p class="vision-muted">Choose your rental dates and review your arrangement before checkout.</p><a class="btn-outline" href="/compositions/saved">View arrangements &amp; checkouts</a></section>
        <?php if ($actor === null): ?><section class="vision-basket-help"><h3>Need an account?</h3><p class="vision-muted">Sign in to view your orders and rentals.</p><a class="btn-outline" href="/login">Sign in →</a></section><?php endif ?>
        <section class="vision-basket-help"><h3>Questions?</h3><p class="vision-muted">We’re here to help with orders, rentals and styling.</p><a class="link-caps" href="mailto:hello@verdant.co.za">Contact us →</a></section>
    </aside>
</section>
<script src="/assets/js/vision-basket.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/vision-basket.js')) ?>" defer></script>
