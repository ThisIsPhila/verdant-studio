<?php $basketImage = \Verdant\Storefront\VisionLandingPresenter::image($item) ?? $image($item['image_path'] ?? null); ?>
<li class="basket-row border-b p-4 last:border-b-0">
    <a href="/product?id=<?= $e($item['product_id']) ?>"><img class="basket-row__image object-cover" src="<?= $e($basketImage) ?>" alt="<?= $e($item['name']) ?>" loading="lazy"></a>
    <div class="basket-row__details min-w-0">
        <h3 class="text-xl"><a href="/product?id=<?= $e($item['product_id']) ?>"><?= $e($item['name']) ?></a></h3>
        <p class="vision-muted"><?= $e($item['category_name'] ?? '') ?></p>
        <?php if (!empty($item['size_label']) && !in_array(strtolower($item['size_label']), ['standard','unknown'], true)): ?><p class="vision-muted"><?= $e($item['size_label']) ?></p><?php endif ?>
        <p class="font-display text-lg"><?= $e($money($item['retail_price_minor'])) ?></p>
        <?php if ($item['errors']): ?><p role="status"><?= $e(implode(' ', $item['errors'])) ?></p><?php endif ?>
    </div>
    <form class="vision-quantity" action="/cart/update" method="post">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="product_id" value="<?= $e($item['product_id']) ?>">
        <label class="sr-only" for="quantity-<?= $e($item['product_id']) ?>">Quantity for <?= $e($item['name']) ?></label>
        <div class="vision-quantity-controls"><button type="button" data-quantity-step="-1" aria-label="Decrease <?= $e($item['name']) ?> quantity">−</button><input id="quantity-<?= $e($item['product_id']) ?>" type="number" name="quantity" min="1" max="99" value="<?= $e($item['quantity']) ?>"><button type="button" data-quantity-step="1" aria-label="Increase <?= $e($item['name']) ?> quantity">+</button></div>
        <button type="submit" class="vision-quantity-update">Update</button>
    </form>
    <div class="basket-row__total text-right"><p class="font-display text-xl"><?= $e($money($item['line_total_minor'])) ?></p><form action="/cart/remove" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="product_id" value="<?= $e($item['product_id']) ?>"><button class="vision-remove" type="submit" aria-label="Remove <?= $e($item['name']) ?>">Remove</button></form></div>
</li>
