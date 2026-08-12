<?php
/**
 * The two render paths — the `[pig_jobs]` shortcode and the server-rendered `launchpad/pig-jobs` block —
 * plus the single-job Leaflet map. With no city/service argument, the shortcode auto-scopes to the current
 * page's tagged city/service (set via the {@see Metabox}), since a standalone site has no Location/Service
 * records to infer from. The map draws a 1-mile radius circle over the JITTERED point (the true address is
 * never sent), keyless via Leaflet + OpenStreetMap.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

use WP_Query;

if (! defined('ABSPATH')) {
    exit;
}

final class Render
{
    private const LEAFLET_VERSION = '1.9.4';

    public function register(): void
    {
        add_shortcode('pig_jobs', [$this, 'shortcode']);
        add_action('init', [$this, 'register_block']);
        add_filter('the_content', [$this, 'append_single_map']);
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
        $atts = shortcode_atts(['city' => '', 'service' => '', 'count' => 12], is_array($atts) ? $atts : [], 'pig_jobs');

        return $this->render($atts);
    }

    /**
     * @param  array<string, mixed>  $atts
     */
    private function render(array $atts): string
    {
        [$city, $service] = $this->resolve_scope((string) ($atts['city'] ?? ''), (string) ($atts['service'] ?? ''));

        $tax_query = [];
        if ($city !== '') {
            $tax_query[] = ['taxonomy' => Cpt::TAX_CITY, 'field' => 'slug', 'terms' => sanitize_title($city)];
        }
        if ($service !== '') {
            $tax_query[] = ['taxonomy' => Cpt::TAX_SERVICE, 'field' => 'slug', 'terms' => sanitize_title($service)];
        }

        $args = [
            'post_type' => Cpt::POST_TYPE,
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
        $template = PIGJOBS_DIR . 'templates/jobs-grid.php';
        if (is_readable($template)) {
            include $template;
        }
        wp_reset_postdata();

        return (string) ob_get_clean();
    }

    /**
     * Fall back to the current page's tagged city/service when the shortcode gives none.
     *
     * @return array{0: string, 1: string}
     */
    private function resolve_scope(string $city, string $service): array
    {
        if (($city !== '' || $service !== '') || ! is_singular()) {
            return [$city, $service];
        }

        $post_id = get_queried_object_id();
        if ($city === '') {
            $city = (string) get_post_meta($post_id, Metabox::PAGE_CITY, true);
        }
        if ($service === '') {
            $service = (string) get_post_meta($post_id, Metabox::PAGE_SERVICE, true);
        }

        return [$city, $service];
    }

    /** Append the radius-circle map to a single job's content. */
    public function append_single_map(string $content): string
    {
        if (! is_singular(Cpt::POST_TYPE) || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        $data = get_post_meta(get_the_ID(), Cpt::JOB_DATA, true);
        $location = is_array($data) && isset($data['location']) && is_array($data['location']) ? $data['location'] : [];
        $lat = isset($location['lat']) ? (float) $location['lat'] : null;
        $lng = isset($location['lng']) ? (float) $location['lng'] : null;
        if ($lat === null || $lng === null) {
            return $content;
        }

        $this->enqueue_leaflet();
        $id = 'pig-job-map-' . (int) get_the_ID();

        // Radius (1 mile) is deliberately larger than the 0.5-mile jitter, so the true address always sits
        // comfortably inside the circle rather than near its edge.
        $script = sprintf(
            '<script>window.addEventListener("load",function(){if(!window.L)return;var m=L.map(%1$s,{scrollWheelZoom:false}).setView([%2$F,%3$F],13);L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{attribution:"© OpenStreetMap"}).addTo(m);L.circle([%2$F,%3$F],{radius:1609,color:"#0284c7",weight:2,fillOpacity:.12}).addTo(m);});</script>',
            wp_json_encode($id),
            $lat,
            $lng
        );

        return $content . sprintf('<div id="%s" class="pig-job-map" style="height:320px;border-radius:12px;overflow:hidden;margin:1.5rem 0;"></div>', esc_attr($id)) . $script;
    }

    private function enqueue_leaflet(): void
    {
        // Scaffold: Leaflet from the CDN (keyless). For production, bundle it locally so it survives a CSP
        // and offline caching.
        wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@' . self::LEAFLET_VERSION . '/dist/leaflet.css', [], self::LEAFLET_VERSION);
        wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@' . self::LEAFLET_VERSION . '/dist/leaflet.js', [], self::LEAFLET_VERSION, true);
    }
}
