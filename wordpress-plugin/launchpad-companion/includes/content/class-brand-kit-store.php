<?php
/**
 * Writes a control-plane brand kit into this site's Elementor Global Kit — the
 * WP-side of C5 (brand intake → Elementor global kit). The control plane sends
 * the tenant's intake palette + typography; this sets the Global Kit's SYSTEM
 * colors (primary / secondary / text / accent) and SYSTEM typography by their
 * stable `_id`s, which is exactly what the generated/bound templates reference via
 * `__globals__` (globals/colors?id=primary, globals/typography?id=primary, …). So
 * provisioning paints the client's brand instead of the theme/Elementor defaults.
 *
 * Mechanism (proven on the prior Launchpad plugin): the active kit's settings live
 * in the `_elementor_page_settings` post meta as `system_colors` / `system_typography`
 * arrays of `{_id, title, …}`. We update entries by `_id` in place (preserving the
 * rest of the kit), append any missing slot, write back, and clear Elementor's CSS
 * cache so the brand renders without a manual regenerate. Idempotent: a re-push
 * overwrites the same slots, never duplicating them.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class BrandKitStore
{
    private const VALID_STRUCTURES = ['trust', 'bold', 'warm'];

    /** The Elementor system slots, in kit order, with their default titles. */
    private const SLOTS = [
        'primary' => 'Primary',
        'secondary' => 'Secondary',
        'text' => 'Text',
        'accent' => 'Accent',
    ];

    /**
     * Apply the brand kit to the active Elementor Global Kit.
     *
     * Payload: {
     *   colors:    { primary?, secondary?, text?, accent? : "#hex" },
     *   fonts:     { primary?, secondary?, text?, accent? : { family, weight? } },
     *   wf_tokens: { "--wf-color-primary"?: "#hex", "--wf-font-heading"?: "Inter", … },
     *   structure: "trust" | "bold" | "warm"
     * }
     *
     * The wf_tokens + structure feed the NATIVE wf-* pages (the base wf-* stylesheet's
     * :root block + body.wf-structure-{slug}); they are stored as options regardless of
     * whether an Elementor Global Kit exists, so native pages get brand even on a site
     * with no active kit. The colors/fonts feed the Elementor Global Kit (legacy/
     * dynamic-tag path).
     *
     * @param  array<string, mixed>  $payload
     * @return array{updated: bool, kit_id: int, colors_set: int, fonts_set: int, wf_tokens_set: int, structure_set: bool, error?: string}
     */
    public function install(array $payload): array
    {
        // Native wf-* layer first — independent of the Elementor Global Kit.
        $wf_tokens_set = $this->store_wf_tokens($payload['wf_tokens'] ?? null);
        $structure_set = $this->store_structure($payload['structure'] ?? null);

        $result = $this->install_global_kit($payload);
        $result['wf_tokens_set'] = $wf_tokens_set;
        $result['structure_set'] = $structure_set;

        // The native layer alone is a successful push even when there is no Elementor kit.
        if (($wf_tokens_set > 0 || $structure_set) && ! $result['updated']) {
            unset($result['error']);
            $result['updated'] = true;
        }

        return $result;
    }

    /**
     * Store the per-tenant `--wf-*` brand tokens (lp_brand_tokens). Only valid
     * `--wf-*` names with string values are kept. Returns how many were stored.
     *
     * @param  mixed  $tokens
     */
    private function store_wf_tokens($tokens): int
    {
        if (! is_array($tokens)) {
            return 0;
        }

        $clean = [];
        foreach ($tokens as $name => $value) {
            if (is_string($name) && preg_match('/^--wf-[a-z0-9-]+$/', $name) && (is_string($value) || is_numeric($value))) {
                $clean[$name] = (string) $value;
            }
        }

        if ($clean === []) {
            return 0;
        }

        update_option(Meta::OPTION_BRAND_TOKENS, $clean);

        return count($clean);
    }

    /**
     * Store the chosen structure preset (lp_structure_preset). Ignores anything not
     * in the known set.
     *
     * @param  mixed  $structure
     */
    private function store_structure($structure): bool
    {
        if (! is_string($structure) || ! in_array($structure, self::VALID_STRUCTURES, true)) {
            return false;
        }

        update_option(Meta::OPTION_STRUCTURE_PRESET, $structure);

        return true;
    }

    /**
     * The Elementor Global Kit write (system colors/typography). Unchanged behavior
     * from the original install(); split out so the native wf-* layer can be stored
     * even when there is no active kit.
     *
     * @param  array<string, mixed>  $payload
     * @return array{updated: bool, kit_id: int, colors_set: int, fonts_set: int, error?: string}
     */
    private function install_global_kit(array $payload): array
    {
        $kit_id = $this->active_kit_id();
        if ($kit_id <= 0) {
            return [
                'updated' => false,
                'kit_id' => 0,
                'colors_set' => 0,
                'fonts_set' => 0,
                'error' => 'No active Elementor Global Kit; brand not applied.',
            ];
        }

        $colors = is_array($payload['colors'] ?? null) ? $payload['colors'] : [];
        $fonts = is_array($payload['fonts'] ?? null) ? $payload['fonts'] : [];

        if ($colors === [] && $fonts === []) {
            return [
                'updated' => false,
                'kit_id' => $kit_id,
                'colors_set' => 0,
                'fonts_set' => 0,
                'error' => 'Empty brand kit (no colors or fonts to apply).',
            ];
        }

        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (! is_array($settings)) {
            $settings = [];
        }

        $colors_set = $this->apply_colors($settings, $colors);
        $fonts_set = $this->apply_fonts($settings, $fonts);

        update_post_meta($kit_id, '_elementor_page_settings', $settings);
        $this->flush_cache();

        return [
            'updated' => true,
            'kit_id' => $kit_id,
            'colors_set' => $colors_set,
            'fonts_set' => $fonts_set,
        ];
    }

    /**
     * Set the kit's system colors by _id, in place. Returns how many were applied.
     *
     * @param  array<string, mixed>  $settings  (by ref)
     * @param  array<string, mixed>  $colors
     */
    private function apply_colors(array &$settings, array $colors): int
    {
        $existing = isset($settings['system_colors']) && is_array($settings['system_colors'])
            ? $settings['system_colors'] : [];
        $index = $this->index_by_id($existing);

        $count = 0;
        foreach (self::SLOTS as $id => $title) {
            if (empty($colors[$id]) || ! is_string($colors[$id])) {
                continue;
            }
            $color = $this->sanitize_color($colors[$id]);
            if ($color === '') {
                continue;
            }

            if (isset($index[$id])) {
                $existing[$index[$id]]['color'] = $color;
            } else {
                $existing[] = ['_id' => $id, 'title' => $title, 'color' => $color];
            }
            $count++;
        }

        $settings['system_colors'] = array_values($existing);

        return $count;
    }

    /**
     * Set the kit's system typography by _id, in place. Returns how many applied.
     *
     * @param  array<string, mixed>  $settings  (by ref)
     * @param  array<string, mixed>  $fonts
     */
    private function apply_fonts(array &$settings, array $fonts): int
    {
        $existing = isset($settings['system_typography']) && is_array($settings['system_typography'])
            ? $settings['system_typography'] : [];
        $index = $this->index_by_id($existing);

        $count = 0;
        foreach (self::SLOTS as $id => $title) {
            $font = $fonts[$id] ?? null;
            $family = is_array($font) && ! empty($font['family']) ? (string) $font['family'] : '';
            if ($family === '') {
                continue;
            }

            $entry = isset($index[$id]) && is_array($existing[$index[$id]])
                ? $existing[$index[$id]]
                : ['_id' => $id, 'title' => $title];

            $entry['typography_typography'] = 'custom';
            $entry['typography_font_family'] = $family;
            if (is_array($font) && ! empty($font['weight'])) {
                $entry['typography_font_weight'] = (string) $font['weight'];
            }

            if (isset($index[$id])) {
                $existing[$index[$id]] = $entry;
            } else {
                $existing[] = $entry;
            }
            $count++;
        }

        $settings['system_typography'] = array_values($existing);

        return $count;
    }

    /**
     * Map `_id` => position for an existing system_colors / system_typography list.
     *
     * @param  array<int, mixed>  $list
     * @return array<string, int>
     */
    private function index_by_id(array $list): array
    {
        $index = [];
        foreach ($list as $pos => $entry) {
            if (is_array($entry) && isset($entry['_id']) && is_string($entry['_id'])) {
                $index[$entry['_id']] = $pos;
            }
        }

        return $index;
    }

    /**
     * A hex color is normalized; any other CSS color string (rgb/hsl/var) is passed
     * through trimmed — the kit accepts those too, and we never want to silently
     * drop a valid brand value.
     */
    private function sanitize_color(string $color): string
    {
        $color = trim($color);
        if ($color === '') {
            return '';
        }

        $hex = sanitize_hex_color($color);

        return is_string($hex) && $hex !== '' ? $hex : $color;
    }

    /**
     * The active Global Kit id: the option Elementor stores, falling back to the
     * kits manager (which lazily creates one) when Elementor's API is present.
     */
    private function active_kit_id(): int
    {
        $id = (int) get_option('elementor_active_kit');
        if ($id > 0 && get_post_status($id) !== false) {
            return $id;
        }

        if (class_exists('\Elementor\Plugin')) {
            $elementor = \Elementor\Plugin::$instance;
            if (isset($elementor->kits_manager) && is_object($elementor->kits_manager)
                && method_exists($elementor->kits_manager, 'get_active_id')) {
                $managed = (int) $elementor->kits_manager->get_active_id();
                if ($managed > 0) {
                    return $managed;
                }
            }
        }

        return 0;
    }

    private function flush_cache(): void
    {
        if (! class_exists('\Elementor\Plugin')) {
            return;
        }

        $elementor = \Elementor\Plugin::$instance;
        if (isset($elementor->files_manager) && is_object($elementor->files_manager)
            && method_exists($elementor->files_manager, 'clear_cache')) {
            $elementor->files_manager->clear_cache();
        }
    }
}
