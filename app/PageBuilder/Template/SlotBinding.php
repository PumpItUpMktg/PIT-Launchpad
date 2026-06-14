<?php

namespace App\PageBuilder\Template;

use App\Enums\SlotContentType;

/**
 * Shared slot↔Elementor binding facts, so the generator (fallback) and the binder
 * (production) agree on tag names and dynamic-tag encoding, and the verifier reads
 * back what they write.
 */
final class SlotBinding
{
    /**
     * The content control a native/common Elementor widget binds its main text/media
     * through — for the BINDER, resolving which control to attach the tag to on the
     * designer's existing widget. Unmapped widget types return null (left unbound;
     * the verifier surfaces the gap rather than guessing wrong).
     */
    private const WIDGET_CONTROL = [
        'heading' => 'title',
        'theme-page-title' => 'title',
        'theme-site-title' => 'title',
        'text-editor' => 'editor',
        'theme-post-content' => 'editor',
        'button' => 'text',
        'text-path' => 'text',
        'image' => 'image',
        'shortcode' => 'shortcode',
    ];

    /** The lp/* dynamic tag that reads a slot of this content type. */
    public static function tagName(SlotContentType $type): string
    {
        return match ($type->value) {
            'image' => 'lp-image',
            'map' => 'lp-map',
            'cta' => 'lp-cta',
            'list', 'faq', 'stat', 'testimonial', 'gallery' => 'lp-repeater',
            default => 'lp-text',
        };
    }

    public static function controlForWidget(string $widgetType): ?string
    {
        return self::WIDGET_CONTROL[$widgetType] ?? null;
    }

    /**
     * The Elementor Global-Kit references a generated widget should carry, by widget
     * type — color + typography pointed at the SYSTEM global ids (`primary`,
     * `text`, `accent`), which exist in every Global Kit. So a tenant's intake-built
     * brand (its Global Kit) cascades into the generated template with no hardcoded
     * hex/px and no per-tenant styling. (The designer's own template carries its own
     * globals; the binder never touches them — this is generator-only.)
     *
     * @return array<string, string> control => "globals/…?id=…"
     */
    public static function globalsFor(string $widgetType): array
    {
        return match ($widgetType) {
            'heading' => [
                'title_color' => 'globals/colors?id=primary',
                'typography_typography' => 'globals/typography?id=primary',
            ],
            'text-editor' => [
                'text_color' => 'globals/colors?id=text',
                'typography_typography' => 'globals/typography?id=text',
            ],
            'button' => [
                'background_color' => 'globals/colors?id=accent',
            ],
            default => [],
        };
    }

    /**
     * The Elementor dynamic-tag string stored in a widget's `__dynamic__.<control>`:
     * `[elementor-tag id="…" name="lp-…" settings="<url-encoded {slot:key}>"]`.
     */
    public static function dynamicTag(string $tagName, string $slotKey, string $id): string
    {
        return sprintf(
            '[elementor-tag id="%s" name="%s" settings="%s"]',
            $id,
            $tagName,
            rawurlencode((string) json_encode(['slot' => $slotKey])),
        );
    }

    /**
     * A deterministic 7-char Elementor element/tag id from a seed.
     */
    public static function id(string $seed): string
    {
        return substr(md5($seed), 0, 7);
    }
}
