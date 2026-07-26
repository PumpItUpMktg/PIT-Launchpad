<?php
/**
 * The `lp_area` taxonomy — the towns a piece of content references (§B).
 *
 * A blog post is tagged, at publish, with every coverage town it mentions (the control plane extracts
 * these and sends them in the meta-blob's `towns` array). The taxonomy makes the town a QUERYABLE
 * dimension: a location page can pull `WP_Query(['lp_area' => {its-town-slug}])` to list the posts about
 * its town — additional local content alongside the reviews and jobs on the areas-served page.
 *
 * Registered public + queryable (so front-end queries and any archive work), hierarchical off (towns are
 * a flat vocabulary). One term per town; a re-push replaces the post's town set so a re-tagged post never
 * keeps a stale town.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

if (! defined('ABSPATH')) {
    exit;
}

final class AreaTaxonomy
{
    public const TAXONOMY = 'lp_area';

    public static function register(): void
    {
        register_taxonomy(self::TAXONOMY, ['page', 'post'], [
            'label' => 'Service Area',
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            // A clean /area/{town}/ archive slug — harmless, since the theme drives the local feed by
            // query rather than this archive, but it keeps the town a real, linkable URL if wanted.
            'rewrite' => ['slug' => 'area'],
        ]);
    }

    /**
     * Stamp a post with exactly the towns it references (creating terms as needed). Re-push
     * authoritative: replaces any prior town set so a re-tagged post never carries a stale town.
     * An empty list clears the marker.
     *
     * @param  array<int, array{slug?: string, name?: string}>  $towns
     */
    public static function assign(int $post_id, array $towns): void
    {
        $names = [];
        foreach ($towns as $town) {
            $name = trim((string) ($town['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        // wp_set_object_terms with term NAMES find-or-creates each term, so out-of-order or
        // never-seen towns just work. Empty array clears the post's towns.
        wp_set_object_terms($post_id, $names, self::TAXONOMY, false);
    }
}
