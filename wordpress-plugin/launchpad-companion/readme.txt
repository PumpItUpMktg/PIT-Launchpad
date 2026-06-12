=== Launchpad Companion ===
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 0.4.5
License: GPLv2 or later

The receiver on each client site for the Launchpad control plane. It implements
the §2 control-plane↔WordPress contract: authed REST upserts, consolidated
slot-blob storage, brand-neutral Elementor rendering via lp/* dynamic tags,
native SEO, a managed sitemap, and 301 redirects. No SEO plugin, no ACF, no
media-library import — images are served from R2/CDN URLs in the payload.

== Contract endpoints (namespace launchpad/v1) ==
* POST /silo       — ensure a hierarchical category mirrors a Silo
* POST /content    — upsert a page/post (idempotent on content_id)
* POST /redirects  — upsert 301s (idempotent on from_url)
* GET  /status     — environment introspection (WP/PHP/Elementor/theme/plugin versions)
* GET  /templates  — inventory of Elementor saved templates (id/title/slug/type/modified/
                     preview/thumbnail) so the control plane maps kits to real templates

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

Posts get NO page template: `elementor_canvas` is a full-page Elementor template
that bypasses Theme Builder *single* templates, so a post is left on the theme
default and a Theme Builder single template (the post body design) drives it via
its display condition. The document <title> is emitted once via core title-tag
(filtered through pre_get_document_title), never a second hand-printed tag.

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
