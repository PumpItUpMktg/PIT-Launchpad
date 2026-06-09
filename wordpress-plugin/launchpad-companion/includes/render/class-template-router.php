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
