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

    /**
     * The cta slot. The control-plane resolves it to one of three shapes:
     *   - a dual CONVERSION BLOCK ({type:conversion_block, tel, call_label, phone,
     *     form_embed?}) — the service-page "Call Now" button + optional GHL form;
     *   - a NAP block ({type:nap, name, address, phone, hours?}) — contact_block;
     *   - a legacy {label, url} anchor (back-compat).
     */
    public static function cta(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $type = isset($value['type']) ? (string) $value['type'] : '';

        if ($type === 'conversion_block') {
            return self::conversion_block($value);
        }

        if ($type === 'nap') {
            return self::nap_block($value);
        }

        if (empty($value['url'])) {
            return '';
        }

        return sprintf(
            '<a class="lp-cta" href="%s">%s</a>',
            esc_url((string) $value['url']),
            esc_html((string) ($value['label'] ?? 'Learn more'))
        );
    }

    /**
     * The dual conversion block: a "Call Now" tel: button (the always-present floor,
     * derived from the location phone) + an optional embedded GHL lead form. No tel
     * → nothing; no form → call-button-only (graceful).
     *
     * @param  array<string, mixed>  $value
     */
    private static function conversion_block(array $value): string
    {
        $tel = isset($value['tel']) ? trim((string) $value['tel']) : '';
        if ($tel === '') {
            return '';
        }

        $label = (string) ($value['call_label'] ?? 'Call Now');
        $phone = trim((string) ($value['phone'] ?? ''));

        $out = '<div class="lp-conversion-block">';
        $out .= sprintf(
            '<a class="lp-conversion-block__call lp-cta" href="%s">%s</a>',
            esc_url($tel, ['tel', 'http', 'https']),
            esc_html($phone !== '' ? $label . ' ' . $phone : $label)
        );

        $embed = isset($value['form_embed']) ? (string) $value['form_embed'] : '';
        if (trim($embed) !== '') {
            $out .= '<div class="lp-conversion-block__form">' . self::embed_html($embed) . '</div>';
        }

        return $out . '</div>';
    }

    /**
     * The location NAP block (contact_block) — name / address / click-to-call phone
     * / hours, each rendered only when present.
     *
     * @param  array<string, mixed>  $value
     */
    private static function nap_block(array $value): string
    {
        $rows = '';

        if (! empty($value['name'])) {
            $rows .= '<div class="lp-nap__name">' . esc_html((string) $value['name']) . '</div>';
        }
        if (! empty($value['address'])) {
            $rows .= '<div class="lp-nap__address">' . esc_html((string) $value['address']) . '</div>';
        }
        if (! empty($value['phone'])) {
            $phone = (string) $value['phone'];
            $rows .= sprintf(
                '<div class="lp-nap__phone"><a href="%s">%s</a></div>',
                esc_url('tel:' . preg_replace('/[^0-9+]/', '', $phone), ['tel']),
                esc_html($phone)
            );
        }
        if (! empty($value['hours']) && is_array($value['hours'])) {
            $rows .= self::nap_hours($value['hours']);
        }

        return $rows === '' ? '' : '<div class="lp-nap">' . $rows . '</div>';
    }

    /**
     * The per-day hours map ({mon:{open,close}|"closed"|"24h", …}) as a simple list.
     *
     * @param  array<string, mixed>  $hours
     */
    private static function nap_hours(array $hours): string
    {
        $rows = '';
        foreach ($hours as $day => $value) {
            if (is_array($value) && isset($value['open'], $value['close'])) {
                $label = $value['open'] . '–' . $value['close'];
            } elseif ($value === '24h') {
                $label = 'Open 24 hours';
            } else {
                $label = 'Closed';
            }
            $rows .= sprintf(
                '<div class="lp-nap__hours-row"><span class="lp-nap__day">%s</span><span class="lp-nap__time">%s</span></div>',
                esc_html(ucfirst((string) $day)),
                esc_html((string) $label)
            );
        }

        return '<div class="lp-nap__hours">' . $rows . '</div>';
    }

    /**
     * Sanitize an operator-configured embed snippet (e.g. the GoHighLevel lead-form
     * iframe + loader script). This is platform-controlled config, not visitor
     * input, so the allowlist permits the iframe/script/div an embed needs.
     */
    private static function embed_html(string $embed): string
    {
        $allowed = [
            'iframe' => [
                'src' => true, 'id' => true, 'class' => true, 'style' => true, 'title' => true,
                'width' => true, 'height' => true, 'scrolling' => true, 'frameborder' => true,
                'allow' => true, 'loading' => true,
                'data-layout' => true, 'data-trigger-type' => true, 'data-form-id' => true,
                'data-height' => true, 'data-form-name' => true, 'data-deactivation-type' => true,
            ],
            'script' => ['src' => true, 'type' => true, 'async' => true, 'defer' => true],
            'div' => ['class' => true, 'id' => true, 'style' => true],
        ];

        return wp_kses($embed, $allowed);
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
