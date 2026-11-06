<section data-vision-section="hero" class="relative flex flex-col overflow-hidden bg-surface">
  <img
    src="<?= $e(in_array($section['media_path'] ?? '', \Verdant\Storefront\SectionRegistry::mediaPaths(), true) && str_starts_with($section['media_path'], '/assets/vision/') ? $section['media_path'] : '/assets/vision/images/hero.jpg') ?>"
    alt="Monstera in a stone pot"
    width="1600"
    height="912"
    class="order-2 h-64 w-full object-cover object-right md:absolute md:inset-0 md:order-none md:h-full"
  />
  <div class="relative order-1 mx-auto w-full max-w-7xl px-6 py-12 md:order-none md:py-28">
    <div class="max-w-lg">
      <p class="eyebrow">Indoor plants · Outdoor gardens · Floral studio</p>
      <h1 class="mt-5 text-[clamp(2.5rem,10vw,3.75rem)] leading-tight md:text-7xl md:leading-[0.95]">
        <?= $section['heading'] === 'Nature, curated by hand.' ? 'Nature, curated<br>by hand.' : $e($section['heading']) ?>
      </h1>
      <p class="mt-4 font-script text-4xl text-foreground/80">A greener, more beautiful tomorrow.</p>
      <svg
        viewBox="0 0 60 16"
        class="h-4 w-14 text-primary mt-2 ml-10"
        fill="none"
        stroke="currentColor"
        stroke-width="1"
      >
        <path d="M2 8h56"></path>
        <g>
          <path d="M10 8q4-6 8-6"></path>
          <path d="M10 8q4 6 8 6"></path>
        </g>
        <g>
          <path d="M20 8q4-6 8-6"></path>
          <path d="M20 8q4 6 8 6"></path>
        </g>
        <g>
          <path d="M30 8q4-6 8-6"></path>
          <path d="M30 8q4 6 8 6"></path>
        </g>
        <g>
          <path d="M40 8q4-6 8-6"></path>
          <path d="M40 8q4 6 8 6"></path>
        </g>
      </svg>
      <p class="mt-6 max-w-sm text-sm leading-relaxed text-muted-foreground">
        <?= $e($section['body']) ?>
      </p>
      <div class="mt-8 flex flex-wrap gap-4">
        <a href="<?= $e($section['link_path'] ?? '/catalogue?kind=plant') ?>" class="btn-solid"
          ><?= $e($section['link_label'] ?: 'Shop plants') ?>
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
            <path d="m12 5 7 7-7 7"></path></svg></a
        ><a href="#arrangements" class="btn-outline">Browse collections</a>
      </div>
    </div>
    <p class="absolute right-10 bottom-10 hidden -rotate-12 text-right font-script text-3xl leading-tight lg:block">
      Plants<br />for a brighter<br />tomorrow
    </p>
  </div>
</section>
