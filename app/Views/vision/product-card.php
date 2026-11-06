    <article class="flex flex-col border bg-card" data-vision-category="<?= $e(\Verdant\Storefront\VisionLandingPresenter::category($product)) ?>">
      <div class="relative bg-surface">
        <a href="/product?id=<?= $e($product['product_id']) ?>"><img
          src="<?= $e($product['vision_image'] ?? $image($product['image_path'] ?? null)) ?>"
          alt="<?= $e($product['name']) ?>"
          loading="lazy"
          class="aspect-[3/4] w-full object-cover"
        /></a><span
          class="absolute top-2 left-2 px-1.5 py-0.5 text-[0.5rem] tracking-[0.15em] uppercase bg-primary text-primary-foreground"
          ><?= !empty($product['rent_enabled']) ? 'Buy or rent' : 'Buy' ?></span
        ><button type="button" data-save-product="<?= $e($product['product_id']) ?>" aria-label="Save <?= $e($product['name']) ?>" aria-pressed="false" class="absolute top-2 right-2 text-muted-foreground hover:text-primary">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="lucide lucide-heart h-3.5 w-3.5"
            aria-hidden="true"
          >
            <path
              d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"
            ></path>
          </svg>
        </button>
      </div>
      <div class="flex flex-1 flex-col p-2.5">
        <h3 class="font-display text-sm"><a href="/product?id=<?= $e($product['product_id']) ?>"><?= $e($product['name']) ?></a></h3>
        <p class="text-[0.65rem] text-muted-foreground"><?= $e($product['category_name'] ?? $label($product['product_kind'])) ?></p>
        <p class="mt-1 text-sm"><?= $e($money($product['card_price_minor'] ?? $product['retail_price_minor'] ?? null)) ?></p>
        <div class="mt-1 mb-3 flex items-center gap-0.5 text-star">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="lucide lucide-star h-2.5 w-2.5"
            aria-hidden="true"
          >
            <path
              d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
            ></path></svg
          ><svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="lucide lucide-star h-2.5 w-2.5"
            aria-hidden="true"
          >
            <path
              d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
            ></path></svg
          ><svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="lucide lucide-star h-2.5 w-2.5"
            aria-hidden="true"
          >
            <path
              d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
            ></path></svg
          ><svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="lucide lucide-star h-2.5 w-2.5"
            aria-hidden="true"
          >
            <path
              d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
            ></path></svg
          ><svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="lucide lucide-star h-2.5 w-2.5"
            aria-hidden="true"
          >
            <path
              d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
            ></path></svg
          ><span class="ml-1 text-[0.6rem] text-muted-foreground">(0)</span>
        </div>
        <?php if (!empty($product['buy_enabled']) && (($product['product_kind'] ?? '') !== 'plant' || !empty($product['card_pot_id']))): ?>
        <form action="/cart/add" method="post" class="mt-auto pt-3">
          <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
          <input type="hidden" name="product_id" value="<?= $e($product['product_id']) ?>">
          <input type="hidden" name="quantity" value="1">
          <?php if (!empty($product['card_pot_id'])): ?>
            <input type="hidden" name="pot_id" value="<?= $e($product['card_pot_id']) ?>">
          <?php endif ?>
          <button class="btn-outline w-full min-h-11 !px-2 !py-1.5 !text-[0.55rem] sm:min-h-0" type="submit">Add to cart</button>
        </form>
      <?php else: ?>
        <a class="btn-outline mt-auto !px-2 !py-1.5 !text-[0.55rem]" href="/product?id=<?= $e($product['product_id']) ?>">View product</a>
      <?php endif ?>
      </div>
    </article>
