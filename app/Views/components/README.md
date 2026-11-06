# Shared storefront component contract

Components are plain PHP partials. A caller prepares display data and sets
`$component` immediately before including the relevant partial. Components
escape displayed strings with the view's `$e` helper and do not query MySQL or
calculate authoritative stock, eligibility, or totals. Price/amount strings
must already come from the existing service layer.

| Partial | `$component` input |
| --- | --- |
| `hero.php` | `eyebrow`, `title`, `body`, optional `image: {src, alt}`, optional `action` button input |
| `button.php` | `label`, optional local `href`, `type`, `variant`, `name`, `value` |
| `form-field.php` | `name`, `label`, `type`, `value`, `options: [{value, label}]`, `help`, `error`, `required`, `id`, optional allowlisted `inputmode` |
| `status-notice.php` | `title`, `message`, `tone` (`info`, `success`, `warning`, `error`) |
| `filter-panel.php` | `action`, `legend`, `fields` (form-field inputs), `active: [{label, remove_href}]`, `submit_label` |
| `collection-card.php` | `title`, `description`, optional `image: {src, alt}`, local `href`, `link_label` |
| `arrangement-card.php` | `title`, `description`, optional `image: {src, alt}`, `kind`, local `href`, `action_label` |
| `configuration-steps.php` | `steps: [{label, description}]`, one-based `current` step |
| `item-summary.php` | `title`, `items: [{label, detail, quantity, amount}]`, preformatted `total_label`, `total` |
| `service-band.php` | `items: [{label, text, optional local href}]` |

Image partials render only approved `/assets/` paths. Link partials accept
local absolute paths only; protocol-relative, traversal, backslash, and control
character paths fall back to a safe local destination. Filters are native GET
forms inside a closed-by-default `<details>` element so the basic flow works
without JavaScript.

`View::render($template, $data, $status, $layout)` accepts the explicit layout
names `customer` and `internal`. Controllers choose the layout; it is not
inferred from a request parameter or actor role.
