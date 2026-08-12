<?php
/**
 * The two Job Capture render paths (§10): the `[pig_jobs]` shortcode and a server-rendered `launchpad/
 * pig-jobs` block (the fallback for builders that can't query a CPT directly). Both list published
 * `pig_job` posts, optionally filtered by city / service term. Correctly-registered CPT + taxonomies mean
 * Elementor Loop Grid, Divi Blog, and stock query loops also see the jobs natively — these two paths are
 * for everything else. Also shows an admin notice on the job editor, since a client's edits are overwritten
 * on the next sync.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Content\JobCpt;
use WP_Post;
use WP_Query;

if (! defined('ABSPATH')) {
    exit;
}

final class JobRender
{
    public function register(): void
    {
        add_shortcode('pig_jobs', [$this, 'shortcode']);
        add_action('init', [$this, 'register_block']);
        add_action('edit_form_top', [$this, 'edit_notice']);
    }

    public function register_block(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        register_block_type('launchpad/pig-jobs', [
            'api_version' => 2,
            'render_callback' => [$this, 'render_block'],
            'attributes' => [
                'city' => ['type' => 'string', 'default' => ''],
                'service' => ['type' => 'string', 'default' => ''],
                'count' => ['type' => 'number', 'default' => 12],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function render_block(array $attributes): string
    {
        return $this->render([
            'city' => (string) ($attributes['city'] ?? ''),
            'service' => (string) ($attributes['service'] ?? ''),
            'count' => (int) ($attributes['count'] ?? 12),
        ]);
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function shortcode($atts): string
    {
        $atts = shortcode_atts(
            ['city' => '', 'service' => '', 'count' => 12],
            is_array($atts) ? $atts : [],
            'pig_jobs'
        );

        return $this->render($atts);
    }

    /**
     * @param  array<string, mixed>  $atts
     */
    private function render(array $atts): string
    {
        $tax_query = [];
        if (trim((string) ($atts['city'] ?? '')) !== '') {
            $tax_query[] = ['taxonomy' => JobCpt::TAX_CITY, 'field' => 'slug', 'terms' => sanitize_title((string) $atts['city'])];
        }
        if (trim((string) ($atts['service'] ?? '')) !== '') {
            $tax_query[] = ['taxonomy' => JobCpt::TAX_SERVICE, 'field' => 'slug', 'terms' => sanitize_title((string) $atts['service'])];
        }

        $args = [
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(48, (int) ($atts['count'] ?? 12))),
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ];
        if ($tax_query !== []) {
            $args['tax_query'] = count($tax_query) > 1 ? array_merge(['relation' => 'AND'], $tax_query) : $tax_query;
        }

        $query = new WP_Query($args);

        ob_start();
        $template = LPC_DIR . 'templates/jobs-grid.php';
        if (is_readable($template)) {
            include $template;
        }
        wp_reset_postdata();

        return (string) ob_get_clean();
    }

    /** Warn on the job editor: control-plane edits win, so editing here is lost on the next sync. */
    public function edit_notice(mixed $post): void
    {
        if (! ($post instanceof WP_Post) || $post->post_type !== JobCpt::POST_TYPE) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Managed by Launchpad.</strong> '
            . 'Edits made here are overwritten on the next sync — manage this job in the Launchpad portal.</p></div>';
    }
}
