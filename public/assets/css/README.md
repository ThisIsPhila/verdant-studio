# Stylesheet ownership

- `app.css`: shared tokens, type, controls, accessibility utilities and page-container contract.
- `components/site-header.css`: navigation, utilities and native mobile menu.
- `components/hero.css`: editorial hero, copy, actions and responsive composition.
- `components/cards.css`: product, collection and arrangement cards with explicit variants.
- `components/sections.css`: common section headings and gutters.
- `components/configuration-entry.css`, `rental-collection.css`, `service-band.css`: each section's own layout.
- `components/site-footer.css`: footer directory.
- `pages.css`: existing specialised forms, catalogue introductions, product detail, commerce, account and internal layouts.
- `media-renderer.css`: product gallery and scene/camera geometry.

The layout loads these files directly. There is no overriding guideline stylesheet. Edit the owning component instead of adding a later replacement rule. Breakpoint and state rules stay in the same file as their component. Shared cards receive explicit compact/rental inputs; CSS does not remove commerce actions by inspecting unrelated ancestors. Source files remain expanded and readable.

The main canvas is full width. Editorial sections own their gutters; ordinary customer page blocks use the shared bounded content width. Internal operations keep their own content shell.

Typography and colour tokens follow `storefront-prototype/css/design-system.css`: Playfair Display for display type, Inter for body/navigation, Caveat for script accents. Do not replace this type system while adjusting layouts. The featured product rail owns its horizontal overflow; the page itself must not scroll sideways. Default compatible pot choices for its plant cart forms are supplied by the controller and revalidated by the cart service. Review stars are empty until actual review data exists.
