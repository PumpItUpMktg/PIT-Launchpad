# Claude Code Relay — Elementor Wireframe Library (`.json`)

**Goal:** produce importable Elementor `.json` for the entire library — block templates, page assemblies, and the two Theme Builder templates. Output is `.json`, not HTML.

**Sources of truth:**
- `wireframe-service-page.html`, `wireframe-location-page.html` — detailed layouts
- `wireframe-all-pages.html` — every page's block composition + new blocks
- `wireframe-header-footer.html` — header/footer tiers + columns
- Spec tables (this doc, §7) — image sizes + char ranges

**Library shape:** block-level templates (the reusable unit) + page-assembly templates that compose them in order + header/footer Theme Builder templates.

---

## 1. Step 0 — capture the schema before generating

Elementor's JSON envelope is version-sensitive. **Do not hand-author it from memory.**

1. On the target install, export one **container** template (with a Heading, Text Editor, Image, Button), one **header** Theme Builder template, and one **footer** Theme Builder template.
2. Use their exact `version` strings, `type` values, and key names as canonical.
3. Generate everything to match. The golden example (§5) is a shape reference; the live export wins on any mismatch.

---

## 2. Conventions (hard rules)

1. **Containers**, not legacy sections/columns — match the captured export.
2. **Inherit the Global Kit.** Headings = Heading widget + `header_size` h1/h2/h3, **no local typography/color**. Text = Text Editor, no local type/color. Buttons inherit global button style. Only **layout** (flex, widths, gaps, spacing, alignment) is set locally — never font or color.
3. **Images** = Image widget, `image_size: custom`, exact px from §7. Ship a placeholder image at that exact dimension. Tag AI-gen vs client-supplied.
4. **Stable hooks** — Elementor's element `id` is random per export. Give every content element a stable `_css_classes` of the form `wf-<block>-<element>` (mirror in `_title`). The generation sidecar and any later dynamic binding target these classes.
5. **Char ranges / image sizes are NOT in the `.json`.** They live in `wireframe-spec.json`, keyed by the stable class.
6. **Schema is out of scope.** Do **not** add JSON-LD or microdata to any template. Launchpad injects Organization/LocalBusiness schema via `wp_head`, site-wide.
7. **Logo has two variants** — standard (light) and reversed (dark footer), same 200×48 box.

### Widget dependencies (Elementor Pro — confirmed in stack)
These templates rely on Pro widgets: Theme Builder (header, footer, blog single), **Nav Menu** (header menus), **Loop Grid / Posts** (post-grid, blog index), **Form** (contact-form), **Post Content** (blog body), and dynamic tags. Build to Pro; do not substitute core fallbacks that break Theme Builder.

---

## 3. Output files

**Blocks:**
`block-hero` · `block-trust-bar` · `block-problem-solution` · `block-why-us` · `block-proof-strip` · `block-testimonials` · `block-jobs` · `block-faq` · `block-final-cta` · `block-intro` · `block-area-intro` · `block-nap-map` · `block-service-list` · `block-services-grid` · `block-areas-teaser` · `block-markets-grid` · `block-story` · `block-team-grid` · `block-contact-form` · `block-hours` · `block-post-grid` · `block-article-header` · `block-article-body` · `block-author-share` · `block-basic-content` · `block-utility-message`

**Theme Builder:** `tb-header` · `tb-footer`

**Pages (assemblies, §6):** `page-home` · `page-service-hub` · `page-service` · `page-service-in-location` · `page-location` · `page-areas-hub` · `page-about` · `page-contact` · `page-blog-index` · `page-blog-post` · `page-basic-content` · `page-404` · `page-thank-you`

**Sidecar:** `wireframe-spec.json` — every `wf-*` class → `{ type, image_size?, char_range?, source }`.

---

## 4. Block inventory

Format: **block** `[variants/params]` — element (`class` · spec). Variant differences noted inline.

