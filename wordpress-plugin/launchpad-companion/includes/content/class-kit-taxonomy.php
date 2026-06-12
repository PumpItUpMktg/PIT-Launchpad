<?php
/**
 * The `lp_kit` taxonomy — a stable, plugin-owned per-kit marker on managed pages.
 * It is the target an operator points an Elementor Pro Theme Builder "single"
 * template's display condition at (Term: Launchpad Kit → {kit}), so the kit→template
 * mapping chosen in Launchpad actually renders. Per-post and version-independent:
 * it needs no Elementor API (just a taxonomy term), works on the Atomic Editor
 * (V4) where per-post template assignment / page-template hijacking do not, and a
 * body class can't be a condition target but a term can.
 *
 * Registered queryable (so Elementor lists it under "By Taxonomy") but with no
 * public archive/UI clutter. One term per kit; each managed page carries exactly
 * its kit's term.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

if (! defined('ABSPATH')) {
    exit;
}

final class KitTaxonomy
{
    public const TAXONOMY = 'lp_kit';

    public static function register(): void
    {
        register_taxonomy(self::TAXONOMY, ['page', 'post'], [
            'label' => 'Launchpad Kit',
            // Public + show_in_nav_menus so Elementor Pro lists it as a Theme
            // Builder display-condition target ("By Taxonomy"); rewrite stays off
            // to avoid public archive URLs.
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => false,
        ]);
    }

    /**
     * Stamp a page with exactly its kit's term (creating the term if needed).
     * Re-push authoritative: replaces any prior kit term so a re-kitted page
     * never carries a stale marker. A blank kit clears the marker.
     */
    public static function assign(int $post_id, string $kit): void
    {
        $kit = trim($kit);

        if ($kit === '') {
            wp_set_object_terms($post_id, [], self::TAXONOMY, false);

            return;
        }

        wp_set_object_terms($post_id, [$kit], self::TAXONOMY, false);
    }
}
