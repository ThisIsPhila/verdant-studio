<section data-vision-section="arrangements" id="arrangements" class="mx-auto max-w-7xl px-6 py-12">
  <div class="flex flex-wrap items-end justify-between gap-4 border-b pb-4">
    <div>
      <p class="eyebrow">Curated arrangements</p>
      <h2 class="mt-1 text-3xl"><?= $e($section['heading']) ?></h2>
    </div>
    <a href="/arrangements" class="link-caps"
      >View all arrangements
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
        class="lucide lucide-arrow-right h-3 w-3"
        aria-hidden="true"
      >
        <path d="M5 12h14"></path>
        <path d="m12 5 7 7-7 7"></path></svg
    ></a>
  </div>
  <div class="mt-6 grid gap-5 lg:grid-cols-3">
    <?php foreach ($section['definitions'] ?? [] as $definitionIndex => $definition): ?>
    <article class="grid grid-cols-2 gap-3 border bg-card p-3">
      <img
        src="<?= $e('/assets/vision/images/' . ['homes.jpg', 'workspaces.jpg', 'gatherings.jpg'][$definitionIndex % 3]) ?>"
        alt="<?= $e($definition['name']) ?>"
        loading="lazy"
        class="aspect-[4/5] h-full w-full object-cover"
      />
      <ul class="flex flex-col justify-center gap-3">
        <?php foreach ($definition['components'] ?? [] as $part): ?>
        <li class="flex items-center gap-2 text-xs text-muted-foreground">
          <img
            src="<?= $e(\Verdant\Storefront\VisionLandingPresenter::image($part['product_options'][0] ?? []) ?? '/assets/vision/images/p-stone.jpg') ?>"
            alt=""
            loading="lazy"
            class="h-9 w-9 shrink-0 bg-surface object-contain"
          /><?= $e($part['source_type'] === 'compatible_pot' ? 'Planter' : $part['label']) ?>
        </li>
        <?php endforeach ?>
      </ul>
      <div>
        <h3 class="text-xl"><?= $e($definition['name']) ?></h3>
        <p class="text-xs text-muted-foreground">Thoughtfully paired plants and planters for your space.</p>
      </div>
      <div class="flex flex-col justify-end gap-2">
        <p class="font-display text-lg"><?= isset($definition['price_minor']) ? 'From ' . $e($money($definition['price_minor'])) : 'Made to your selection' ?></p>
        <a href="/configure?code=<?= $e(rawurlencode($definition['definition_code'])) ?>" class="btn-solid !px-3 !py-2 !text-[0.6rem]"
          >Shop arrangement
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
            class="lucide lucide-arrow-right h-3 w-3"
            aria-hidden="true"
          >
            <path d="M5 12h14"></path>
            <path d="m12 5 7 7-7 7"></path></svg
        ></a>
      </div>
    </article>
    <?php endforeach ?>
  </div>
</section>
