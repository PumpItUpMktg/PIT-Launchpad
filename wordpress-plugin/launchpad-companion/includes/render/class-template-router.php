<?php
/**
 * Assigns the page-type Elementor template to a managed page and exposes the
 * page type / kit as body classes so brand-neutral templates can target them.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class TemplateRouter
{
    public function register(): void
    {
        add_filter('body_class', [$this, 'body_class']);

        // Declare the templates this router assigns (the kit map + the Elementor
        // canvas fallback) as valid page templates for EVERY post type. WordPress
        // core re-validates a post's _wp_page_template on every wp_update_post; we
        // call it with $wp_error=true, so an unrecognized template comes back as
        // WP_Error('invalid_page_template') instead of silently degrading to
        // 'default'. The contract write must not depend on the active theme (or
        // Elementor) having registered these, so the plugin registers them itself.
        add_filter('theme_templates', [$this, 'register_templates']);
    }

    /**
     * Keep an idempotent re-push from failing on a template the active theme
     * doesn't know: advertise the canvas fallback + every mapped kit template as
     * valid. The generic `theme_templates` hook covers pages AND posts.
     *
     * @param  array<string, string>  $templates
     * @return array<string, string>
     */
    public function register_templates(array $templates): array
    {
        $templates['elementor_canvas'] = 'Elementor Canvas';

        $map = get_option(Meta::OPTION_TEMPLATES, []);
        if (is_array($map)) {
            foreach ($map as $file) {
                $file = (string) $file;
                if ($file !== '' && ! isset($templates[$file])) {
                    $templates[$file] = $file;
                }
            }
        }

        return $templates;
    }

    /**
     * Route the kit to its Elementor template. The contract's `kit` is the
     * selector (e.g. service-page, location-page); `page_type` is a fallback.
     * Unknown kits fall back to a generic canvas so a draft kit (whose §3a schema
     * isn't locked yet) renders its available slots rather than fatalling.
     */
    public static function assign(int $post_id, string $kit, string $page_type = ''): void
    {
        $map = get_option(Meta::OPTION_TEMPLATES, []);
        $map = is_array($map) ? $map : [];

        $template = '';
        foreach ([$kit, $page_type] as $key) {
            if ($key !== '' && isset($map[$key]) && $map[$key] !== '') {
                $template = (string) $map[$key];
                break;
            }
        }

        update_post_meta($post_id, '_wp_page_template', $template !== '' ? $template : 'elementor_canvas');
    }

    /**
     * @param  array<int, string>  $classes
     * @return array<int, string>
     */
    public function body_class(array $classes): array
    {
        $id = get_queried_object_id();

        if ($id <= 0) {
            return $classes;
        }

        $page_type = (string) get_post_meta($id, Meta::PAGE_TYPE, true);
        $kit = (string) get_post_meta($id, Meta::KIT, true);

        if ($page_type !== '') {
            $classes[] = 'lp-page-type-' . sanitize_html_class($page_type);
        }
        if ($kit !== '') {
            $classes[] = 'lp-kit-' . sanitize_html_class($kit);
        }

        return $classes;
    }
}
