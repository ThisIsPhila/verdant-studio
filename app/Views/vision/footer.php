<footer class="vision-footer mx-auto <?= ($visionServicesVisible ?? false) ? '' : 'mt-12' ?> max-w-7xl px-6 pb-10">

  <div class="grid gap-10 py-10 lg:grid-cols-[1fr_1.4fr_2.4fr]">
    <div>
      <a class="block leading-none active" href="/" data-status="active" aria-current="page"
        ><div class="font-display tracking-[0.3em] text-2xl">VERDANT</div>
        <div class="mt-1 tracking-[0.25em] text-muted-foreground uppercase text-[0.5rem]">
          Botanical nursery &amp; plant studio
        </div></a
      >
      <p class="mt-3 text-3xl font-script">Nature, curated by hand.</p>
      <svg viewBox="0 0 60 16" class="h-4 w-14 text-primary ml-8" fill="none" stroke="currentColor" stroke-width="1">
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
    </div>
    <div>
      <h4 class="text-lg">Join our growing community</h4>
      <p class="text-xs text-muted-foreground">
        Updates about plants, care and seasonal collections. Newsletter sign-up is coming soon.
      </p>
      <div class="mt-4 flex">
        <input
          type="email"
          disabled aria-label="Newsletter email — sign-up coming soon"
          placeholder="Your email address"
          class="min-w-0 flex-1 border bg-card px-3 py-2 text-xs outline-none focus:border-primary"
        /><button type="button" class="btn-solid !py-2" disabled>Coming soon</button>
      </div>
      <div class="mt-4 flex gap-4 text-muted-foreground">
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
          class="lucide lucide-instagram h-4 w-4"
          aria-hidden="true"
        >
          <rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect>
          <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
          <line x1="17.5" x2="17.51" y1="6.5" y2="6.5"></line></svg
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
          class="lucide lucide-facebook h-4 w-4"
          aria-hidden="true"
        >
          <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg
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
          class="lucide lucide-youtube h-4 w-4"
          aria-hidden="true"
        >
          <path
            d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"
          ></path>
          <path d="m10 15 5-3-5-3z"></path>
        </svg>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-6 sm:grid-cols-4">
      <div>
        <p class="eyebrow !text-foreground">Shop</p>
        <ul class="mt-3 space-y-1.5 text-xs text-muted-foreground">
          <li><a href="/catalogue?kind=plant">All Plants</a></li>
          <li><a href="/catalogue?kind=cut_flower">Flowers</a> &amp; <a href="/catalogue?kind=floral_arrangement">Bouquets</a></li>
          <li><a href="<?= $e($footerShopLinks['pots'] ?? '/catalogue') ?>">Pots &amp; Planters</a></li>
          <li><a href="<?= $e($footerShopLinks['tools'] ?? '/catalogue') ?>">Care &amp; Tools</a></li>
          <li><a href="/catalogue?mode=rent">Rentals</a></li>
          <li><a href="/arrangements">Arrangements</a></li>
          <li><a href="/collections">Collections</a></li>
          <li><a href="/collections/homes">Homes</a></li>
          <li><a href="/configure">Configure</a></li>
        </ul>
      </div>
      <div>
        <p class="eyebrow !text-foreground">About</p>
        <ul class="mt-3 space-y-1.5 text-xs text-muted-foreground">
          <li><a href="/about">Our Story</a></li>
          <li><a href="/sustainability">Sustainability</a></li>
          <li><a href="/plant-care">Plant Care</a></li>
          <li><a href="/journal">Journal</a></li>
          <li><a href="/contact">Contact</a></li>
        </ul>
      </div>
      <div>
        <p class="eyebrow !text-foreground">Help</p>
        <ul class="mt-3 space-y-1.5 text-xs text-muted-foreground">
          <li><a href="/delivery">Delivery</a></li>
          <li><a href="/returns">Returns &amp; FAQs</a></li>
          <li><a href="/plant-care">Plant Care Guide</a></li>
          <li><a href="/contact#corporate">Corporate Enquiries</a></li>
          <li><a href="/contact#trade">Trade</a></li>
        </ul>
      </div>
      <div>
        <p class="eyebrow !text-foreground">South Africa</p>
        <ul class="mt-3 space-y-1.5 text-xs text-muted-foreground">
          <li>Based in South Africa</li>
          <li><a href="/delivery">Delivery &amp; collection</a></li>
          <li><a href="/contact">Contact &amp; enquiries</a></li>

        </ul>
      </div>
    </div>
  </div>
  <div class="flex flex-wrap justify-between gap-4 border-t pt-5 text-[0.65rem] text-muted-foreground">
    <p>© 2026 Verdant. All rights reserved.</p>
    <nav aria-label="Legal information">
      <?php foreach (['privacy' => 'Privacy & POPIA', 'terms' => 'Terms of Use & Sale'] as $policyKey => $policyLabel): ?>
        <a href="/<?= $e($policyKey) ?>"><?= $e($policyLabel) ?></a>
      <?php endforeach ?>
    </nav>
    <p><a href="/catalogue">Shop Online</a> | <a href="/contact">Consult</a> | <a href="/delivery">Collect | Deliver | Install</a></p>
  </div>
</footer>
