<?php
/**
 * Silences competing SEO plugins (Yoast / Rank Math / AIOSEO) on managed posts,
 * so the native head emission is the single source of truth — no duplicate
 * canonical, title, or BreadcrumbList / schema.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Seo;

use Launchpad\Companion\Render\Payload;

if (! defined('ABSPATH')) {
    exit;
}

final class Suppressor
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_suppress'], 1);
    }

    public function maybe_suppress(): void
    {
        if (! is_singular()) {
            return;
        }

        $id = (int) get_queried_object_id();
        if (! Payload::is_managed($id)) {
            return;
        }

        // Yoast — drop its head output entirely on these posts.
        add_filter('wpseo_metabox_prio', '__return_false');
        add_action('wpseo_head', static fn () => remove_all_actions('wpseo_head'), 0);
        add_filter('wpseo_canonical', '__return_false');
        add_filter('wpseo_json_ld_output', '__return_false');
        add_filter('wpseo_opengraph', '__return_false');
        add_filter('wpseo_twitter', '__return_false');

        // Rank Math.
        add_filter('rank_math/frontend/disable_integration', '__return_true');
        add_action('rank_math/head', static fn () => remove_all_actions('rank_math/head'), 0);

        // All in One SEO.
        add_filter('aioseo_disable', '__return_true');
        add_filter('aioseo_canonical_url', '__return_false');
    }
}
