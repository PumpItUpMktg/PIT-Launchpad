<?php
/**
 * The `pig_job` custom post type + its `pig_city` / `pig_service` taxonomies — the Job Capture render
 * surface (§10). Registered public + `show_in_rest` + `has_archive` so the jobs are real, indexable URLs
 * and appear natively in every block-theme query loop and page-builder loop widget (Elementor Loop Grid,
 * Divi Blog, stock query loops) — no custom rendering needed for those. Everything is PREFIXED (`pig_`) so
 * a bare `service`/`city` taxonomy can never collide with a theme's own.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

if (! defined('ABSPATH')) {
    exit;
}

final class JobCpt
{
    public const POST_TYPE = 'pig_job';

    public const TAX_CITY = 'pig_city';

    public const TAX_SERVICE = 'pig_service';

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Jobs',
                'singular_name' => 'Job',
                'menu_name' => 'Jobs',
            ],
            'public' => true,
            'has_archive' => true,
            'show_in_rest' => true,
            'show_ui' => true,
            'menu_icon' => 'dashicons-camera',
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'rewrite' => ['slug' => apply_filters('pig_job_rewrite_slug', 'jobs'), 'with_front' => false],
            'taxonomies' => [self::TAX_CITY, self::TAX_SERVICE],
        ]);

        register_taxonomy(self::TAX_CITY, [self::POST_TYPE], [
            'label' => 'City',
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => ['slug' => apply_filters('pig_city_rewrite_slug', 'job-city')],
        ]);

        register_taxonomy(self::TAX_SERVICE, [self::POST_TYPE], [
            'label' => 'Service',
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => ['slug' => apply_filters('pig_service_rewrite_slug', 'job-service')],
        ]);
    }

    /**
     * Replace a job post's terms in a taxonomy from a list of {name, slug} pairs (creating terms as
     * needed). Re-push authoritative: an empty list clears the set so a re-tagged job never keeps a stale
     * term.
     *
     * @param  array<int, array{name?: string, slug?: string, label?: string}>  $terms
     */
    public static function assign(int $post_id, string $taxonomy, array $terms): void
    {
        $names = [];
        foreach ($terms as $term) {
            $name = trim((string) ($term['name'] ?? $term['label'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        wp_set_object_terms($post_id, array_keys($names), $taxonomy, false);
    }
}
