<?php
/**
 * Bootstrap: wires the CPT, the REST receiver, rendering, and the page-tagging metabox onto WordPress
 * hooks. Self-contained — no dependency on the Launchpad companion plugin.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('init', [Cpt::class, 'register']);
        add_action('rest_api_init', [new Rest(), 'register']);
        ( new Render() )->register();
        ( new Metabox() )->register();
    }

    /** Register the CPT so its rewrite rules exist, then flush — the /jobs/* URLs resolve after activation. */
    public static function activate(): void
    {
        Cpt::register();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
