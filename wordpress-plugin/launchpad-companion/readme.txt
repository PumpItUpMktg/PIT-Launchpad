=== Launchpad Companion ===
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 0.9.5
License: GPLv2 or later

The receiver on each client site for the Launchpad control plane. It implements
the control-plane↔WordPress contract: authed REST upserts, consolidated
slot-blob storage, core Gutenberg block markup stored as the page's post_content
(rendered by a block theme — no page builder), native SEO, a managed sitemap,
and 301 redirects. No page builder, no SEO plugin, no ACF, no media-library
import — images are served from R2/CDN URLs in the payload.

== Changelog ==

= 0.9.5 =
* /content now accepts `service_area_map` — the "Areas we serve" interactive-map geometry
  (served-county polygons + tiered town points). Stored as post meta (`_lp_area_map`) and
  printed as a `window.lpAreaMap` global in the footer for the block theme's Leaflet init.
  Kept out of post_content because kses strips embedded geometry. Absent/null clears it.

= 0.9.4 =
* POST /style now also accepts an inline `theme_json` payload — a per-tenant DYNAMIC style
  variation (the logo-derived "Your brand colors") that has no styles/{slug}.json file in
  the theme. When `theme_json` is present it is written to the user global-styles post
  directly; otherwise the curated slug is read from the active theme as before. Idempotent.

= 0.9.3 =
* Added the universal header/footer chrome. New POST /site-profile stores the pushed
  per-tenant brand + NAP + navigation (phone, emergency flag, hours, service/area/
  company links) into the lp_site_profile option; new [lp_header] / [lp_footer]
  shortcodes render it as semantic .lp-* markup for the block theme's header/footer
  template parts (styled by the theme's assets/theme.css). A block-theme template part
  can't express per-tenant NAP statically — this is the injection path. Degrades to the
  WordPress site title with no phone/nav when no profile has been pushed.

= 0.9.2 =
* Stop fighting the block theme on the front end. On a BLOCK theme the plugin no
  longer enqueues the Elementor-era baseline stylesheets (launchpad.css / wireframe.css)
  or the brand-token Google Fonts — those were the classic/Elementor styling system and
  loaded the wrong fonts (Merriweather / Source Sans) over a block theme whose styling
  is theme.json + the theme's own stylesheet. The legacy enqueue now runs only on a
  non-block (classic) theme, where it is still the render layer.

= 0.9.1 =
* Added POST /style — activate a block-theme theme.json style variation (bold/clean/
  warm) as the site's global styles (writes the user global-styles post, same as
  picking it in Appearance → Editor → Styles). This is the pivot's brand "push":
  brand styling is a theme.json variation, not the Elementor Global Kit. The legacy
  /brand-kit endpoint remains for backward compatibility but is no longer the path
  the Brand step uses.

