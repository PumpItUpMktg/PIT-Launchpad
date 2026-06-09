# Elementor Wireframe Library

Importable Elementor `.json` for the whole wireframe library — block templates,
page assemblies, and the two Theme Builder templates — plus a sidecar spec that
maps every stable `wf-*` hook to its type, image size, char range, and source.

Everything here is **generated** by [`build.php`](build.php), the single source
of truth. Edit the generator, not the JSON, then re-run:

```bash
php wireframe-library/build.php
```

The generator validates its own output (re-parses every file, asserts unique
element ids per file, and cross-checks that every emitted `wf-*` class resolves
in the sidecar).

## Layout

```
wireframe-library/
├── build.php              # generator (source of truth)
├── wireframe-spec.json    # sidecar: wf-* class → {type, image_size?, char_range?, source}
├── blocks/                # 26 reusable block templates  (type: container)
├── pages/                 # 13 page assemblies            (type: container / single-post)
├── theme-builder/         # tb-header (type: header), tb-footer (type: footer)
└── sources/               # the relay brief + wireframe HTML the generator was built from
```

`sources/` holds the design inputs the generator encodes: the relay brief, the
all-pages composition wireframe, and the header/footer tier wireframe. They are
reference material, not imported — edit `build.php` to change output.

## Importing

- **Blocks** & **page assemblies** — Templates → Saved Templates → Import, then
  drop into a page (or use a block as a section). They import as containers.
- **`theme-builder/tb-header.json` / `tb-footer.json`** — Templates → Theme
  Builder → Import; they land as a Header and a Footer and are applied
  site-wide (set display conditions to "Entire Site").
- **`pages/page-blog-post.json`** — imports as a **Single** post template
  (`type: single-post`); its body is the **Post Content** widget, which renders
  the generated post via dynamic content.

## Conventions (enforced in the generator)

1. **Containers only** — no legacy sections/columns.
2. **No local typography or color anywhere.** Only layout settings (flex
   direction, widths, gaps, padding, alignment) are set locally. Headings, text,
   and buttons inherit the **Global Kit** — configure the kit and everything
   re-skins. (Verified: a grep for `typography_*` / `*color*` / `font_*` over the
   emitted JSON returns nothing.)
3. **Images** are the Image widget with `image_size: "custom"` and the exact §7
   pixel dimensions, pointing at a `https://PLACEHOLDER.local/wf/<name>-<W>x<H>.png`
   placeholder. Replacing the image keeps the box size.
4. **Stable hooks.** Every content element carries a `wf-<block>-<element>` class
   in `_css_classes` (mirrored, underscored, in `_title`). The generation sidecar
   and any later dynamic binding target these classes, never the random
   Elementor element `id`.
5. **No JSON-LD / microdata** in any template — Launchpad injects
   Organization / LocalBusiness schema via `wp_head`, site-wide.
6. Char ranges and image sizes live **only** in `wireframe-spec.json`, keyed by
   the stable class — never in the templates.

## Decisions worth knowing

- **Envelope shape (Step 0).** The task's §5 golden example is the canonical
  envelope here: `version: "0.4"`, the container model, `page_settings: []`.
  Step 0 (exporting a container / header / footer from the *target* install and
  reading its exact `version`/`type`/key names) could not be run in this
  environment — there is no live Elementor install to export from. **If a live
  export's `version` string differs, that wins:** update the `envelope()` /
  Theme-Builder `type` values in `build.php` and re-run. Everything else
  (widget types, settings keys) follows the documented Elementor container/Pro
  schema.

- **Pages carry body blocks only; header/footer are the TB templates.** §6
  writes every page as `header → … → footer`, but §4a defines the header and
  footer as **Theme Builder** templates applied globally. Embedding them in each
  page would duplicate the chrome and collide with the global header/footer, so
  page assemblies contain just the body blocks (in §6 order) and the
  `header →`/`→ footer` is delivered once as `tb-header` / `tb-footer`.

- **FAQ uses the Accordion widget** (per §4). Accordion Q/A pairs are repeater
  rows, not first-class elements, so they can't each carry a `_css_classes`. The
  `wf-faq-q-{n}` / `wf-faq-a-{n}` hooks are therefore registered in the sidecar
  as `accordion tabs[n].tab_title` / `tab_content`; the binding engine targets
  them by index. The block element itself carries `wf-faq`.

- **Blog index uses a dynamic Posts widget** (`wf-post-loop`) with pagination,
  rather than static cards. `block-post-grid.json` is the **static-card** form
  (stable `wf-post-{n}-*` hooks) used for related-posts (×3) and teasers. Swap
  the Posts widget for a Loop Grid once a loop-item template exists.

- **Hero variants.** `block-hero.json` ships the default (problem-led, two-column
  with image) matching the golden example. Pages render the variant they need
  inline (`brand_led` / `category_led` / `area_led` / `slim`); `slim` drops the
  image and goes single-column. On `area_led` / `brand_led` the hero image is a
  brand image (no AI local scene) — noted in the sidecar `source`.

## Files

**Blocks (26):** hero · trust-bar · problem-solution · why-us · proof-strip ·
testimonials · jobs · faq · final-cta · intro · area-intro · nap-map ·
service-list · services-grid · areas-teaser · markets-grid · story · team-grid ·
contact-form · hours · post-grid · article-header · article-body · author-share ·
basic-content · utility-message

**Pages (13):** home · service-hub · service · service-in-location · location ·
areas-hub · about · contact · blog-index · blog-post · basic-content · 404 ·
thank-you

**Theme Builder (2):** tb-header · tb-footer
