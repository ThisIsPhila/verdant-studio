<?php
$visionPage = true;
$visionComponentStyles = ['customer-records'];
?>
<div class="vision-container rental-quote-page" aria-labelledby="rental-quote-title">
    <nav class="customer-back" aria-label="Breadcrumb"><a href="/catalogue?mode=rent">Rental collection</a><span aria-hidden="true">/</span><span>Availability</span></nav>
    <header class="rental-quote-heading">
        <p class="eyebrow">Plan your rental</p>
        <h1 id="rental-quote-title">Check your dates.</h1>
        <p>Choose an item, quantity and inclusive rental period. Availability is checked again before checkout; browsing and quoting do not place a hold.</p>
    </header>

    <?php if ($errors): ?>
        <div class="customer-record-error" role="alert"><h2>Please review these details</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>

    <div class="rental-quote-grid">
        <section class="customer-record-card rental-quote-form-card" aria-labelledby="rental-date-form-title">
            <div class="customer-card-heading"><div><p class="eyebrow">Your rental</p><h2 id="rental-date-form-title">Choose dates and quantity</h2></div></div>
            <form class="rental-quote-form" action="/rental" method="get">
                <input type="hidden" name="product_id" value="<?= $e($input['product_id']) ?>">
                <div class="rental-quote-field"><label for="rental-quantity">Quantity</label><input id="rental-quantity" type="number" name="quantity" min="1" max="10000" value="<?= $e($input['quantity']) ?>" required></div>
                <div class="rental-quote-dates"><div class="rental-quote-field"><label for="rental-start">Start date</label><input id="rental-start" type="date" name="start_date" value="<?= $e($input['start_date']) ?>" required></div><div class="rental-quote-field"><label for="rental-end">End date</label><input id="rental-end" type="date" name="end_date" value="<?= $e($input['end_date']) ?>" required></div></div>
                <button class="button button--primary" type="submit">Check these dates</button>
            </form>
        </section>

        <aside class="rental-quote-aside" aria-live="polite">
            <?php if (is_array($quote)): ?>
                <section class="customer-record-card rental-quote-result" aria-labelledby="rental-quote-summary-title">
                    <p class="eyebrow"><?= $quote['available'] ? 'Dates available' : 'Quantity unavailable' ?></p>
                    <h2 id="rental-quote-summary-title"><?= $e($quote['product_name_snapshot']) ?></h2>
                    <p class="rental-quote-period"><?= $e($date($quote['start_date'])) ?> – <?= $e($date($quote['end_date_exclusive'], true)) ?></p>
                    <dl class="rental-quote-details"><div><dt>Rental period</dt><dd><?= $e($quote['days']) ?> day<?= $quote['days'] === 1 ? '' : 's' ?> · inclusive</dd></div><div><dt>Quantity</dt><dd><?= $e($quote['quantity']) ?></dd></div><div><dt>Available for these dates</dt><dd><?= $e($quote['available_quantity']) ?></dd></div><div><dt>Rate per item per day</dt><dd><?= $e($money($quote['daily_rate_minor'])) ?></dd></div></dl>
                    <div class="rental-quote-total"><span>Estimated total</span><strong><?= $e($money($quote['total_minor'])) ?></strong></div>
                    <?php if ($quote['available']): ?>
                        <form class="customer-record-action" action="/rental/checkout" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="product_id" value="<?= $e($quote['product_id']) ?>"><input type="hidden" name="quantity" value="<?= $e($quote['quantity']) ?>"><input type="hidden" name="start_date" value="<?= $e($input['start_date']) ?>"><input type="hidden" name="end_date" value="<?= $e($input['end_date']) ?>"><button class="button button--primary" type="submit">Reserve these dates</button><p class="customer-muted">Stock is checked again before a reservation is held.</p></form>
                    <?php else: ?><p class="customer-notice" role="status">That quantity is not available for this period. Try fewer items or different dates.</p><?php endif ?>
                </section>
            <?php else: ?>
                <section class="customer-record-card rental-quote-guidance"><p class="eyebrow">What happens next</p><h2>Quote first, reserve at checkout.</h2><p>We’ll show the current daily rate and total for your selected period. Nothing is reserved until the server checks availability during checkout.</p></section>
            <?php endif ?>
        </aside>
    </div>
    <p class="customer-record-footer"><a class="customer-record-link" href="/catalogue?mode=rent">← Return to rental collection</a></p>
</div>
