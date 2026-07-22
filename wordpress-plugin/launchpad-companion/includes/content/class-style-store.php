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

        self::flush_global_styles_cache();

        // Read back what WordPress will ACTUALLY paint now (the live preset variables), so a "colors
        // still didn't change" report is decidable at the source: if this reflects the variation but the
        // browser shows the old hue, it's a page/CDN cache; if it does NOT, the write didn't take. And
        // if the site isn't running a block theme, theme.json global styles are inert — flag that too.
        return [
            'updated' => true,
            'variation' => $variation,
            'is_block_theme' => function_exists('wp_is_block_theme') ? wp_is_block_theme() : false,
            'active_colors' => self::live_preset_colors(),
        ];
    }

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
