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
