<?php
/**
 * Generates the XML sitemap index + a managed-content sitemap from the posts
 * this plugin manages, and references it from robots.txt. Core's sitemap is
 * disabled so this serves at /sitemap.xml. No SEO plugin required.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

use Launchpad\Companion\Content\JobCpt;

if (! defined('ABSPATH')) {
    exit;
}

final class Sitemap
{
    public function register(): void
    {
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'maybe_render']);
        add_filter('robots_txt', [$this, 'robots_txt'], 10, 1);
    }

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule('^sitemap\.xml$', 'index.php?lp_sitemap=index', 'top');
        add_rewrite_rule('^sitemap-content\.xml$', 'index.php?lp_sitemap=content', 'top');
        add_rewrite_rule('^sitemap-jobs\.xml$', 'index.php?lp_sitemap=jobs', 'top');
    }

    /**
     * @param  array<int, string>  $vars
     * @return array<int, string>
     */
    public function query_vars(array $vars): array
    {
        $vars[] = 'lp_sitemap';

        return $vars;
    }

    public function maybe_render(): void
    {
        $type = get_query_var('lp_sitemap');

        if ($type === '' || $type === false) {
            return;
        }

        header('Content-Type: application/xml; charset=UTF-8');

        if ($type === 'index') {
            $xml = $this->render_index();
        } elseif ($type === 'jobs') {
            $xml = $this->render_jobs();
        } else {
            $xml = $this->render_content();
        }

        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    private function render_index(): string
    {
        // The core content sitemap is always present; the jobs child is listed only when there is at least
        // one quality-passing published job, so Google is never handed an empty (or thin) jobs sitemap.
        $children = ['sitemap-content.xml'];
        if ($this->job_ids() !== []) {
            $children[] = 'sitemap-jobs.xml';
        }

        $items = '';
        foreach ($children as $child) {
            $items .= '<sitemap><loc>' . esc_url(home_url('/' . $child)) . '</loc></sitemap>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . $items
            . '</sitemapindex>';
    }

    private function render_content(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($this->managed_posts() as $post_id) {
            $xml .= '<url><loc>' . esc_url((string) get_permalink($post_id)) . '</loc>'
                . '<lastmod>' . esc_html((string) get_post_modified_time('c', true, $post_id)) . '</lastmod>'
                . '</url>';
        }

        return $xml . '</urlset>';
    }

    /**
     * @return array<int, int>
     */
    private function managed_posts(): array
    {
        return array_map('intval', get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => Meta::CONTENT_ID,
            'meta_compare' => 'EXISTS',
            'suppress_filters' => false,
        ]));
    }

    /**
     * The Job Capture sitemap — its own child so operators can watch job indexation in isolation in GSC
     * (and so a thin job page can never dilute the core-content sitemap's coverage signal).
     */
    private function render_jobs(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($this->job_ids() as $post_id) {
            $xml .= '<url><loc>' . esc_url((string) get_permalink($post_id)) . '</loc>'
                . '<lastmod>' . esc_html((string) get_post_modified_time('c', true, $post_id)) . '</lastmod>'
                . '</url>';
        }

        return $xml . '</urlset>';
    }

    /**
     * Published `pig_job` posts that clear the quality bar for indexing. Thin jobs are kept OUT of the
     * sitemap so Google is never handed doorway-style stubs — the biggest risk with programmatic job pages.
     *
     * @return array<int, int>
     */
    private function job_ids(): array
    {
        $ids = array_map('intval', get_posts([
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => Meta::JOB_ID,
            'meta_compare' => 'EXISTS',
            'suppress_filters' => false,
        ]));

        return array_values(array_filter($ids, [$this, 'is_indexable_job']));
    }

    /**
     * The per-job quality gate: a real photo (featured image), a substantive write-up (non-empty body), and
     * a resolved location (a `pig_city` term). All three must be present.
     */
    private function is_indexable_job(int $post_id): bool
    {
        if (! has_post_thumbnail($post_id)) {
            return false;
        }

        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || trim((string) $post->post_content) === '') {
            return false;
        }

        $cities = wp_get_post_terms($post_id, JobCpt::TAX_CITY, ['fields' => 'ids']);

        return ! is_wp_error($cities) && $cities !== [];
    }

    public function robots_txt(string $output): string
    {
        return $output . "\nSitemap: " . home_url('/sitemap.xml') . "\n";
    }
}
