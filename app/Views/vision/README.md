# Landing components

These PHP templates copy the owner-approved Verdant Vision landing (sample main4f0c716), with its original Cormorant Garamond/Jost/Mrs Saint Delafield type and approved JPGs. Each section is independent. The initial transfer selected this skin only for the landing. The approved page transfer below extends it to catalogue, configuration, basket and account/authentication; payment, standalone rental and admin pages retain their existing presentation.

- `landing.php` owns the fixed section renderer map and preserves persisted section order/visibility.
- `header.php` / `footer.php` / `service-band.php` own shared page regions.
- Hero/spaces/arrangements/products/configuration/rentals have separate templates; `product-card.php` is reused for the landing product grid.
- `VisionLandingPresenter` maps actual product identity to approved image defaults and chooses from the active/allowed result set. No fabricated inventory, prices or reviews enter checkout.
- `vision/global.css` contains copied global tokens, base styles and compiled utilities. `vision/components.css` owns the small cart/gallery adapter styles. The old landing CSS is not overlaid.
- Existing section editor, safe asset/URL registry, CSRF, gallery, compatibility and cart services remain authoritative. Do not add commerce rules to these templates.

Provenance and editable visual source: `resources/landing-reference/verdant-vision/`. Recovery source: `resources/archive/landing-pre-vision-2026-10-16/`. The sample is a separate React design workspace; this production application remains PHP/MySQL.


## Transferred pages — sample4097c92

Catalogue, configuration, basket, account and authentication set `visionPage` to select this shared skin. The landing continues to use `landingVision`; product detail, standalone payment/rental pages and admin retain their existing presentation. Global sample utilities/tokens live in vision/global.css; PHP adapters in components.css and pages.css. Avoid loading the legacy customer styles over the vision skin.

Shared page-hero.php owns left-aligned imagery/readability. Catalogue card/filter, configuration choice, basket row, account record/navigation and auth form partials own their sections. Controllers supply actual catalogue, compatibility, quote, basket and owned history data. The copied TSX is a visual source reference only; there is no React runtime in PHP. Native forms preserve original CSRF/routes and work without enhancement. JS enhances filters, compatible choices, quantity steps and account anchors. Sample customer records, fixed prices, arbitrary size multipliers and fake payment status are not copied into application state.

Rental arrangements remain in the existing composition checkout journey, linked from the retail basket/account. A unified mixed session basket is not introduced by this presentation transfer. New page heroes are exact unchanged copies of the approved sample JPEGs. 

## Remaining transfer —sample3f379fa /18 October2026
Product detail, rental catalogue, Homes/Collections and prepared checkout/results now use this skin too. Their page-owned styles are `product-detail.css`, `discovery.css` and `checkout.css`, explicitly registered by each view and allowlisted in layout.php. Shared arrangement/category/checkout partials receive existing service data; no new runtime or commerce state engine is introduced. Admin layout and standalone rental quote presentation remain separate. 
