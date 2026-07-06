# Bundled webfonts

Self-hosted **latin-subset** `woff2` webfonts for the Launchpad block theme — the heading typefaces
for the three style variations plus Inter for body. Bundled locally (not loaded from a CDN) so the
output is portable and privacy-clean: no third-party font request leaves the visitor's browser.

Referenced from `theme.json` / `styles/*.json` via `fontFace` `src: file:./assets/fonts/…`.

| File | Family · weight | Used by |
| --- | --- | --- |
| `archivo-800.woff2` | Archivo 800 | Bold & Direct (heading) |
| `manrope-700.woff2` | Manrope 700 | Clean & Trustworthy (heading) |
| `bricolage-grotesque-700.woff2` | Bricolage Grotesque 700 | Warm & Local (heading) |
| `inter-400.woff2` · `inter-600.woff2` | Inter 400 / 600 | body (all variations) |

## License & attribution

All four families are licensed under the **SIL Open Font License, Version 1.1** (OFL-1.1), which
permits bundling and redistribution with the theme. Full license text: <https://openfontlicense.org>.

- **Archivo** — © The Archivo Project Authors (github.com/Omnibus-Type/Archivo)
- **Manrope** — © The Manrope Project Authors (github.com/sharanda/manrope)
- **Bricolage Grotesque** — © The Bricolage Grotesque Project Authors (github.com/ateliertriay/bricolage)
- **Inter** — © The Inter Project Authors (github.com/rsms/inter)

Files are the `latin` unicode-range subset served by Google Fonts. To refresh or add weights, re-fetch
the latin-subset `woff2` for the family/weight and add a matching `fontFace` entry.
