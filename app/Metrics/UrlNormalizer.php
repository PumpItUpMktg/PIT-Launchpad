<?php

namespace App\Metrics;

/**
 * The one URL/path normalizer for the metric spine (§ Client Dashboard v1). URL normalization is a hard
 * prerequisite: GSC, DataForSEO, the index sync, Content slugs and job paths must all key the same page the
 * same way, or per-page metrics fragment across `/foo`, `/foo/` and `/Foo`.
 *
 * `path()` is the canonical page dimension key — the same rule CoverageDashboard uses (leading slash, no
 * trailing slash, lowercased) so spine rows line up with the operator coverage view and Content paths.
 * `url()` is the canonical absolute-URL key for page_index_states (PR 3).
 */
class UrlNormalizer
{
    /** Canonical page-path key: leading slash, no trailing slash, lowercased. Accepts a full URL or a path. */
    public static function path(?string $urlOrPath): string
    {
        $value = (string) $urlOrPath;

        // A full URL → take its path; a bare path passes through parse_url unchanged.
        $path = parse_url($value, PHP_URL_PATH);
        if ($path === false || $path === null || $path === '') {
            $path = str_contains($value, '://') ? '/' : $value;
        }

        return mb_strtolower('/'.trim((string) $path, '/'));
    }

    /** Canonical absolute-URL key: lowercased scheme+host, normalized path, no query/fragment, no trailing slash. */
    public static function url(?string $url): string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return '';
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['host'])) {
            return $value; // not an absolute URL — leave it be rather than mangle it
        }

        $scheme = mb_strtolower($parts['scheme'] ?? 'https');
        $host = mb_strtolower($parts['host']);
        $path = self::path($parts['path'] ?? '/');
        $path = $path === '/' ? '' : $path; // apex → no trailing slash

        return $scheme.'://'.$host.$path;
    }
}
