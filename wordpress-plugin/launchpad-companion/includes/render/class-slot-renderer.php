<?php
/**
 * Server-side renderer for slot values — the single render path shared by the
 * Launchpad shortcodes (Elementor-version-independent) and the classic lp/*
 * dynamic tags (where Elementor's V3 dynamic-tag system is available). It has NO
 * Elementor dependency, so the shortcodes render identically on the Atomic Editor
 * (V4), the classic editor, or no Elementor at all.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

if (! defined('ABSPATH')) {
    exit;
}

final class SlotRenderer
{
    /** A scalar/HTML text slot (sanitized; collapses to '' when not a string). */
    public static function text(mixed $value): string
    {
        return is_string($value) ? wp_kses_post($value) : '';
    }

    /**
     * A repeater slot (list, faq, testimonials, stats, cta, gallery). Infers the
     * item shape; collapses cleanly to '' when empty.
     */
    public static function repeater(string $key, mixed $items): string
    {
        if (! is_array($items) || $items === []) {
            return '';
        }

        $out = '<div class="lp-repeater lp-repeater--' . esc_attr($key) . '">';
        foreach ($items as $item) {
            $out .= self::repeater_item($item);
        }

        return $out . '</div>';
    }

    /** A call-to-action anchor from a {label, url} slot. */
    public static function cta(mixed $value): string
    {
        if (! is_array($value) || empty($value['url'])) {
            return '';
        }

        return sprintf(
            '<a class="lp-cta" href="%s">%s</a>',
            esc_url((string) $value['url']),
            esc_html((string) ($value['label'] ?? 'Learn more'))
        );
    }

    /** A lazy map embed from {embed_url} or {lat,lng}. */
    public static function map(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        if (! empty($value['embed_url'])) {
            $src = (string) $value['embed_url'];
        } elseif (isset($value['lat'], $value['lng'])) {
            $src = 'https://www.google.com/maps?q=' . rawurlencode($value['lat'] . ',' . $value['lng']) . '&output=embed';
        } else {
            return '';
        }

        return sprintf(
            '<iframe class="lp-map" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="%s"></iframe>',
            esc_url($src)
        );
    }

    /**
     * An <img> served from the R2/CDN url in the image map (never the media
     * library). Collapses to '' when the slot has no usable url.
     *
     * @param  array<string, mixed>|null  $image
     */
    public static function image(?array $image, string $alt_fallback = ''): string
    {
        $url = is_array($image) && ! empty($image['url']) ? (string) $image['url'] : '';
        if ($url === '') {
            return '';
        }

        $alt = is_array($image) && ! empty($image['alt']) ? (string) $image['alt'] : $alt_fallback;

        return sprintf(
            '<img class="lp-image" src="%s" alt="%s" loading="lazy" decoding="async" />',
            esc_url($url),
            esc_attr($alt)
        );
    }

    private static function repeater_item(mixed $item): string
    {
        if (is_string($item)) {
            return '<div class="lp-repeater__item">' . esc_html($item) . '</div>';
        }

        if (! is_array($item)) {
            return '';
        }

        if (isset($item['question'], $item['answer'])) {
            return sprintf(
                '<div class="lp-faq"><h3 class="lp-faq__q">%s</h3><div class="lp-faq__a">%s</div></div>',
                esc_html((string) $item['question']),
                wp_kses_post((string) $item['answer'])
            );
        }

        if (isset($item['quote']) || isset($item['body'])) {
            return sprintf(
                '<figure class="lp-testimonial"><blockquote>%s</blockquote><figcaption>%s</figcaption></figure>',
                esc_html((string) ($item['quote'] ?? $item['body'])),
                esc_html((string) ($item['author'] ?? ''))
            );
        }

        if (isset($item['value'], $item['label'])) {
            return sprintf(
                '<div class="lp-stat"><span class="lp-stat__value">%s</span><span class="lp-stat__label">%s</span></div>',
                esc_html((string) $item['value']),
                esc_html((string) $item['label'])
            );
        }

        if (! empty($item['url']) && isset($item['label'])) {
            return sprintf(
                '<a class="lp-repeater__cta" href="%s">%s</a>',
                esc_url((string) $item['url']),
                esc_html((string) $item['label'])
            );
        }

        if (! empty($item['url'])) {
            return sprintf(
                '<img class="lp-repeater__img" src="%s" alt="%s" loading="lazy" decoding="async" />',
                esc_url((string) $item['url']),
                esc_attr((string) ($item['alt'] ?? ''))
            );
        }

        return '';
    }
}
