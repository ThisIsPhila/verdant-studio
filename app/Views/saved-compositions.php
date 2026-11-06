<?php
$visionPage = true;
$visionComponentStyles = ['customer-records'];
?>
<div class="vision-container saved-arrangements-page" aria-labelledby="saved-arrangements-title">
    <header class="saved-arrangements-header">
        <p class="eyebrow">A little garden of ideas</p>
        <h1 id="saved-arrangements-title">Saved arrangements</h1>
        <p>Your saved choices keep their original arrangement details. Current options, prices and availability are checked again when you reopen a configuration.</p>
    </header>

    <section aria-labelledby="saved-list-title">
        <div class="customer-card-heading saved-arrangements-heading"><div><p class="eyebrow">Your collection</p><h2 id="saved-list-title">Saved for later</h2></div><span class="customer-card-note"><?= $e(count($saved)) ?> saved</span></div>
        <?php if (!$saved): ?>
            <div class="customer-empty saved-arrangements-empty" role="status"><p class="eyebrow">Nothing here yet</p><h2>Your saved list is clear.</h2><p>Explore current arrangements and keep a favourite for later.</p><a class="button button--primary" href="/arrangements">Explore arrangements</a></div>
        <?php else: ?>
            <div class="saved-arrangements-grid">
                <?php foreach ($saved as $item): ?>
                    <article class="saved-arrangement-card">
                        <div class="saved-arrangement-content"><div class="saved-arrangement-copy"><p class="eyebrow">Saved arrangement</p><h2><?= $e($item['name']) ?></h2><p><?= $e($item['definition_name_snapshot']) ?></p></div>
                            <div class="saved-arrangement-footer"><a class="button button--primary" href="/compositions?saved_id=<?= $e($item['saved_composition_id']) ?>">Reopen configuration</a><form action="/compositions/saved/archive" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="saved_composition_id" value="<?= $e($item['saved_composition_id']) ?>"><button class="button button--secondary" type="submit">Archive</button></form></div>
                        </div>
                    </article>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </section>

    <section class="saved-checkouts" aria-labelledby="arrangement-checkouts-title">
        <div class="saved-checkouts-heading"><div><p class="eyebrow">Prepared arrangements</p><h2 id="arrangement-checkouts-title">Arrangement checkouts</h2><p>Review your prepared arrangements and their recorded payment state.</p></div></div>
        <?php if (!$checkouts): ?><p class="customer-empty saved-checkouts-empty">You have no arrangement checkouts yet.</p><?php else: ?>
            <ul class="saved-checkout-list"><?php foreach ($checkouts as $checkout): ?><li><div><h3><?= $e($checkout['definition_name_snapshot']) ?></h3><p><?= $e($statusLabel($checkout['state'])) ?> · <?= $e($money($checkout['total_minor'])) ?></p></div><a class="customer-record-link" href="/compositions/checkout?id=<?= $e($checkout['composition_checkout_id']) ?>">View checkout</a></li><?php endforeach ?></ul>
        <?php endif ?>
    </section>
    <p class="customer-record-footer">Need a fresh idea? <a class="customer-record-link" href="/arrangements">Explore all arrangements</a></p>
</div>
