=== Launchpad Companion ===
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 0.2.1
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

== Dynamic tags ==
lp/text, lp/image, lp/cta, lp/map, lp/repeater — read the consolidated meta blob
once per render (request-cached) and feed the Elementor template. Repeaters
collapse cleanly when their slot is empty.

== Templates ==
Brand-neutral Elementor templates are built by the designer. Map each page type
to a template via the `lp_templates` option (page_type => template); pages also
carry `lp-page-type-{type}` and `lp-kit-{kit}` body classes for Theme Builder
display conditions.
