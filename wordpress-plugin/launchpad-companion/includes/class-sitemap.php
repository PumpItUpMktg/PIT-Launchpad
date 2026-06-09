<?php
/**
 * Generates the XML sitemap index + a managed-content sitemap from the posts
 * this plugin manages, and references it from robots.txt. Core's sitemap is
 * disabled so this serves at /sitemap.xml. No SEO plugin required.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

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

        echo $type === 'index' ? $this->render_index() : $this->render_content(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    private function render_index(): string
    {
        $loc = esc_url(home_url('/sitemap-content.xml'));

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<sitemap><loc>' . $loc . '</loc></sitemap>'
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

    public function robots_txt(string $output): string
    {
        return $output . "\nSitemap: " . home_url('/sitemap.xml') . "\n";
    }
}
