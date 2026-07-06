# Launchpad block theme

A **self-contained block theme** — the WordPress side of the Elementor → Gutenberg pivot. All brand
styling lives in `theme.json` and its style variations; there is no page builder and no Global Kit.
Pages are core Gutenberg blocks, so the client is never locked into a proprietary builder (the whole
point of the pivot). **No parent theme required** — it ships its own templates and parts, so it
activates cleanly on any WordPress 6.6+ without a separate parent install.

## Files
- `style.css` — the theme declaration (standalone; no `Template:` parent). Intentionally CSS-free:
  the brand-styling surface is `theme.json`, single and only.
- `templates/` — block templates: `page.html` (renders a generated page's `post_content`, the key
  one), `single.html` (posts), `index.html` (blog/fallback).
- `parts/` — `header.html` (site title + navigation) and `footer.html` template parts.
- `theme.json` — the base token vocabulary the block **patterns bind to**: the color palette slugs
  (`base` / `surface` / `contrast` / `muted` / `border` / `primary` / `accent` / `on-accent`), the
  type scale, spacing scale, and custom properties (`radius`, `headingWeight`,
  `headingLetterSpacing`). Patterns reference these by slug, so they are style-agnostic.
- `styles/{bold,clean,warm}.json` — the three style variations (Bold & Direct / Clean & Trustworthy /
  Warm & Local). Each overrides the brand palette, heading typeface, radius and tracking. Switching
  the active variation restyles every page without regenerating content.

## Single source of truth
The variation tokens mirror `app/Styling/StyleVariation.php` on the control-plane side (the recommender
picks a variation; the Brand step writes its tokens to the site's `theme.json`). `ThemeVariationTest`
locks the two together so they can't drift — change the tokens in one place and the test fails until
both agree.

## Webfonts
Heading typefaces (Archivo 800 · Manrope 700 · Bricolage Grotesque 700) and the Inter body font are
**bundled locally** as latin-subset `woff2` under `assets/fonts/`, wired via `theme.json` /
`styles/*.json` `fontFace` `src: file:./assets/fonts/…`. Self-hosted, not a CDN link — portable and
privacy-clean. See `assets/fonts/README.md` for the files and their OFL license.

## Install (WP-side, per tenant)
Ships with the site (Layer 6 onboarding adds an "install / activate the block theme" step, replacing
the Elementor install). Twenty Twenty-Five must be present as the parent. The active style variation
is set from the operator's recommendation choice.
