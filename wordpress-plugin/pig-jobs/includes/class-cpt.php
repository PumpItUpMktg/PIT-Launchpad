<?php
/**
 * The pig_job CPT + prefixed pig_city / pig_service taxonomies. Public, has_archive, show_in_rest — so the
 * jobs are indexable URLs and appear natively in block-theme + page-builder query loops. Rewrite slugs are
 * filterable so a site can rename the URL bases.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

if (! defined('ABSPATH')) {
    exit;
}

final class Cpt
{
    public const POST_TYPE = 'pig_job';

    public const TAX_CITY = 'pig_city';

    public const TAX_SERVICE = 'pig_service';

    // Meta keys.
    public const JOB_ID = '_pig_job_id';

    public const JOB_DATA = '_pig_job_data';

    public const JOB_MEDIA = '_pig_job_media';

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Jobs', 'pig-jobs'),
                'singular_name' => __('Job', 'pig-jobs'),
                'menu_name' => __('Jobs', 'pig-jobs'),
            ],
            'public' => true,
            'has_archive' => true,
            'show_in_rest' => true,
            'show_ui' => true,
            'menu_icon' => 'dashicons-camera',
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'rewrite' => ['slug' => (string) apply_filters('pig_jobs_rewrite_slug', 'jobs'), 'with_front' => false],
            'taxonomies' => [self::TAX_CITY, self::TAX_SERVICE],
        ]);

        self::register_taxonomy(self::TAX_CITY, __('City', 'pig-jobs'), (string) apply_filters('pig_jobs_city_slug', 'job-city'));
        self::register_taxonomy(self::TAX_SERVICE, __('Service', 'pig-jobs'), (string) apply_filters('pig_jobs_service_slug', 'job-service'));
    }

    private static function register_taxonomy(string $taxonomy, string $label, string $slug): void
    {
        register_taxonomy($taxonomy, [self::POST_TYPE], [
            'label' => $label,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => ['slug' => $slug],
        ]);
    }

    /**
     * Replace a post's terms in a taxonomy from {name|label, slug} pairs (creating as needed). Empty clears.
     *
     * @param  array<int, array<string, mixed>>  $terms
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