**block-hero** `variants: problem_led · area_led · brand_led · category_led · slim`
eyebrow (`wf-hero-eyebrow` · text 12–28) · headline (`wf-hero-headline` · H1; problem/brand/category 30–60, area 40–70, slim 20–45) · subhead (`wf-hero-subhead` · text 90–160; slim 60–120) · cta_primary (`wf-hero-cta-primary` · button) · cta_phone (`wf-hero-cta-phone` · button) · image (`wf-hero-image` · IMG 1200×900; **area/brand = brand image, no AI local scene**; slim omits image)

**block-trust-bar** — ×4 pair: label (`wf-trust-label-{n}` · text 6–12) · value (`wf-trust-value-{n}` · H3 8–18)

**block-problem-solution** — heading (`wf-ps-heading` · H2 30–55) · ×2 sides {problem|solution}: eyebrow (`wf-ps-{side}-eyebrow` · text 8–16) · sub (`wf-ps-{side}-sub` · H3 18–36) · body (`wf-ps-{side}-body` · text 180–300) · list (`wf-ps-{side}-list` · text 3×25–55)

**block-why-us** `variants: claims · local` — heading (`wf-why-heading` · H2 30–55) · [local: lead (`wf-why-lead` · text 120–220)] · ×3 card: title (`wf-why-card-{n}-title` · H3 18–40) · body (`wf-why-card-{n}-body` · text 90–150; local proof points 40–90)

**block-proof-strip** — ×5 image (`wf-proof-logo-{n}` · IMG 200×80, client-supplied)

**block-testimonials** `param: geo_scope = service|radius|county|none` — heading (`wf-reviews-heading` · H2 30–55) · rating (`wf-reviews-rating` · text) · ×3 card: body (`wf-review-{n}-body` · text 110–220) · name (`wf-review-{n}-name` · text 8–20) · [location: town (`wf-review-{n}-town` · text 6–18)]

**block-jobs** `param: geo_scope; service filter` — heading (`wf-jobs-heading` · H2 30–55) · ×4 card: image (`wf-job-{n}-image` · IMG 800×600, AI-gen) · town (`wf-job-{n}-town` · text 6–18) · title (`wf-job-{n}-title` · H3 18–40) · body (`wf-job-{n}-body` · text 60–120)

**block-faq** `Accordion widget` — heading (`wf-faq-heading` · H2 30–55) · ×N item: question (`wf-faq-q-{n}` · H3 35–80) · answer (`wf-faq-a-{n}` · text 140–300)

**block-final-cta** — heading (`wf-cta-heading` · H2 25–50) · body (`wf-cta-body` · text 80–150) · cta_primary (`wf-cta-primary` · button) · cta_phone (`wf-cta-phone` · button)

**block-intro** — heading (`wf-intro-heading` · H2 30–55) · body (`wf-intro-body` · text 180–320)

**block-area-intro** — heading (`wf-area-heading` · H2 30–55) · body (`wf-area-body` · text 220–400) · grounded · client-override

**block-nap-map** `conditional: is_storefront` — nap (`wf-nap` · text) · map (`wf-map` · IMG/embed 800×500) · **storefront only; suppressed for SAB**

**block-service-list** `order: priority` — heading (`wf-svclist-heading` · H2 30–55) · ×N item (`wf-svc-item-{n}` · text 12–32, link)

**block-services-grid** — heading (`wf-svcgrid-heading` · H2 30–55) · ×N card: image (`wf-svccard-{n}-image` · IMG 600×400, AI-gen) · title (`wf-svccard-{n}-title` · H3 18–40) · body (`wf-svccard-{n}-body` · text 60–120, link)

**block-areas-teaser** — heading (`wf-areateaser-heading` · H2 30–55) · map (`wf-areateaser-map` · IMG 800×500) · ×N link (`wf-areateaser-link-{n}` · text 8–24)

**block-markets-grid** — heading (`wf-markets-heading` · H2 30–55) · map (`wf-markets-map` · IMG 800×500) · ×N card (`wf-market-{n}` · text 8–24, link)

**block-story** — heading (`wf-story-heading` · H2 30–55) · body (`wf-story-body` · text 400–700) · image (`wf-story-image` · IMG 800×600, AI-gen)

