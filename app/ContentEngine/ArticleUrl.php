<?php

namespace App\ContentEngine;

use App\Metrics\UrlNormalizer;

/**
 * Canonical dedup key for an EXTERNAL article URL (a news item's link) — the §6a URL-dedup layer. Distinct
 * from {@see UrlNormalizer}, which keys the platform's OWN published pages: this one keys
 * third-party publisher URLs so the SAME article ingested twice (different `external_id`, tracking params,
 * http/https, or a www variance) collapses to one candidate.
 *
 * The key is scheme-agnostic (http/https never split a story), host lower-cased with a leading `www.`
 * stripped, path lower-cased with no trailing slash, and query + fragment dropped (utm_*, fbclid, gclid …
 * are noise on a news link). Returns null when there is no usable absolute URL — Google-News-style feeds
 * leave the link null (a redirect token is not a real URL), so those items dedup on `external_id` alone
 * and are never keyed here.
 */
final class ArticleUrl
{
    public static function key(?string $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return null;
        }

        // Tolerate a scheme-less link (e.g. "example.com/x") so it still parses to a host.
        $parts = parse_url(str_contains($value, '://') ? $value : 'https://'.$value);
        if ($parts === false || empty($parts['host'])) {
            return null; // not a usable absolute URL (a redirect token, a mailto:, garbage) → no URL key
        }

        $host = preg_replace('/^www\./', '', mb_strtolower((string) $parts['host']));
        $path = mb_strtolower(rtrim((string) ($parts['path'] ?? ''), '/'));

        return $host.$path;
    }
}
