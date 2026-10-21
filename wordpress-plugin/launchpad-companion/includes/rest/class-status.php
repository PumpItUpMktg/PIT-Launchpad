<?php
/**
 * Environment introspection for the launchpad/v1/status endpoint: WordPress + PHP
 * versions, Elementor and Elementor Pro versions (null when the plugin isn't
 * active — the engine uses this to reason about render compatibility), the active
 * theme, and the companion plugin version. Read-only; no secrets.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Rest;

use Launchpad\Companion\Content\SiteProfileStore;
use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class Status
{
    /**
     * @param  string  $path  optional site-relative path (e.g. /trooper-pa/) — when given, the payload also
     *                        reports what the companion stored for that page: the post id, page type, the
     *                        state the footer swaps on, and whether a push has ever landed. Read-only.
     * @return array<string, mixed>
     */
    public static function payload(string $path = ''): array
    {
        $theme = wp_get_theme();
        $profile = SiteProfileStore::get();
        $hq = is_array($profile['hq'] ?? null) ? $profile['hq'] : [];

        return [
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'elementor_pro_version' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
            'active_theme' => [
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
            ],
            // Brand styling lives in theme.json global styles, which only apply on a block theme. When
            // this is false, a style-variation push is inert — the engine surfaces that instead of
            // reporting a phantom success.
            'is_block_theme' => function_exists('wp_is_block_theme') ? wp_is_block_theme() : false,
            // The colors WordPress currently paints (live preset variables) — ground truth for a
            // "colors didn't change" diagnosis.
            'active_colors' => self::active_colors(),
            'companion_version' => defined('LPC_VERSION') ? LPC_VERSION : null,
            // What the footer swaps on: the corporate state the stored profile carries ('' ⇒ never swaps)
            // and the Contact link. Ground truth for a "footer still shows the street address" diagnosis.
            'footer_hq' => [
                'state' => (string) ($hq['state'] ?? ''),
                'url' => (string) ($hq['url'] ?? ''),
            ],
            'page' => $path !== '' ? self::page($path) : null,
        ];
    }

    /**
     * The stored facts about one managed page, by its site-relative path.
     *
     * @return array<string, mixed>
     */
    private static function page(string $path): array
    {
        $path = '/' . trim($path, '/') . '/';
        $post_id = (int) url_to_postid(home_url($path));
        if ($post_id <= 0) {
            return ['path' => $path, 'found' => false];
        }

        return [
            'path' => $path,
            'found' => true,
            'post_id' => $post_id,
            'post_status' => (string) get_post_status($post_id),
            'content_id' => (string) get_post_meta($post_id, Meta::CONTENT_ID, true),
            'page_type' => (string) get_post_meta($post_id, Meta::PAGE_TYPE, true),
            'state' => (string) get_post_meta($post_id, Meta::STATE, true),
            'pushed' => (string) get_post_meta($post_id, Meta::LAST_PUSH, true) !== '',
        ];
    }

    /**
     * The live brand colors from the generated global-styles variables (`--wp--preset--color--{slug}`).
     *
     * @return array<string, string>
     */
    private static function active_colors(): array
    {
        if (! function_exists('wp_get_global_stylesheet')) {
            return [];
        }

        $css = (string) wp_get_global_stylesheet(['variables']);
        if ($css === '') {
            return [];
        }

        $colors = [];
        foreach (['primary', 'accent', 'button', 'contrast', 'base'] as $slug) {
            if (preg_match('/--wp--preset--color--' . $slug . ':\s*([^;]+);/', $css, $m)) {
                $colors[$slug] = trim($m[1]);
            }
        }

        return $colors;
    }
}
