<?php
$cardCare = [];
$displayMode = ($product['display_mode'] ?? '') === 'rent' ? 'rent' : 'all';
$rentalOnlyCard = $displayMode === 'rent';
$featuredCard = !empty($compactCard) && !$rentalOnlyCard;
$rentalDates = is_array($rentalDates ?? null) ? $rentalDates : [];
$rentalQuote = is_array($product['rental_quote'] ?? null) ? $product['rental_quote'] : null;
$rentalHref = '/rental?product_id=' . rawurlencode((string) $product['product_id']);
if (($rentalDates['start_date'] ?? '') !== '' && ($rentalDates['end_date'] ?? '') !== '') {
    $rentalHref .= '&' . http_build_query([
        'start_date' => $rentalDates['start_date'],
        'end_date' => $rentalDates['end_date'],
    ]);
}
if (($product['product_kind'] ?? 'other') === 'plant') {
    foreach (['light_level' => 'Light', 'watering_level' => 'Water', 'toxicity' => 'Safety'] as $careKey => $careCaption) {
        $careValue = $product[$careKey] ?? 'unknown';
        if (is_string($careValue) && $careValue !== '' && $careValue !== 'unknown') {
            $cardCare[] = $careCaption . ': ' . $label($careValue);
        }
    }
}
$cardCategory = $featuredCard
    ? $label($product['product_kind'] ?? 'Botanical collection')
    : ($product['category_name'] ?? 'Botanical collection') . ' · ' . $label($product['product_kind'] ?? 'other');
$cardRetailPrice = $featuredCard
    ? ($product['card_price_minor'] ?? $product['retail_price_minor'] ?? null)
    : ($product['retail_price_minor'] ?? null);
$cardClasses = 'product-card catalogue-card';
if (!empty($compactCard)) {
    $cardClasses .= ' product-card--compact';
}
if ($rentalOnlyCard) {
    $cardClasses .= ' product-card--rental';
}
?>
<article class="<?= $e($cardClasses) ?>">
    <div class="product-card__media">
        <?php if ($featuredCard): ?>
            <span class="product-card__badge<?= empty($product['rent_enabled']) ? ' product-card__badge--buy' : '' ?>"><?= !empty($product['rent_enabled']) ? 'Buy or rent' : 'Buy' ?></span>
            <button class="product-card__save" type="button" data-save-product="<?= $e($product['product_id']) ?>" aria-label="Save <?= $e($product['name']) ?>" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
                    <path d="M12 21 3.5 12.5C-2 7 6 0 12 6c6-6 14 1 8.5 6.5Z"/>
                </svg>
            </button>
        <?php endif ?>
        <a class="product-image" href="/product?id=<?= $e(rawurlencode((string) $product['product_id'])) ?>">
            <?php require __DIR__ . '/product-thumbnail.php'; ?>
        </a>
    </div>
    <div class="product-copy">
        <p class="eyebrow"><?= $e($cardCategory) ?></p>
        <h2><a href="/product?id=<?= $e(rawurlencode((string) $product['product_id'])) ?>"><?= $e($product['name']) ?></a></h2>
        <?php if (empty($compactCard) && ($product['size_label'] ?? '') !== '' && strtolower((string) $product['size_label']) !== 'unknown'): ?>
            <p class="care-summary">
                <?= $e($product['size_label']) ?>
                <?php if (($product['selling_unit'] ?? '') !== ''): ?>
                    · Sold by <?= $e($product['selling_unit']) ?>
                <?php endif ?>
            </p>
        <?php endif ?>
        <?php if (empty($compactCard) && $cardCare !== []): ?>
            <p class="care-summary"><?= $e(implode(' · ', $cardCare)) ?></p>
        <?php endif ?>
        <div class="badges" aria-label="Available modes"<?= $featuredCard ? ' hidden' : '' ?>>
            <?php if (!$rentalOnlyCard && !empty($product['buy_enabled'])): ?>
                <span>Buy</span>
            <?php endif ?>
            <?php if (!empty($product['rent_enabled'])): ?>
                <span>Rent</span>
            <?php endif ?>
        </div>
        <div class="prices">
            <?php if (!$rentalOnlyCard && !empty($product['buy_enabled'])): ?>
                <span><?= $e($money($cardRetailPrice)) ?><small>to own</small></span>
            <?php endif ?>
            <?php if (!empty($product['rent_enabled'])): ?>
                <span><?= $e($money($product['rental_daily_rate_minor'] ?? null)) ?><small>per day</small></span>
            <?php endif ?>
        </div>
        <?php if ($rentalOnlyCard && $rentalQuote !== null): ?>
            <?php if (!empty($rentalQuote['unavailable'])): ?>
                <p class="session-note" role="status">This rental is no longer available for those dates.</p>
            <?php elseif (!empty($rentalQuote['available'])): ?>
                <p class="care-summary" role="status">
                    <?= $e($rentalQuote['available_quantity']) ?> available for <?= $e($rentalQuote['days']) ?> day<?= (int) $rentalQuote['days'] === 1 ? '' : 's' ?>
                    · <?= $e($money($rentalQuote['total_minor'])) ?> for one item
                </p>
            <?php else: ?>
                <p class="session-note" role="status">Unavailable for those dates. Choose another period to check again.</p>
            <?php endif ?>
        <?php endif ?>
        <?php if ($featuredCard): ?>
            <div class="product-card__reviews" aria-label="No customer ratings yet"><span aria-hidden="true">☆☆☆☆☆</span> <small>(0)</small></div>
        <?php endif ?>
        <div class="catalogue-card-actions">
            <?php if (!$rentalOnlyCard && !empty($product['buy_enabled'])): ?>
                <?php if (($product['product_kind'] ?? '') === 'plant' && (empty($compactCard) || empty($product['card_pot_id']))): ?>
                    <a class="button" href="/product?id=<?= $e($product['product_id']) ?>">Choose plant &amp; pot</a>
                <?php else: ?>
                    <form action="/cart/add" method="post">
                        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                        <input type="hidden" name="product_id" value="<?= $e($product['product_id']) ?>">
                        <input type="hidden" name="quantity" value="1">
                        <?php if (!empty($product['card_pot_id'])): ?>
                            <input type="hidden" name="pot_id" value="<?= $e($product['card_pot_id']) ?>">
                        <?php endif ?>
                        <button class="button" type="submit">Add to cart</button>
                    </form>
                <?php endif ?>
            <?php endif ?>
            <?php if (($rentalOnlyCard || empty($compactCard)) && !empty($product['rent_enabled'])): ?>
                <a class="button button--secondary" href="<?= $e($rentalHref) ?>"><?= $rentalQuote !== null && !empty($rentalQuote['available']) ? 'Continue with these dates' : 'Check rental dates' ?></a>
            <?php endif ?>
        </div>
        <?php if (empty($compactCard)): ?>
            <a class="text-link" href="/product?id=<?= $e(rawurlencode((string) $product['product_id'])) ?>">Product details <span aria-hidden="true">↗</span></a>
        <?php endif ?>
    </div>
</article>
