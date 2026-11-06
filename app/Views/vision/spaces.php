<section data-vision-section="spaces" class="mx-auto grid max-w-7xl gap-5 px-6 py-12 md:grid-cols-3">
<?php if (($section['collections'] ?? []) !== []): ?>
<?php foreach ($section['collections'] as $collection): ?>
  <article class="bg-card">
    <img
      src="<?= $e($section['media_path'] && in_array($section['media_path'], \Verdant\Storefront\SectionRegistry::mediaPaths(), true) ? $section['media_path'] : '/assets/vision/images/homes.jpg') ?>"
      alt="<?= $e($collection['name']) ?>"
      loading="lazy"
      width="1024"
      height="640"
      class="aspect-[16/10] w-full object-cover"
    />
    <div class="p-6">
      <p class="eyebrow"><?= $e($collection['name']) ?></p>
      <h3 class="mt-2 text-2xl"><?= $e($collection['name']) ?></h3>
      <p class="mt-2 max-w-[16rem] text-sm text-muted-foreground">
        <?= $e($collection['description']) ?>
      </p>
      <a href="/collections/<?= $e(rawurlencode($collection['collection_code'])) ?>" class="link-caps mt-5"
        >Explore collection
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
<?php else: ?>
  <article class="bg-card">
    <img
      src="/assets/vision/images/homes.jpg"
      alt="Homes"
      loading="lazy"
      width="1024"
      height="640"
      class="aspect-[16/10] w-full object-cover"
    />
    <div class="p-6">
      <p class="eyebrow">Homes</p>
      <h3 class="mt-2 text-2xl">Bring nature home.</h3>
      <p class="mt-2 max-w-[16rem] text-sm text-muted-foreground">
        Plants, pots and styling for calmer, healthier living spaces.
      </p>
      <a href="/collections/homes" class="link-caps mt-5"
        >Shop for homes
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
  <article class="bg-card">
    <img
      src="/assets/vision/images/workspaces.jpg"
      alt="Workspaces"
      loading="lazy"
      width="1024"
      height="640"
      class="aspect-[16/10] w-full object-cover"
    />
    <div class="p-6">
      <p class="eyebrow">Workspaces</p>
      <h3 class="mt-2 text-2xl">Greener, brighter teams.</h3>
      <p class="mt-2 max-w-[16rem] text-sm text-muted-foreground">
        Plants and rentals for offices and commercial spaces.
      </p>
      <span class="link-caps mt-5" aria-label="Workspaces — coming soon">Coming soon</span>
    </div>
  </article>
  <article class="bg-card">
    <img
      src="/assets/vision/images/gatherings.jpg"
      alt="Gatherings"
      loading="lazy"
      width="1024"
      height="640"
      class="aspect-[16/10] w-full object-cover"
    />
    <div class="p-6">
      <p class="eyebrow">Gatherings</p>
      <h3 class="mt-2 text-2xl">Unforgettable, naturally.</h3>
      <p class="mt-2 max-w-[16rem] text-sm text-muted-foreground">
        Plants and floral styling for events, weddings and special occasions.
      </p>
      <span class="link-caps mt-5" aria-label="Gatherings — coming soon">Coming soon</span>
    </div>
  </article>
<?php endif ?>
</section>
