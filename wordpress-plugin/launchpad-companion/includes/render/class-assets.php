<?php
/**
 * Front-end assets: the baseline design stylesheet for the lp-* render blocks.
 *
 * Ships the Path-A design layer (assets/launchpad.css) so a generated/fallback page
 * is presentable out of the box. The CSS is keyed to the Elementor Global Kit CSS
 * variables, so it carries no per-tenant values and a tenant's brand cascades into
 * it. Scoped entirely to .lp-* classes, so enqueuing site-wide is harmless.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

if (! defined('ABSPATH')) {
    exit;
}

final class Assets
{
    public const HANDLE = 'launchpad-baseline';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        wp_enqueue_style(
            self::HANDLE,
            LPC_URL . 'assets/launchpad.css',
            [],
            LPC_VERSION
        );
    }
}
