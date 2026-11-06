<?php
$cardSize = $product['display_size'] ?? $product['size_label'] ?? '';
$cardImage = \Verdant\Storefront\VisionLandingPresenter::image($product);
$cardThumbnailMode = is_array($product['thumbnail'] ?? null) ? (string) ($product['thumbnail']['mode'] ?? 'fallback') : 'fallback';
$cardHasInternalMedia = in_array($cardThumbnailMode, ['scene', 'pot'], true);
$cardPlainFallback = $cardImage === null && !$cardHasInternalMedia;
$cardCanBuy = !empty($product['buy_enabled']);
$cardCanRent = !empty($product['rent_enabled']) && !in_array($product['product_kind'] ?? '', ['cut_flower', 'cut_foliage', 'floral_arrangement'], true);
$cardRentalOnly = $cardCanRent && (($filters['mode'] ?? '') === 'rent' || !$cardCanBuy);
$cardRentQuery = ['product_id' => $product['product_id']];
if (!empty($rentalDates['has_dates'])) { $cardRentQuery += ['start_date' => $rentalDates['start_date'], 'end_date' => $rentalDates['end_date']]; }
$cardIsPot = ($product['category_name'] ?? '') === 'Planters & pots';
?>
<article class="product-card catalogue-card">
    <div class="vision-card-image<?= $cardIsPot ? ' vision-card-image--mat' : '' ?>">
        <a href="/product?id=<?= $e($product['product_id']) ?>"<?= $cardPlainFallback ? ' aria-label="' . $e($product['name']) . '"' : '' ?>><?php if ($cardImage !== null): ?><img src="<?= $e($cardImage) ?>" alt="<?= $e($product['name']) ?>" loading="lazy"><?php elseif ($cardHasInternalMedia): require __DIR__ . '/../components/product-thumbnail.php'; else: ?><span class="vision-card-plain" aria-hidden="true"></span><?php endif ?></a>
        <span class="vision-mode-badge <?= $cardCanBuy && !$cardCanRent ? 'vision-mode-badge--buy' : '' ?>"><?= $cardRentalOnly ? 'Rent' : ($cardCanBuy ? ($cardCanRent ? 'Buy or rent' : 'Buy') : 'Rent') ?></span>
        <button type="button" class="vision-save" data-save-product="<?= $e($product['product_id']) ?>" aria-label="Save <?= $e($product['name']) ?>" aria-pressed="false">♡</button>
    </div>
    <h3><a href="/product?id=<?= $e($product['product_id']) ?>"><?= $e($product['name']) ?></a></h3>
    <p class="vision-card-kind"><?= $e($product['category_name'] ?? '') ?><?php if ($cardSize !== '' && !in_array(strtolower($cardSize), ['unknown', 'standard'], true)): ?> · <?= $e($cardSize) ?><?php endif ?></p>
    <?php if (!empty($product['description'])): ?><p class="vision-card-description"><?= $e($product['description']) ?></p><?php endif ?>
    <div class="vision-card-bottom">
        <p class="vision-card-price"><?= $e($money($cardRentalOnly ? $product['rental_daily_rate_minor'] : ($product['card_price_minor'] ?? $product['retail_price_minor']))) ?><?= $cardRentalOnly ? ' / day' : '' ?></p>
        <?php if (!$cardRentalOnly && $cardCanBuy): ?>
            <?php if (($product['product_kind'] ?? '') !== 'plant' || !empty($product['card_pot_id'])): ?>
            <form action="/cart/add" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="product_id" value="<?= $e($product['product_id']) ?>"><input type="hidden" name="quantity" value="1"><?php if (!empty($product['card_pot_id'])): ?><input type="hidden" name="pot_id" value="<?= $e($product['card_pot_id']) ?>"><?php endif ?><button type="submit" class="btn-solid">Add to basket</button></form>
            <?php else: ?><a class="btn-solid" href="/product?id=<?= $e($product['product_id']) ?>">Choose plant &amp; pot</a><?php endif ?>
        <?php endif ?>
        <?php if ($cardRentalOnly): ?><a class="btn-solid" href="/rental?<?= $e(http_build_query($cardRentQuery)) ?>">Check rental dates</a><?php endif ?>
        <?php if (isset($product['rental_quote'])): ?><p class="vision-card-kind"><?php if (!empty($product['rental_quote']['available'])): ?><?= $e($product['rental_quote']['available_quantity']) ?> available · <?= $e($money($product['rental_quote']['total_minor'])) ?> for one item<?php else: ?>Unavailable for those dates<?php endif ?></p><?php endif ?>
    </div>
</article>
