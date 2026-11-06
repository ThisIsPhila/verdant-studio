<section data-vision-section="rental-collection" class="mx-auto max-w-7xl px-6 py-12">
  <div class="grid overflow-hidden bg-sand lg:grid-cols-[1fr_2.4fr_1fr]">
    <div class="p-8">
      <p class="eyebrow">Rental collection</p>
      <h2 class="mt-2 text-4xl leading-tight"><?= $e($section['heading']) ?></h2>
      <p class="mt-4 text-sm text-muted-foreground">
        <?= $e($section['body']) ?>
      </p>
      <a href="/catalogue?mode=rent" class="btn-solid mt-6"
        >Browse rentals
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
          class="lucide lucide-arrow-right h-3.5 w-3.5"
          aria-hidden="true"
        >
          <path d="M5 12h14"></path>
          <path d="m12 5 7 7-7 7"></path></svg
      ></a>
    </div>
      <div class="grid grid-cols-2 gap-4 p-6 sm:grid-cols-4">
        <?php foreach ($section['rentals'] ?? [] as $product): ?>
          <a href="/rental?product_id=<?= $e($product['product_id']) ?>">
            <span class="bg-primary px-1.5 py-0.5 text-[0.5rem] tracking-[0.15em] text-primary-foreground uppercase"><?= !empty($product['buy_enabled']) ? 'Rent (also buy)' : 'Rent' ?></span>
            <img src="<?= $e($product['vision_image'] ?? $image($product['image_path'] ?? null)) ?>" alt="<?= $e($product['name']) ?>" loading="lazy" class="mt-2 aspect-[2/3] w-full object-cover mix-blend-multiply">
            <p class="mt-2 font-display"><?= $e($product['name']) ?></p>
            <p class="text-[0.65rem] text-muted-foreground">Rent from <?= $e($money($product['rental_daily_rate_minor'])) ?> / day</p>
            <?php if (!empty($product['buy_enabled'])): ?><p class="text-[0.65rem] text-muted-foreground">Or buy from <?= $e($money($product['retail_price_minor'])) ?></p><?php endif ?>
          </a>
        <?php endforeach ?>
      </div>
    <div class="relative min-h-64">
      <img
        src="/assets/vision/images/gatherings.jpg"
        alt="Event styling"
        loading="lazy"
        class="absolute inset-0 h-full w-full object-cover"
      />
      <p class="eyebrow absolute top-6 left-6 !text-primary-foreground leading-loose">
        Plants<br />people<br />bring<br />together
      </p>
    </div>
  </div>
</section>
