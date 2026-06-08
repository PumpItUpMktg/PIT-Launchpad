# Templates (designer-owned)

This plugin renders **brand-neutral** Elementor templates it does not ship.
Wire your templates like this:

- Build one Elementor template per page type (service, location, …).
- Map `page_type → template` in the `lp_templates` option, e.g.
  `update_option('lp_templates', ['service' => 'elementor_canvas']);`
  (or a Theme Builder template id). Managed pages default to `elementor_canvas`.
- Target templates with the `lp-page-type-{type}` / `lp-kit-{kit}` body classes.
- Inside a template, bind widgets to the `lp/*` dynamic tags and set each tag's
  **Slot key** to a slot from the kit (e.g. `hero_problem`, `faq`, `proof_strip`).
- Styling comes from Elementor's global color/typography kit — never hardcode.
