<section data-vision-section="products" id="shop" class="mx-auto max-w-7xl px-6 py-12">
  <div class="flex flex-wrap items-end justify-between gap-4 border-b pb-4">
    <div>
      <p class="eyebrow">Shop our collections</p>
      <h2 class="mt-1 text-3xl"><?= $e($section['heading']) ?></h2>
    </div>
    <div class="flex max-w-full gap-6 overflow-x-auto pb-1 text-xs">
      <button type="button" data-vision-filter="All" class="shrink-0 py-2 sm:py-0 sm:pb-1 border-b border-foreground text-foreground">All</button
      ><button type="button" data-vision-filter="Plants" class="shrink-0 py-2 sm:py-0 sm:pb-1 text-muted-foreground hover:text-foreground">Plants</button
      ><button type="button" data-vision-filter="Flowers" class="shrink-0 py-2 sm:py-0 sm:pb-1 text-muted-foreground hover:text-foreground">Flowers</button
      ><button type="button" data-vision-filter="Pots" class="shrink-0 py-2 sm:py-0 sm:pb-1 text-muted-foreground hover:text-foreground">Pots</button
      ><button type="button" data-vision-filter="Care &amp; Tools" class="shrink-0 py-2 sm:py-0 sm:pb-1 text-muted-foreground hover:text-foreground">
        Care &amp; Tools
      </button>
    </div>
  </div>
  <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
    <?php foreach ($section['products'] ?? [] as $product): ?>
      <?php require __DIR__ . '/product-card.php'; ?>
    <?php endforeach ?>
  </div>
</section>
