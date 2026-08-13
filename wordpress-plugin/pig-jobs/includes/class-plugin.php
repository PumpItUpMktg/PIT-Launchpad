<?php
/**
 * Bootstrap: wires the CPT, the REST receiver, rendering, and the page-tagging metabox onto WordPress
 * hooks. Self-contained — no dependency on the Launchpad companion plugin.
 *
 * Coexistence: if the FULL companion plugin is also active on the site, this standalone plugin stands down
 * entirely — the companion owns the `pig_job` CPT, the `launchpad/v1/job` route, and the render paths.
 * Running both otherwise double-registers the same CPT and REST route with DIFFERENT permission gates
 * (companion: `lp_manage_content`; this plugin historically: `edit_posts`), which can 403 a valid service-
 * user Application Password on `/job`. Standing down keeps a single, consistent receiver.
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

    /** True when the full Launchpad companion plugin is present — it owns Job Capture, so we defer to it. */
    public static function companion_active(): bool
    {
        return defined('LPC_VERSION') || class_exists('Launchpad\\Companion\\Content\\JobCpt');
    }

    public function boot(): void
    {
        // Defer to the companion when both are installed — it registers the same CPT + route + render.
        if (self::companion_active()) {
            return;
        }

        add_action('init', [Cpt::class, 'register']);
        add_action('rest_api_init', [new Rest(), 'register']);
        ( new Render() )->register();
        ( new Metabox() )->register();

        // Self-heal the service capability every request (init, so REST too) — repairs a migrated/cloned
        // site whose service role carried over without the cap, which would otherwise 403 a valid app
        // password. Also run the one-time install repair after a version change.
        add_action('init', [ServiceUser::class, 'ensure_caps']);
        add_action('init', [self::class, 'maybe_upgrade']);
    }

    /** Run install() once after a version change (a plugin update never fires the activation hook). */
    public static function maybe_upgrade(): void
    {
        if (get_option('pigjobs_version') !== PIGJOBS_VERSION) {
            ServiceUser::install();
            update_option('pigjobs_version', PIGJOBS_VERSION);
        }
    }

    /**
     * Register the CPT so its rewrite rules exist, then flush — the /jobs/* URLs resolve after activation.
     * On a standalone site also provision the service user/role; when the companion is present it owns all
     * of this, so we only flush.
     */
    public static function activate(): void
    {
        if (! self::companion_active()) {
            ServiceUser::install();
            update_option('pigjobs_version', PIGJOBS_VERSION);
            Cpt::register();
        }

        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
