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
     * @param  array<string, mixed>  $payload  { variation: "bold"|"clean"|"warm" }
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        $variation = isset($payload['variation']) ? sanitize_key((string) $payload['variation']) : '';
        if ($variation === '') {
            return ['updated' => false, 'error' => 'No style variation given.'];
        }

        // The variation ships in the active block theme as styles/{slug}.json.
        $file = get_theme_file_path("styles/{$variation}.json");
        if (! is_string($file) || ! file_exists($file)) {
            return ['updated' => false, 'error' => "Style variation '{$variation}' is not in the active theme (is the Launchpad block theme active?)."];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return ['updated' => false, 'error' => "Style variation '{$variation}' is not valid JSON."];
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

        // Drop the cached, merged theme.json so the new variation renders immediately.
        if (method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        delete_transient('global_styles_' . get_stylesheet());

        return ['updated' => true, 'variation' => $variation];
    }
}
