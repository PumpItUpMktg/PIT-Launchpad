# Kit templates (Elementor)

The plugin provides the **rendering mechanism** — Launchpad dynamic tags that
resolve `slot_payload` by slot key, plus kit→template routing. The actual
**layouts are a per-site design artifact** (Elementor templates wired to the
tags), not hardcoded here: brand-neutral templates are authored once and imported
into each blank WP instance, then mapped to their kit.

## Registering a template for a kit

Map a kit (or `page_type`) to an Elementor template post id in the `lp_templates`
option:

```php
update_option('lp_templates', [
    'service-page'  => '123', // an Elementor template post id / page template file
    'location-page' => '124',
]);
```

`ContentStore` sets `_wp_page_template` per the incoming `kit` (falling back to
`page_type`, then `elementor_canvas`). An unknown kit falls back to the canvas and
the dynamic tags still resolve whatever slots are present — so a draft kit renders
generically rather than fatalling. Templates can also target the
`lp-kit-{kit}` / `lp-page-type-{type}` body classes.

## Scope (locked kits)

Build the two locked kits whose §3a slot schemas are finalized:

- **`service-page`**
- **`location-page`**

Home / Hub / Blog / Utility are deferred until their §3a schemas lock; the generic
fallback covers them in the interim.

## The slot contract the templates consume

Place these via the Launchpad dynamic tags (group **Launchpad**), each keyed on a
slot key from the engine's `slot_payload` / `images`:

| Tag | Resolves | Use |
| --- | --- | --- |
| **LP Text** (`lp-text`) | `slot_payload[<key>]` (string) | headings, body, labels |
| **LP Image** (`lp-image`) | `images[<key>]` → local attachment | hero, gallery |
| **LP CTA** (`lp-cta`) | a CTA slot (label + url) | buttons |
| **LP Map** (`lp-map`) | a map/embed slot | location pages |
| **LP Repeater** (`lp-repeater`) | a list slot | feature / FAQ lists |

SEO (title/meta/canonical/OG/JSON-LD/breadcrumbs) is emitted natively by the
plugin head — templates must **not** add their own meta or an SEO plugin.
Styling comes from Elementor's global color/typography kit — never hardcode.
