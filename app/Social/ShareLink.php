<?php

namespace App\Social;

use App\Enums\SocialNetwork;

/**
 * UTM-tags a shared link so GA4 attributes the session to the network and the share, honestly:
 * `utm_source=<network>`, `utm_medium=social`, `utm_campaign=<subject slug>`, `utm_content=<trigger>`.
 * Without this every social click reads as "direct" and the Shares panel runs on faith.
 */
final class ShareLink
{
    public static function tag(string $url, SocialNetwork $network, string $campaign, string $content): string
    {
        if (! (bool) config('launchpad.social.utm', true)) {
            return $url;
        }
        $params = http_build_query([
            'utm_source' => $network->value,
            'utm_medium' => (string) config('launchpad.social.utm_medium', 'social'),
            'utm_campaign' => self::slug($campaign),
            'utm_content' => self::slug($content),
        ]);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.$params;
    }

    private static function slug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $value), '-'));

        return $slug === '' ? 'share' : mb_substr($slug, 0, 80);
    }
}
