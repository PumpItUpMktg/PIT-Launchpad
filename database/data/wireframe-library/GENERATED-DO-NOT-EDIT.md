# GENERATED — DO NOT EDIT

This directory is a **read-only structural input**: the output of the wireframe
library's `build.php` generator (upstream, not in this repo). Treat every file here
as vendored/generated.

**Do not hand-edit these JSON files.** A library regen would silently overwrite the
change. All live-target reconciliation (envelope/version, the classic→nested
accordion transform, any per-widget 4.1.3 shape deltas) lives OUTSIDE the library:

- `config/elementor_target.php` — the target profile (the deltas)
- `App\PageBuilder\Library\TargetNormalizer` — normalize-on-load (applies them)

The composer reads a block here, normalizes it through the profile, injects content
into the `wf-*` hooks, and assembles the page — so nothing the live target needs is
ever stored in (or lost from) this library.
