=== Launchpad Companion ===
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 0.4.0
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

Dynamic tags (classic V3 editor only): lp/text, lp/image, lp/cta, lp/map,
lp/repeater render the same content via the shared SlotRenderer. They are skipped
safely when Elementor's classic dynamic-tag API is absent (e.g. Atomic-only), so
they can never fatal page rendering — use the shortcodes there.

== Templates ==
Brand-neutral Elementor templates are built by the designer. Map each page type
to a template via the `lp_templates` option (page_type => template); pages also
carry `lp-page-type-{type}` and `lp-kit-{kit}` body classes for Theme Builder
display conditions.
