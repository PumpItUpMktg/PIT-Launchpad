<?php
/**
 * Paints the active brand palette on the front end of a BLOCK theme, as a late `:root` override of
 * the theme's own preset variables.
 *
 * Why this exists: the brand push writes the chosen style variation into WordPress's USER global
 * styles post (StyleStore), which is the "correct" mechanism and is what the Site Editor does. On
 * some installs, though, that write does not surface in the computed global stylesheet at all — the
 * front end (and even the server-side readback) keeps emitting the theme's BASE theme.json palette,
 * so an operator picks e.g. Forest and the site stays on the base blue while every push flag reads
 * green (the write genuinely succeeded; the merge just didn't reflect it). Whatever the root of that
 * merge gap on a given host, we do not need to win the merge: the block theme's blocks all consume
 * `var(--wp--preset--color--{slug})` / `var(--wp--custom--*)`, so re-declaring those variables in a
 * `:root` block that prints AFTER the core `global-styles` inline CSS deterministically repaints the
 * whole page in the chosen colors.
 *
 * The tokens come from the `lp_brand_paint` option (written by StyleStore on every /style push), so
 * the paint survives republish and needs no theme redeploy. Values are sanitized to a conservative
 * charset (no CSS breakout). Nothing prints when the option is empty or the theme is not a block
 * theme (theme.json variables don't apply there — that's the Assets wf-* path instead).
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class BrandPaint
{
    /** The brand-carrying color roles, in theme.json palette order. */
    private const COLOR_SLUGS = ['base', 'surface', 'contrast', 'muted', 'border', 'primary', 'accent', 'on-accent', 'button', 'on-button'];

    /** custom-token key (in the stored option) => the `--wp--custom--*` variable it overrides. */
    private const CUSTOM_VARS = [
        'radius' => '--wp--custom--radius',
        'heading_weight' => '--wp--custom--heading-weight',
        'heading_letter_spacing' => '--wp--custom--heading-letter-spacing',
    ];

    public function register(): void
    {
        // Priority 100 so it prints after core's `global-styles` inline CSS (enqueued at the default
        // priority) — same `:root` specificity, later source order wins.
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 100);
    }

    public function enqueue(): void
    {
        // theme.json preset variables only exist on a block theme; the classic path is Assets' wf-*.
        if (function_exists('wp_is_block_theme') && ! wp_is_block_theme()) {
            return;
        }

        $paint = get_option(Meta::OPTION_BRAND_PAINT, []);
        $css = self::root_block(is_array($paint) ? $paint : []);
        if ($css === '') {
            return;
        }

        // Core registers the block-theme global styles under the `global-styles` handle; appending an
        // inline style to it guarantees our override prints right after it.
        wp_add_inline_style('global-styles', $css);
    }

    /**
     * The `:root { … }` override block for a stored paint map — `--wp--preset--color--{slug}` for each
     * brand color role and `--wp--custom--*` for the shape/heading tokens. Pure + static so it is
     * unit-testable without the enqueue machinery. Every value is sanitized (hex/number/keyword only);
     * an unrecognized or unsafe value is dropped, never emitted.
     *
     * @param array<string, mixed> $paint  { colors: {slug=>hex}, custom: {radius, heading_weight, heading_letter_spacing} }
     */
    public static function root_block(array $paint): string
    {
        $colors = isset($paint['colors']) && is_array($paint['colors']) ? $paint['colors'] : [];
        $custom = isset($paint['custom']) && is_array($paint['custom']) ? $paint['custom'] : [];

        $decls = '';

        foreach (self::COLOR_SLUGS as $slug) {
            $hex = isset($colors[$slug]) ? self::sanitize_color((string) $colors[$slug]) : '';
            if ($hex !== '') {
                $decls .= '--wp--preset--color--' . $slug . ':' . $hex . ';';
            }
        }

        foreach (self::CUSTOM_VARS as $key => $var) {
            $value = isset($custom[$key]) ? self::sanitize_token((string) $custom[$key]) : '';
            if ($value !== '') {
                $decls .= $var . ':' . $value . ';';
            }
        }

        return $decls === '' ? '' : ':root{' . $decls . '}';
    }

    /** A `#rgb` / `#rrggbb` hex color, lowercased; empty when the input isn't a clean hex. */
    private static function sanitize_color(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : '';
    }

    /** A conservative shape/number token (e.g. "12px", "700", "-0.01em"); `;{}<>` can never appear. */
    private static function sanitize_token(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9 .%()\-]/', '', trim($value)) ?? '';

        return substr($value, 0, 40);
    }
}
