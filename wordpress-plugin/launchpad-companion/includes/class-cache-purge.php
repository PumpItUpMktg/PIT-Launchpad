<?php
/**
 * Purge the common full-page caches after a write that WordPress's own editor path would have purged
 * but a REST write does not — a brand push (the theme inlines global styles) and a redirect push (a
 * host's FastCGI cache keeps serving the OLD 301 for a path after the rule changed). Every purger is
 * fired defensively (`has_action` / `function_exists`-guarded; a `do_action` with no listener is a
 * no-op), so this is safe on a site with no cache plugin at all. Returns the layers asked to purge, so
 * a lingering stale page can be pinned on a cache we did NOT cover (e.g. an external CDN).
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

if (! defined('ABSPATH')) {
    exit;
}

final class CachePurge
{
    /**
     * @return list<string>
     */
    public static function page_caches(): array
    {
        $purged = [];

        // LiteSpeed Cache.
        if (has_action('litespeed_purge_all') || defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
            $purged[] = 'litespeed';
        }
        // WP Rocket.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $purged[] = 'wp-rocket';
        }
        // W3 Total Cache.
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $purged[] = 'w3-total-cache';
        }
        // WP Super Cache.
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $purged[] = 'wp-super-cache';
        }
        // WP Fastest Cache.
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
            $purged[] = 'wp-fastest-cache';
        } elseif (has_action('wpfc_clear_all_cache')) {
            do_action('wpfc_clear_all_cache', true);
            $purged[] = 'wp-fastest-cache';
        }
        // Cache Enabler.
        if (has_action('cache_enabler_clear_complete_cache')) {
            do_action('cache_enabler_clear_complete_cache');
            $purged[] = 'cache-enabler';
        }
        // SiteGround SG Optimizer.
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $purged[] = 'sg-optimizer';
        } elseif (has_action('siteground_optimizer_flush_cache')) {
            do_action('siteground_optimizer_flush_cache');
            $purged[] = 'sg-optimizer';
        }
        // Breeze (Cloudways).
        if (has_action('breeze_clear_all_cache')) {
            do_action('breeze_clear_all_cache');
            $purged[] = 'breeze';
        }
        // Autoptimize (CSS/page cache).
        if (has_action('autoptimize_flush_pagecache')) {
            do_action('autoptimize_flush_pagecache', '');
            $purged[] = 'autoptimize';
        } elseif (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            \autoptimizeCache::clearall();
            $purged[] = 'autoptimize';
        }
        // Kinsta / other hosts commonly listen on this generic signal.
        if (has_action('kinsta_cache_purge_all')) {
            do_action('kinsta_cache_purge_all');
            $purged[] = 'kinsta';
        }

        // Nginx FastCGI caches (Nginx Helper, and the host-level caches that listen on its signal).
        if (has_action('rt_nginx_helper_purge_all')) {
            do_action('rt_nginx_helper_purge_all');
            $purged[] = 'nginx-helper';
        }
        // FlyWP helper (fly-fastcgi-cache) — whichever purge signal the helper listens on.
        foreach (['flywp_purge_cache', 'flywp_cache_purge', 'fly_cache_purge'] as $hook) {
            if (has_action($hook)) {
                do_action($hook);
                $purged[] = 'flywp';
                break;
            }
        }
        // A host-specific snippet can listen here (mu-plugin) when its cache has no public API.
        if (has_action('launchpad_purge_page_cache')) {
            do_action('launchpad_purge_page_cache');
            $purged[] = 'custom';
        }

        return array_values(array_unique($purged));
    }
}
