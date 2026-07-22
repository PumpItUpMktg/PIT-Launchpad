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

if (! defined('ABSPATH')) {
    exit;
}

final class Status
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $theme = wp_get_theme();

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