**block-team-grid** — heading (`wf-team-heading` · H2 30–55) · ×N card: image (`wf-team-{n}-image` · IMG 400×400 1:1, real headshot) · name (`wf-team-{n}-name` · H3 8–28) · role (`wf-team-{n}-role` · text 8–32)

**block-contact-form** `Form widget (Pro)` — fields name/email/phone/message + submit (`wf-contact-form`). Posts to thank-you page.

**block-hours** — ×N row: day (`wf-hours-{n}-day` · text) · time (`wf-hours-{n}-time` · text)

**block-post-grid** `also related-posts (×3)` — ×N card: image (`wf-post-{n}-image` · IMG 640×360, AI-gen) · meta (`wf-post-{n}-meta` · text) · title (`wf-post-{n}-title` · H3 30–70) · excerpt (`wf-post-{n}-excerpt` · text 80–160). On index, use Loop Grid + pagination.

**block-article-header** — meta (`wf-article-meta` · text date/author/category) · title (`wf-article-title` · H1 30–80) · byline (`wf-article-byline` · text) · featured (`wf-article-featured` · IMG 1200×675, AI-gen)

**block-article-body** — **Post Content widget** (`wf-article-body`). The generated post renders here via Theme Builder dynamic content, not a static text area. Inline images target 1000×667.

**block-author-share** `optional` — avatar (`wf-author-avatar` · IMG 96×96 1:1) · bio (`wf-author-bio` · text) · share (`wf-author-share`)

**block-basic-content** — title (`wf-basic-title` · H1 12–50) · updated (`wf-basic-updated` · text) · body (`wf-basic-body` · rich text / editor region, no char cap)

**block-utility-message** `404 + thank-you` — heading (`wf-util-heading` · H1 8–40) · body (`wf-util-body` · text 40–120) · cta (`wf-util-cta` · button) · [404: helpful links]

---

## 4a. Theme Builder templates

**tb-header** `type: header` — three tiers:
- Tier 1 utility: phone (`wf-hd-phone` · text) · email (`wf-hd-email` · text) · address (`wf-hd-address` · text) · social (`wf-hd-social` · icons 24×24) · *REC* rating chip (`wf-hd-rating`)
- Tier 2 main: logo (`wf-hd-logo` · IMG 200×48 standard) · Nav Menu (main menu) · *REC* cta (`wf-hd-cta` · button) · *REC* click-to-call (`wf-hd-call`)
- Tier 3 silos: silo links (`wf-hd-silo-{n}` · text 8–28) · *REC* mega-menu → services
- Mobile: hamburger → off-canvas (Nav Menu responsive) holding menu + silos + contact + CTA.

**tb-footer** `type: footer` — 4 columns + bottom bar:
- Col1: logo reversed (`wf-ft-logo` · IMG 200×48 reversed) · about (`wf-ft-about` · text 60–140) · NAP (`wf-ft-nap` · text) · *REC* license/insurance (`wf-ft-license`) · *REC* social (`wf-ft-social`)
- Col2 services (`wf-ft-services`) · Col3 service areas (`wf-ft-areas`) · Col4 company (`wf-ft-company`) + *REC* legal links (`wf-ft-legal` → privacy/terms/accessibility/disclaimer/sitemap)
- *REC* accreditation badges (`wf-ft-badge-{n}` · IMG 160×80)
- Bottom: copyright (`wf-ft-copyright` · text) · agency credit (`wf-ft-credit` · text+link → "Built & managed by Pump It Up Marketing LLC")

*REC = recommended; keep or cut per Eric.*

---

## 5. Golden example — `block-hero.json` (shape reference)

No typography/color present → inherits the kit. Stable hooks via `_css_classes`. Reconcile envelope against Step 0.

