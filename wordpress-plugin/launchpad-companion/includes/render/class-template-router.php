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
        // The native-body full-width template assign() stamps below — advertise it
        // so core's re-validation accepts it without depending on Elementor Pro.
        $templates['elementor_header_footer'] = 'Elementor Full Width';

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
     * Route managed content to its Elementor template. The contract's `kit` is the
     * selector (e.g. service-page, location-page); `page_type` is a fallback. An
     * explicit page-template FILE mapped via the `lp_templates` option still wins.
     *
     * A native-body page (per-page `_elementor_data`) renders its OWN Elementor
     * document, not a Theme Builder single template — so it gets Elementor's
     * Full-Width template (`elementor_header_footer`): theme header/footer, but no
     * theme `.page-header` entry-title (the hero H1 is the page's only H1) and
     * full-width. An explicit `lp_templates` mapping still wins over it.
     *
     * Otherwise NO page template is stamped — for kit PAGES as well as POSTS.
     * `elementor_canvas` is a full-page Elementor template that BYPASSES the Theme
     * Builder *single* template a kit renders through (its `lp_kit` display
     * condition): a page stamped with canvas renders blank of that template
     * (evidence: page 196's canvas body class). Clearing the meta leaves the
     * content on the theme default so the Theme Builder condition drives it.
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

        // An explicit operator mapping wins; else a native body gets full-width.
        if ($template === '' && get_post_meta($post_id, '_elementor_data', true) !== '') {
            $template = 'elementor_header_footer';
        }

        if ($template !== '') {
            update_post_meta($post_id, '_wp_page_template', $template);

            return;
        }

        delete_post_meta($post_id, '_wp_page_template');
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

        // The chosen structure preset → the body class that activates its token bundle
        // in wireframe.css, on managed kit pages (where the wf-block widgets live).
        // Defaults to 'trust' (also the :root default) when none has been pushed yet.
        if ($page_type !== '' || $kit !== '') {
            $preset = (string) get_option(Meta::OPTION_STRUCTURE_PRESET, '');
            $preset = in_array($preset, ['trust', 'bold', 'warm'], true) ? $preset : 'trust';
            $classes[] = 'wf-structure-' . $preset;
        }

        return $classes;
    }
}
