<?php
/**
 * Activates one of the block theme's theme.json STYLE VARIATIONS as the site's global styles — the
 * Gutenberg-pivot replacement for the Elementor Global Kit brand push. The control plane sends the
 * chosen variation slug (bold / clean / warm); this writes that variation's settings + styles into
 * the user global-styles post, exactly as picking it in Appearance → Editor → Styles would. Brand
 * styling lives in theme.json — there is no Global Kit here.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\BrandPaint;

if (! defined('ABSPATH')) {
    exit;
}

final class StyleStore
{
    /**
     * @param  array<string, mixed>  $payload  { variation: "bold"|"clean"|"warm" } OR
     *                                          { variation: "brand", theme_json: {settings,styles,…} }
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        $variation = isset($payload['variation']) ? sanitize_key((string) $payload['variation']) : '';

        // A per-tenant DYNAMIC variation (e.g. the logo-derived "Your brand colors") is pushed inline as
        // a full theme.json variation — there is no styles/{slug}.json file for it in the theme.
        if (isset($payload['theme_json']) && is_array($payload['theme_json'])) {
            $data = $payload['theme_json'];
        } elseif ($variation !== '') {
            // A curated variation ships in the active block theme as styles/{slug}.json.
            $file = get_theme_file_path("styles/{$variation}.json");
            if (! is_string($file) || ! file_exists($file)) {
                return ['updated' => false, 'error' => "Style variation '{$variation}' is not in the active theme (is the Launchpad block theme active?)."];
            }

            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data)) {
                return ['updated' => false, 'error' => "Style variation '{$variation}' is not valid JSON."];
            }
        } else {
            return ['updated' => false, 'error' => 'No style variation given.'];
        }

        // The user global-styles post content — the same shape the editor writes when a variation is
        // picked: the variation's settings + styles, flagged as user theme JSON.
        $content = [
            'version' => isset($data['version']) ? (int) $data['version'] : 3,
            'isGlobalStylesUserThemeJSON' => true,
            'settings' => isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : (object) [],
            'styles' => isset($data['styles']) && is_array($data['styles']) ? $data['styles'] : (object) [],
        ];

        if (! class_exists('WP_Theme_JSON_Resolver')) {
            return ['updated' => false, 'error' => 'Block theme global styles are unavailable on this WordPress version.'];
        }

        $post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if (! $post_id) {
            return ['updated' => false, 'error' => 'Could not resolve the user global-styles post.'];
        }

        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => wp_slash((string) wp_json_encode($content)),
        ], true);

        if (is_wp_error($result)) {
            return ['updated' => false, 'error' => $result->get_error_code() . ': ' . $result->get_error_message()];
        }

        // Persist the resolved palette + shape tokens so BrandPaint can re-declare them as a late
        // :root override on the front end. This is what makes the push actually paint on installs
        // where the user global-styles write above doesn't surface in WordPress's computed
        // stylesheet (the "green flag, colors stay on the base theme" failure) — the theme's blocks
        // read --wp--preset--color--* / --wp--custom--*, and BrandPaint re-declares them last.
        $paint = self::brand_paint_from($data, $variation);
        update_option(Meta::OPTION_BRAND_PAINT, $paint);

        self::flush_global_styles_cache();

        // A block theme INLINES the global-styles CSS into every page's <head>, so a full-page cache
        // (a caching plugin, or host/CDN cache) keeps serving the OLD colors even though the write took
        // and the theme.json caches are clear — the classic "colors didn't change from the dashboard,
        // but editing in wp-admin works" report (saving in the editor purges the page cache; our REST
        // write did not). Purge the well-known page caches so the next front-end request re-renders.
        $purged = self::purge_page_caches();

        // `active_colors` is what the front end will ACTUALLY paint now: BrandPaint re-declares this
        // exact palette as a late :root override, so it renders regardless of whether the user
        // global-styles write surfaced in WordPress's merged stylesheet. `merged_colors` is the raw
        // computed global stylesheet (the value the base theme.json alone would paint) — kept as a
        // diagnostic: when it differs from active_colors, this install is exactly the case where the
        // global-styles merge doesn't reflect the write and the override is doing the work. If the
        // site isn't a block theme, theme.json global styles are inert — flagged so the push isn't
        // reported as a silent success.
        return [
            'updated' => true,
            'variation' => $variation,
            'is_block_theme' => function_exists('wp_is_block_theme') ? wp_is_block_theme() : false,
            'active_colors' => $paint['colors'],
            'merged_colors' => self::live_preset_colors(),
            'page_caches_purged' => $purged,
        ];
    }

    /**
     * Extract the brand paint (color roles + shape/heading tokens) from a resolved theme.json
     * variation document — the palette's slug=>hex map plus the custom radius/heading tokens. This is
     * what {@see BrandPaint} re-declares on the front end. Sourced from the same $data the user
     * global-styles write uses, so the override always matches the intended variation.
     *
     * @param  array<string, mixed>  $data       a theme.json variation (settings.color.palette + settings.custom)
     * @param  string                $variation  the curated slug, used to fall back to a bundled palette
     * @return array{colors: array<string, string>, custom: array<string, string>}
     */
    private static function brand_paint_from(array $data, string $variation): array
    {
        $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];

        $colors = [];
        $palette = isset($settings['color']['palette']) && is_array($settings['color']['palette'])
            ? $settings['color']['palette'] : [];
        foreach ($palette as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $slug = isset($entry['slug']) && is_string($entry['slug']) ? $entry['slug'] : '';
            $color = isset($entry['color']) && is_string($entry['color']) ? trim($entry['color']) : '';
            if ($slug !== '' && $color !== '') {
                $colors[$slug] = $color;
            }
        }

        // Resilience: a bare-slug push against a STALE deployed theme (its styles/{slug}.json carries
        // no palette) yields an empty $palette — the very case that made the push paint nothing. Fall
        // back to the bundled palette for the known curated slugs so the colors land regardless of the
        // deployed theme's age or whether the control plane sent the palette inline.
        if ($colors === [] && isset(self::CURATED_PALETTES[$variation])) {
            $colors = self::CURATED_PALETTES[$variation];
        }

        $customIn = isset($settings['custom']) && is_array($settings['custom']) ? $settings['custom'] : [];
        $custom = [];
        foreach (['radius' => 'radius', 'headingWeight' => 'heading_weight', 'headingLetterSpacing' => 'heading_letter_spacing'] as $src => $dst) {
            if (isset($customIn[$src]) && (is_string($customIn[$src]) || is_numeric($customIn[$src]))) {
                $custom[$dst] = (string) $customIn[$src];
            }
        }

        return ['colors' => $colors, 'custom' => $custom];
    }

    /**
     * A bundled MIRROR of the control plane's locked StyleVariation palettes (App\Styling\
     * StyleVariation::palette(), mapped to the theme.json role slugs). Used ONLY as a fallback when a
     * push arrives as a bare slug and the deployed theme's variation file has no palette to read — so
     * the brand still paints without a control-plane or theme redeploy. The variations are locked; if
     * they ever change, update this map in lockstep with the enum.
     *
     * @var array<string, array<string, string>>
     */
    private const CURATED_PALETTES = [
        'clean' => ['base' => '#ffffff', 'surface' => '#f1f5f9', 'contrast' => '#0f172a', 'muted' => '#475569', 'border' => '#e2e8f0', 'primary' => '#123B6B', 'accent' => '#1D6FD6', 'on-accent' => '#ffffff', 'button' => '#1D6FD6', 'on-button' => '#ffffff'],
        'bold' => ['base' => '#ffffff', 'surface' => '#f5f3f2', 'contrast' => '#1a1a1a', 'muted' => '#57534e', 'border' => '#e7e5e4', 'primary' => '#111827', 'accent' => '#E4572E', 'on-accent' => '#ffffff', 'button' => '#E4572E', 'on-button' => '#ffffff'],
        'warm' => ['base' => '#fffdf8', 'surface' => '#f6efe3', 'contrast' => '#2b2620', 'muted' => '#6b5d4f', 'border' => '#e7dcc9', 'primary' => '#7C4A24', 'accent' => '#E08D3C', 'on-accent' => '#ffffff', 'button' => '#C9702A', 'on-button' => '#ffffff'],
        'fresh' => ['base' => '#ffffff', 'surface' => '#eefaf6', 'contrast' => '#0f2a26', 'muted' => '#4b6b64', 'border' => '#d5eae4', 'primary' => '#0B5D52', 'accent' => '#14B8A6', 'on-accent' => '#ffffff', 'button' => '#0EA5A0', 'on-button' => '#ffffff'],
        'premium' => ['base' => '#0f1620', 'surface' => '#17202e', 'contrast' => '#e8edf4', 'muted' => '#9aa7bc', 'border' => '#263241', 'primary' => '#D4AF37', 'accent' => '#E7C55A', 'on-accent' => '#1a1206', 'button' => '#C9A227', 'on-button' => '#14100a'],
        'forest' => ['base' => '#ffffff', 'surface' => '#f0f4ef', 'contrast' => '#1c2b22', 'muted' => '#52645a', 'border' => '#dce6da', 'primary' => '#1E5233', 'accent' => '#4C9A2A', 'on-accent' => '#ffffff', 'button' => '#3E7D2B', 'on-button' => '#ffffff'],
        'slate' => ['base' => '#ffffff', 'surface' => '#f2f4f7', 'contrast' => '#1f2937', 'muted' => '#64748b', 'border' => '#e2e8f0', 'primary' => '#334155', 'accent' => '#F97316', 'on-accent' => '#ffffff', 'button' => '#F97316', 'on-button' => '#ffffff'],
        'coastal' => ['base' => '#fbfeff', 'surface' => '#eaf4f7', 'contrast' => '#14343d', 'muted' => '#4e6c74', 'border' => '#d3e6ea', 'primary' => '#226C82', 'accent' => '#E0A458', 'on-accent' => '#14343d', 'button' => '#226C82', 'on-button' => '#ffffff'],
        'crimson' => ['base' => '#ffffff', 'surface' => '#f7f2f2', 'contrast' => '#1a1414', 'muted' => '#6b5555', 'border' => '#ecdcdc', 'primary' => '#8C1D2C', 'accent' => '#C8102E', 'on-accent' => '#ffffff', 'button' => '#C8102E', 'on-button' => '#ffffff'],
        'midnight' => ['base' => '#0b1220', 'surface' => '#131c2e', 'contrast' => '#eaf1fb', 'muted' => '#93a4be', 'border' => '#22304a', 'primary' => '#4D97E8', 'accent' => '#38BDF8', 'on-accent' => '#06131f', 'button' => '#2F86E0', 'on-button' => '#ffffff'],
    ];

    /**
     * Thoroughly drop every cached copy of the merged theme.json so the new variation renders on the
     * next front-end request — the partial clear (static caches only) was the classic cause of a push
     * that "succeeded" while the site kept serving the old global styles from a persistent object cache.
     */
    private static function flush_global_styles_cache(): void
    {
        // The canonical core invalidation (clears the static resolver caches, the `theme_json` object
        // cache group, and the theme-JSON transients) — present since WP 6.2.
        if (function_exists('wp_clean_theme_json_cache')) {
            wp_clean_theme_json_cache();
        }
        if (method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        // Belt and suspenders for older cores / stray transients.
        delete_transient('global_styles_' . get_stylesheet());
        delete_transient('gutenberg_global_styles_' . get_stylesheet());
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wp_get_global_stylesheet', 'theme_json');
        }
    }

    /**
     * Purge the common full-page caches so a brand push actually re-renders on the front end. A block
     * theme inlines its global-styles CSS per page, so a cached page keeps the old colors until its
     * cache is cleared — and unlike a wp-admin save, our REST write doesn't run the editor's purge path.
     * Every purger is fired defensively (a `do_action` with no listener is a harmless no-op; function
     * calls are `function_exists`-guarded), so this is safe on a site with no cache plugin at all.
     *
     * Returns the names of the cache layers we asked to purge — surfaced in the readback so a lingering
     * stale page can be pinned on a cache we DIDN'T cover (e.g. an external CDN) rather than the write.
     *
     * @return list<string>
     */
    private static function purge_page_caches(): array
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

        return $purged;
    }

    /**
     * The colors WordPress will actually render right now, read from the generated global-styles
     * variables (`--wp--preset--color--{slug}`) — version-stable and origin-merged, so it reflects the
     * user global styles we just wrote. Returns a slug => hex map for the brand-carrying roles; empty
     * when global styles are unavailable (e.g. a classic theme).
     *
     * @return array<string, string>
     */
    private static function live_preset_colors(): array
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
