<?php
/**
 * Stores the site PROFILE the control plane pushes for the universal header/footer chrome — the
 * per-tenant NAP + navigation that a block-theme template part cannot express statically (phone,
 * emergency flag, hours, service/area/company links, brand name + tagline). Written to a single option
 * by the launchpad/v1/site-profile endpoint and read by {@see \Launchpad\Companion\Render\SiteChrome}.
 *
 * No page content here — this is chrome data, kept out of post_content so it stays site-wide and is
 * rendered once by the header/footer template parts.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class SiteProfileStore
{
    /**
     * Upsert the pushed profile. Every field is sanitized to a conservative shape so a bad push can
     * never inject markup into the site-wide chrome.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        update_option(Meta::OPTION_SITE_PROFILE, self::sanitize($payload), false);

        return ['updated' => true];
    }

    /**
     * The stored profile (empty array when nothing has been pushed yet — the chrome degrades to
     * WordPress' own site title with no phone/nav).
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $profile = get_option(Meta::OPTION_SITE_PROFILE, []);

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private static function sanitize(array $p): array
    {
        return [
            'brand_name' => sanitize_text_field((string) ($p['brand_name'] ?? '')),
            'logo_url' => isset($p['logo_url']) ? (string) esc_url_raw((string) $p['logo_url']) : '',
            'tagline' => sanitize_text_field((string) ($p['tagline'] ?? '')),
            'phone' => sanitize_text_field((string) ($p['phone'] ?? '')),
            'phone_tel' => self::tel((string) ($p['phone_tel'] ?? '')),
            'emergency' => ! empty($p['emergency']),
            'address' => sanitize_text_field((string) ($p['address'] ?? '')),
            'hours' => sanitize_text_field((string) ($p['hours'] ?? '')),
            'legal' => sanitize_text_field((string) ($p['legal'] ?? '')),
            'nav' => self::links($p['nav'] ?? []),
            'services' => self::links($p['services'] ?? []),
            'areas' => self::links($p['areas'] ?? []),
            'company' => self::links($p['company'] ?? []),
        ];
    }

    /**
     * Normalize a list of { label|name, url } links, dropping empties. URLs are kept only when they
     * pass esc_url_raw; a null/blank URL yields a plain (unlinked) label.
     *
     * @param  mixed  $raw
     * @return list<array{label: string, url: string}>
     */
    private static function links(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = sanitize_text_field((string) ($item['label'] ?? $item['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $url = isset($item['url']) ? esc_url_raw((string) $item['url']) : '';
            $out[] = ['label' => $label, 'url' => is_string($url) ? $url : ''];
        }

        return $out;
    }

    /** A `tel:` value reduced to a safe dialing charset. */
    private static function tel(string $value): string
    {
        $value = preg_replace('/^tel:/i', '', trim($value)) ?? '';
        $digits = preg_replace('/[^0-9+]/', '', $value) ?? '';

        return $digits !== '' ? 'tel:' . $digits : '';
    }
}