```json
{
  "version": "0.4",
  "title": "Block - Hero",
  "type": "container",
  "page_settings": [],
  "content": [
    {
      "id": "hero000", "elType": "container", "isInner": false,
      "settings": { "content_width": "boxed", "flex_direction": "row", "flex_align_items": "center", "flex_gap": { "unit": "px", "size": 34, "column": "34", "row": "34" }, "_title": "block-hero", "_css_classes": "wf-block wf-block-hero" },
      "elements": [
        { "id": "herocl1", "elType": "container", "isInner": true,
          "settings": { "flex_direction": "column", "width": { "unit": "%", "size": 52 }, "flex_gap": { "unit": "px", "size": 18, "column": "18", "row": "18" } },
          "elements": [
            { "id": "heroeyb", "elType": "widget", "widgetType": "text-editor", "settings": { "editor": "<p>Service eyebrow</p>", "_title": "hero_eyebrow", "_css_classes": "wf-hero-eyebrow" }, "elements": [] },
            { "id": "herohd", "elType": "widget", "widgetType": "heading", "settings": { "title": "Primary headline goes here", "header_size": "h1", "_title": "hero_headline", "_css_classes": "wf-hero-headline" }, "elements": [] },
            { "id": "herosb", "elType": "widget", "widgetType": "text-editor", "settings": { "editor": "<p>Subhead stating the solution and one proof point.</p>", "_title": "hero_subhead", "_css_classes": "wf-hero-subhead" }, "elements": [] },
            { "id": "herobtn", "elType": "container", "isInner": true, "settings": { "flex_direction": "row", "flex_gap": { "unit": "px", "size": 12, "column": "12", "row": "12" } },
              "elements": [
                { "id": "herob1", "elType": "widget", "widgetType": "button", "settings": { "text": "Primary CTA", "_title": "hero_cta_primary", "_css_classes": "wf-hero-cta-primary" }, "elements": [] },
                { "id": "herob2", "elType": "widget", "widgetType": "button", "settings": { "text": "Phone CTA", "_title": "hero_cta_phone", "_css_classes": "wf-hero-cta-phone" }, "elements": [] }
              ] }
          ] },
        { "id": "herocl2", "elType": "container", "isInner": true, "settings": { "flex_direction": "column", "width": { "unit": "%", "size": 48 } },
          "elements": [
            { "id": "heroimg", "elType": "widget", "widgetType": "image",
              "settings": { "image": { "url": "https://PLACEHOLDER.local/wf/hero-1200x900.png", "id": 0 }, "image_size": "custom", "image_custom_dimension": { "width": "1200", "height": "900" }, "_title": "hero_image", "_css_classes": "wf-hero-image" },
              "elements": [] }
          ] }
      ]
    }
  ]
}
```

---

## 6. Page assemblies (block order)

1. **page-home** `/` — header → hero(brand_led) → trust-bar → services-grid → why-us → areas-teaser → testimonials → jobs → faq → final-cta → footer
2. **page-service-hub** `/services/[silo]` — header → hero(category_led) → intro → services-grid → why-us → testimonials → final-cta → footer
3. **page-service** `/services/[silo]/[service]` — header → hero(problem_led) → trust-bar → problem-solution → why-us(claims) → proof-strip → testimonials(service) → jobs(service) → faq → final-cta → footer
4. **page-service-in-location** `/[market]/[service]` — header → hero(area_led) → trust-bar → problem-solution → area-intro → nap-map(cond) → why-us(local) → testimonials(proximity+service) → jobs(proximity+service) → faq → final-cta → footer
5. **page-location** `/[market]` — header → hero(area_led) → area-intro → nap-map(cond) → service-list → why-us(local) → testimonials(proximity) → jobs(proximity) → faq(optional) → final-cta → footer
6. **page-areas-hub** `/service-areas` — header → hero → intro → markets-grid → testimonials → final-cta → footer
7. **page-about** `/about` — header → hero(brand_led) → story → trust-bar → team-grid → why-us(claims) → proof-strip → testimonials → final-cta → footer
8. **page-contact** `/contact` — header → hero(slim) → [contact-form + hours + nap] → nap-map(map) → final-cta → footer
9. **page-blog-index** `/blog` — header → hero(slim) → post-grid(Loop Grid + pagination) → final-cta → footer
10. **page-blog-post** `/blog/[post]` **(Theme Builder Single)** — header → article-header → article-body(Post Content) → author-share(opt) → final-cta → post-grid(related ×3) → footer
11. **page-basic-content** `/privacy · /terms · /accessibility · /disclaimer` — header → basic-content → footer
12. **page-404** `/404` — header → utility-message → footer
13. **page-thank-you** `/thank-you` — header → utility-message → footer

