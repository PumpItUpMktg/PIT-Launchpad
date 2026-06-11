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
            'companion_version' => defined('LPC_VERSION') ? LPC_VERSION : null,
        ];
    }
}