= 0.9.0 =
* Elementor → Gutenberg pivot. The /content endpoint now stores core-block markup
  (the push's `post_content`) as the WP post_content, rendered by the Launchpad
  block theme; brand styling lives in the theme's theme.json, not an Elementor
  Global Kit. Dropped the `Requires Plugins: elementor` dependency so Elementor can
  be deactivated/removed on managed sites. A page re-pushed to the block path strips
  any stale `_elementor_data`. Pushes without `post_content` still fall back to the
  legacy Elementor-body path. Requires a block theme (a Twenty Twenty-Five child).

== Contract endpoints (namespace launchpad/v1) ==
* POST /silo       — ensure a hierarchical category mirrors a Silo
* POST /content    — upsert a page/post (idempotent on content_id)
* POST /redirects  — upsert 301s (idempotent on from_url)
* POST /kit-template — import a bound Elementor template into Theme Builder
                     (idempotent per kit): creates/updates an `elementor_library`
                     "single" template from the pushed _elementor_data, ensures the
                     `lp_kit` term, and sets its Display Condition (Singular → By
                     Term → Launchpad Kit → {kit}) when Elementor Pro is present
                     (advisory meta + condition_set:false when it is not)
* POST /style      — activate a block-theme theme.json style variation (bold/clean/
                     warm) as the site's global styles. The pivot's brand push:
                     writes the variation's settings+styles to the user global-styles
                     post; brand styling lives in theme.json. Idempotent.
* POST /brand-kit  — [legacy] write the tenant's brand. Sets the Elementor Global Kit system
                     colors + typography by _id (primary/secondary/text/accent) for the
                     __globals__/dynamic-tag path; AND stores the native wf-* layer —
                     `wf_tokens` ("--wf-*" => value) into the lp_brand_tokens option and
                     `structure` (trust|bold|warm) into lp_structure_preset — which the
                     base wf-* stylesheet + body class consume. The wf-* layer is stored
                     even with no active Elementor kit. Idempotent (overwrites the same
                     slots/options)
* GET  /status     — environment introspection (WP/PHP/Elementor/theme/plugin versions)
* GET  /templates  — inventory of ALL Elementor saved templates across every Theme
                     Builder group (single-page / single-post / header / footer / page /
                     container …), any status, each with its actual _elementor_template_type
                     (taxonomy fallback for v4), so the control plane maps kits to real templates
* POST /site-profile — store the per-tenant brand + NAP + navigation (phone, emergency,
                     hours, service/area/company links) into lp_site_profile for the
                     universal [lp_header]/[lp_footer] chrome. Idempotent

Authentication: WordPress application password for the dedicated `launchpad-sync`
service user (role `launchpad_service`, capability `lp_manage_content`).

== Binding pushed content ==
Two surfaces, both reading the pushed slot blob:

Shortcodes (recommended; no Elementor dependency — works on the Atomic Editor V4,
the classic editor, or none). Place these in a Theme Builder template:
* [lp_slot key="hero_problem"]   scalar / HTML (also infers list / cta / map)
* [lp_repeater key="faq"]        faq / features / testimonials / stats
* [lp_cta key="cta"]             a {label,url} call-to-action
* [lp_map key="service_area"]    a {embed_url} or {lat,lng} map
* [lp_image key="hero_image"]    an <img> from the R2/CDN image map
Each accepts an optional id="<post_id>"; renders nothing for a non-managed post.
Scalar slots also mirror to readable `lp_slot_<key>` post meta for native binding.

Rendered markup (designer CSS hooks). Repeaters emit semantic, brand-neutral HTML
the designer styles — Launchpad never inlines look-and-feel:
* plain lists  → <ul class="lp-repeater lp-repeater--{key} lp-list"><li class="lp-list__item">
* faq          → <details class="lp-faq"><summary class="lp-faq__q">…<div class="lp-faq__a">
* stats        → <div class="lp-stat"><span class="lp-stat__value">…<span class="lp-stat__label">
* testimonials → <figure class="lp-testimonial"><blockquote>…<figcaption>
* nap / call   → .lp-nap (+ __name/__address/__phone/__hours) ; .lp-conversion-block (+ __call/__form)

Featured image: the push carries `featured_image` (the og/hero image URL); the
plugin sideloads it (reusing the already-imported attachment) and sets it as the
post thumbnail — so posts, which have no kit hero slot, still get a featured image.
Image URLs must be absolute (the control plane's R2 public base) to resolve.

Posts (news / reactive content) carry no kit: the article is the `body` slot. Bind
it once in the single-post template with [lp_slot key="body"] (or the native Post
Custom Field `lp_slot_body`). SEO (title, meta description, canonical, robots,
OpenGraph/Twitter) is engine-owned and emitted into the document <head>
automatically — there is no tag to place. Both are documented under
Launchpad → Slots & Shortcodes (a built-in "Posts" section, independent of kit pushes).

Dynamic tags (classic V3 editor only): lp/text, lp/image, lp/cta, lp/map,
lp/repeater render the same content via the shared SlotRenderer. They are skipped
safely when Elementor's classic dynamic-tag API is absent (e.g. Atomic-only), so
they can never fatal page rendering — use the shortcodes there.

== Templates ==
Brand-neutral Elementor templates are built by the designer. Map each page type
to a template via the `lp_templates` option (page_type => template); pages also
carry `lp-page-type-{type}` and `lp-kit-{kit}` body classes.

A page carrying a per-page native Elementor body (`_elementor_data`) gets the
Elementor Full-Width template (`elementor_header_footer`): the theme header/footer
render, but the theme's `.page-header` entry-title is dropped (so the hero H1 is the
page's only H1) and the content is full-width. An explicit `lp_templates` file
mapping still wins over it.

Otherwise managed content gets NO page template (posts AND dynamic-tag kit pages):
`elementor_canvas` is a full-page Elementor template that bypasses Theme Builder
*single* templates, so the content is left on the theme default and the Theme
Builder single template (mapped via the lp_kit display condition) drives it. The
document <title> is emitted once via core title-tag (filtered through
pre_get_document_title), never a second hand-printed tag.

Kit → template mapping is chosen in Launchpad (operator panel → Portfolio →
Templates), against this site's live template inventory (GET /templates). Every
kit page is tagged with its kit in the `lp_kit` taxonomy — a stable per-kit marker
that an Elementor Pro Theme Builder "single" template can target as a Display
Condition (Singular → By Taxonomy → Launchpad Kit → {kit}). Set that condition
once per template and the mapped kit renders through it; Launchpad → Slots &
Shortcodes → "Kit templates" lists the exact condition + the resolved template id
per kit. (A taxonomy term is the condition target because Pro conditions match
terms/post-types, not body classes; this is version-independent and works on the
Atomic Editor where per-post page-template assignment does not.)

== Brand system (wf-* native pages) ==
Native library pages (per-page _elementor_data) render as wireframe widgets carrying
stable `wf-*` hook classes and NO baked color/font/radius. The look is supplied by
assets/wireframe.css, parameterized entirely by CSS custom properties in two tiers:

* Structure tokens (--wf-radius / -shadow / -section-gap / -pad-block / -button-radius
  / -heading-transform|weight|tracking) — one of three preset bundles selected by the
  `body.wf-structure-{slug}` class (trust / bold / warm). The chosen slug is the
  `lp_structure_preset` option, emitted as a body class on managed kit pages.
* Brand tokens (--wf-color-* / --wf-font-*) — per-tenant values from the
  `lp_brand_tokens` option, printed as a sanitized `:root { --wf-* }` inline block
  before wireframe.css and survive republish. Only valid `--wf-*` names are emitted;
  values are charset-restricted (no CSS breakout). The tenant's heading/body Google
  Fonts are enqueued so the --wf-font-* tokens render. Both options are written by
  push-brand-kit. Every token has a safe fallback, so an un-branded page is presentable.

The brand tokens carry the COLOR SCHEME (Light or Dark) in their surface values — a
light token set renders a light page, a dark set a dark page. Every surface paints
both its background and foreground; foregrounds are ONLY the gated tokens
(--wf-color-text / -text-muted / -on-accent — the CTA text the engine picks white-or-
dark per accent). The brand hues --wf-color-primary/-secondary are fills/accents and
--wf-color-accent is the CTA button fill — never a readable-text foreground. So both
schemes render coherently and no element falls back to the theme white bg or the WP
default #32373c button.

Style only the `wf-*` classes — never the id-tied `.elementor-element-{id}` classes,
which are rebuilt on every compose.