---

## 7. Sidecar spec

### Images
| element / class | size (px) | ratio | source |
|---|---|---|---|
| `wf-hero-image` | 1200 × 900 | 4:3 | AI-gen → real (brand image on area/brand variants) |
| `wf-job-{n}-image` | 800 × 600 | 4:3 | AI-gen → real |
| `wf-svccard-{n}-image` | 600 × 400 | 3:2 | AI-gen → real |
| `wf-story-image` | 800 × 600 | 4:3 | AI-gen → real |
| `wf-team-{n}-image` | 400 × 400 | 1:1 | real headshot |
| `wf-map` / `wf-areateaser-map` / `wf-markets-map` | 800 × 500 | 8:5 | embed / static |
| `wf-article-featured` | 1200 × 675 | 16:9 | AI-gen (news-to-blog) |
| inline body image | 1000 × 667 | 3:2 | AI-gen / upload |
| `wf-post-{n}-image` | 640 × 360 | 16:9 | AI-gen |
| `wf-author-avatar` | 96 × 96 | 1:1 | real |
| `wf-hd-logo` / `wf-ft-logo` | 200 × 48 | ~25:6 | client (standard + reversed) |
| social icons | 24 × 24 | 1:1 | icon set |
| `wf-proof-logo-{n}` | 200 × 80 | 5:2 | client |
| `wf-ft-badge-{n}` | 160 × 80 | 2:1 | client |

### Text (char ranges)
| element | type | range |
|---|---|---|
| hero eyebrow | text | 12–28 |
| hero headline | H1 | 30–70 (slim 20–45) |
| hero subhead | text | 90–160 (slim 60–120) |
| section heading (H2 blocks) | H2 | 30–55 |
| intro / category body | text | 180–320 |
| problem/solution sub | H3 | 18–36 |
| problem/solution body | text | 180–300 |
| list item | text | 25–55 |
| why-us card heading | H3 | 18–40 |
| why-us card / local point | text | 90–150 / 40–90 |
| why-us local lead | text | 120–220 |
| area intro body | text | 220–400 |
| service / market item | text | 12–32 / 8–24 |
| review body | text | 110–220 |
| reviewer name / town | text | 8–20 / 6–18 |
| job title | H3 | 18–40 |
| job body / town | text | 60–120 / 6–18 |
| service card title / body | H3 / text | 18–40 / 60–120 |
| story body | text | 400–700 |
| team name / role | H3 / text | 8–28 / 8–32 |
| FAQ question / answer | H3 / text | 35–80 / 140–300 |
| final CTA heading / body | H2 / text | 25–50 / 80–150 |
| trust label / value | text / H3 | 6–12 / 8–18 |
| post title / excerpt | H3 / text | 30–70 / 80–160 |
| article title | H1 | 30–80 |
| basic-content title | H1 | 12–50 |
| utility heading / body | H1 / text | 8–40 / 40–120 |
| footer about | text | 60–140 |

---

## 8. Acceptance criteria

1. Every `.json` imports into Elementor with zero errors.
2. With a configured Global Kit, all headings/text/buttons render in the kit's fonts/colors — **no local type/color overrides in any JSON**.
3. Image widgets render placeholders at exact §7 dimensions; replacing the image preserves the size.
4. Every content element carries a unique, stable `wf-*` class per §4.
5. `tb-header` and `tb-footer` import as Theme Builder header/footer; `page-blog-post` imports as a Single template using the Post Content widget.
6. Each page assembly composes its blocks in §6 order and imports cleanly.
7. `wireframe-spec.json` resolves every `wf-*` class to type, image size (if any), char range (if any), and source.
8. **No JSON-LD or microdata in any template** (schema is Launchpad's, via `wp_head`).
