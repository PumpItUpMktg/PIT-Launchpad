<?php
/**
 * Maps a kit slot's content_type to its operator reference: which shortcode binds
 * it, a one-line "what it renders", the wrapper/item CSS classes (styling targets,
 * matching SlotRenderer's output), and whether it's a scalar (also bindable via
 * the readable lp_slot_* mirror / native Post Custom Field tag). Pure — no WP.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class ShortcodeReference
{
    /**
     * @return array{shortcode: string, renders: string, classes: string, scalar: bool}
     */
    public static function for_type(string $content_type, string $key): array
    {
        switch ($content_type) {
            case 'heading':
            case 'short_text':
            case 'long_text':
            case 'rich_text':
            case 'text':
                return self::row("[lp_slot key=\"{$key}\"]", 'Inline text / HTML (no wrapper).', '—', true);

            case 'list':
                return self::row("[lp_repeater key=\"{$key}\"]", 'Bullet list of strings.', ".lp-repeater.lp-repeater--{$key} › .lp-repeater__item", false);

            case 'faq':
                return self::row("[lp_repeater key=\"{$key}\"]", 'FAQ list — heading/answer pairs.', ".lp-repeater--{$key} › .lp-faq (.lp-faq__q, .lp-faq__a)", false);

            case 'testimonial':
                return self::row("[lp_repeater key=\"{$key}\"]", 'Testimonials — quote + author.', '.lp-testimonial (blockquote, figcaption)', false);

            case 'stat':
                return self::row("[lp_repeater key=\"{$key}\"]", 'Stat strip — value + label.', '.lp-stat (.lp-stat__value, .lp-stat__label)', false);

            case 'cta':
                return self::row("[lp_cta key=\"{$key}\"]", 'Call-to-action anchor.', '.lp-cta', false);

            case 'image':
            case 'gallery':
                return self::row("[lp_image key=\"{$key}\"]", 'Image from the R2/CDN url.', '.lp-image', false);

            case 'map':
                return self::row("[lp_map key=\"{$key}\"]", 'Lazy-loaded map embed.', '.lp-map (iframe)', false);

            default:
                return self::row("[lp_slot key=\"{$key}\"]", 'Generic slot (shape inferred).', '—', false);
        }
    }

    /** The readable Post Custom Field mirror key for a scalar slot. */
    public static function mirror_key(string $key): string
    {
        return Meta::SLOT_PREFIX . sanitize_key($key);
    }

    /**
     * @return array{shortcode: string, renders: string, classes: string, scalar: bool}
     */
    private static function row(string $shortcode, string $renders, string $classes, bool $scalar): array
    {
        return ['shortcode' => $shortcode, 'renders' => $renders, 'classes' => $classes, 'scalar' => $scalar];
    }
}
