<?php
/**
 * Launchpad Blocks — theme bootstrap.
 *
 * theme.json remains the single source of truth for tokens (palette, type scale, spacing, radius) and
 * per-variation styling. Two things it CANNOT express are enqueued here:
 *
 *  1. The `.lp-*` SECTION LAYOUTS. The pages the control plane composes are core Gutenberg blocks
 *     tagged with layout classes (`lp-hero`, `lp-services-grid`, `lp-card`, `lp-proof-grid`, `lp-cta`,
 *     …). theme.json styles block TYPES, not arbitrary classes, so the grid/card/hero arrangements
 *     live in assets/theme.css — keyed to those classes with real specificity, so they win over the
 *     zero-specificity `:where()` layout rules WordPress injects from theme.json.
 *
 *  2. The bundled heading @font-face rules for EVERY variation (Archivo / Manrope / Bricolage). WP
 *     only prints font-faces for the *active* variation's families; declaring them all here means the
 *     heading webfont always loads, so switching a style variation can never fall back to system-ui.
 *
 * A block theme does not auto-enqueue any front-end stylesheet, so this hook is the delivery path.
 *
 * @package LaunchpadBlocks
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function (): void {
    $theme = wp_get_theme();
    $version = (string) $theme->get('Version');
    $dir = get_stylesheet_directory_uri();

    wp_enqueue_style('launchpad-blocks', $dir.'/assets/theme.css', [], $version);

    // "Areas we serve" interactive map: self-hosted Leaflet + init, loaded ONLY on a singular page
    // that actually carries map geometry (the companion plugin's `_lp_area_map` meta) — so Leaflet
    // never loads on a page without a map. The plugin prints the geometry as window.lpAreaMap early
    // in the footer; assets/area-map.js reads it + draws into .lp-areas-map (vector marks, no image
    // deps). No geometry → nothing enqueues and the section shows its text fallback.
    if (is_singular() && get_post_meta(get_queried_object_id(), '_lp_area_map', true)) {
        wp_enqueue_style('leaflet', $dir.'/assets/vendor/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('leaflet', $dir.'/assets/vendor/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('launchpad-area-map', $dir.'/assets/area-map.js', ['leaflet'], $version, true);
    }
});
