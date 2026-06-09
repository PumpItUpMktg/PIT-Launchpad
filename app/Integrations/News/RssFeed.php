<?php

namespace App\Integrations\News;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RSS/Atom parsing + body-shape classification, shared by the Google News
 * provider, the verify-vendors probe, and (Phase 2) the client-feed add-flow.
 *
 * The body-shape check is the guard against Google News's datacenter-IP wall: a
 * 200 holding an HTML consent page parses to zero items and must NOT look like a
 * healthy empty feed. `shape()` distinguishes real XML (with an item count) from
 * the consent/HTML interstitial.
 */
final class RssFeed
{
    /** Browser UA — datacenter IPs get a consent interstitial / softer rate limits without it. */
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Consent cookies that flip Google News off the interstitial for cookieless clients. */
    public const GOOGLE_CONSENT_COOKIE = 'CONSENT=YES+cb; SOCS=CAISNQgDEitib3FfaWRlbnRpdHlmcm9udGVuZHVpc2VydmVyXzIwMjQwMTAxLjAwX3AwGgJlbiACGgYIgIChrgY';

    /**
     * Classify a fetched body: format (xml | html | empty | unknown) + item count.
     *
     * @return array{format: string, items: int}
     */
    public static function shape(string $contentType, string $body): array
    {
        $trimmed = ltrim($body);
        $lcType = strtolower($contentType);
        $lcHead = strtolower(substr($trimmed, 0, 200));

        $looksXml = str_contains($lcType, 'xml')
            || str_starts_with($trimmed, '<?xml')
            || str_contains($lcHead, '<rss')
            || str_contains($lcHead, '<feed');

        if ($looksXml) {
            return ['format' => 'xml', 'items' => count(self::parse($body))];
        }

        if ($trimmed === '') {
            return ['format' => 'empty', 'items' => 0];
        }

        $looksHtml = str_contains($lcType, 'html')
            || str_contains($lcHead, '<!doctype html')
            || str_contains($lcHead, '<html');

        return ['format' => $looksHtml ? 'html' : 'unknown', 'items' => 0];
    }

    /**
     * Parse RSS or Atom items into a normalized list.
     *
     * @return list<array{title: string, link: string, published: string, source: string}>
     */
    public static function parse(string $body): array
    {
        try {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_use_internal_errors($previous);
        } catch (Throwable) {
            return [];
        }

        if ($xml === false) {
            return [];
        }

        $items = [];

        // RSS 2.0: channel > item
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $source = $item->source ?? null;
                $items[] = [
                    'title' => trim((string) ($item->title ?? '')),
                    'link' => trim((string) ($item->link ?? '')),
                    'published' => trim((string) ($item->pubDate ?? '')),
                    'source' => $source !== null ? trim((string) $source) : '',
                ];
            }

            return $items;
        }

        // Atom: feed > entry
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    $href = (string) $l['href'];
                    if ($href !== '' && ((string) $l['rel'] === 'alternate' || $link === '')) {
                        $link = $href;
                    }
                }
                $items[] = [
                    'title' => trim((string) ($entry->title ?? '')),
                    'link' => trim($link),
                    'published' => trim((string) ($entry->published ?? $entry->updated ?? '')),
                    'source' => trim((string) ($entry->source->title ?? '')),
                ];
            }
        }

        return $items;
    }

    /**
     * Resolve a Google News item link to the real publisher URL, not the
     * news.google.com / google.com/url redirect (source citations must point at
     * the original outlet). Best-effort: a `google.com/url?...&url=` wrapper is
     * decoded exactly; the `news.google.com/rss/articles/{id}` form is decoded by
     * pulling the http(s) URL out of the base64 article id. Falls back to the link.
     */
    public static function unwrapGoogleNewsUrl(string $link): string
    {
        $parts = parse_url($link);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $link;
        }

        // google.com/url?...&url=REAL
        if (str_contains($parts['host'], 'google.com') && isset($parts['query'])) {
            parse_str($parts['query'], $q);
            if (! empty($q['url']) && is_string($q['url'])) {
                return $q['url'];
            }
        }

        // news.google.com/rss/articles/{base64 article id}
        if (str_contains($parts['host'], 'news.google.com') && isset($parts['path']) && str_contains($parts['path'], '/articles/')) {
            $id = substr($parts['path'], strpos($parts['path'], '/articles/') + strlen('/articles/'));
            $decoded = (string) base64_decode(strtr($id, '-_', '+/'), false);
            // URL char class only, so the match stops at the surrounding protobuf
            // bytes rather than swallowing them.
            if (preg_match('|https?://[A-Za-z0-9\-._~:/?#\[\]@!$&\'()*+,;=%]+|', $decoded, $m) === 1) {
                return $m[0];
            }
        }

        return $link;
    }

    /**
     * The feed's own title (RSS <channel><title> or Atom <feed><title>) — the
     * publisher name for a single-outlet direct feed, where per-item <source> is
     * absent. Empty string when none is present or the body doesn't parse.
     */
    public static function channelTitle(string $body): string
    {
        try {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_use_internal_errors($previous);
        } catch (Throwable) {
            return '';
        }

        if ($xml === false) {
            return '';
        }

        if (isset($xml->channel->title)) {
            return trim((string) $xml->channel->title);
        }

        return isset($xml->title) ? trim((string) $xml->title) : '';
    }

    /**
     * The real outlet URL for a Google News item link, or null when the link is
     * a Google redirect we deliberately do NOT decode. Per the §6a Phase-2
     * decision there is no token decoder (no batchexecute, no follow-redirect):
     * a `google.com/url?...&url=` wrapper and the legacy base64 article id still
     * resolve exactly, but a modern opaque article token unwraps to itself — so
     * anything left on a google.com host is not a usable link and the item is
     * cited by its <source> name only.
     */
    public static function publisherUrl(string $link): ?string
    {
        $resolved = self::unwrapGoogleNewsUrl($link);
        if ($resolved === '') {
            return null;
        }

        $host = strtolower((string) (parse_url($resolved, PHP_URL_HOST) ?: ''));

        return ($host === '' || str_contains($host, 'google.com')) ? null : $resolved;
    }

    public static function parseDate(string $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        try {
            return new DateTimeImmutable($value !== '' ? $value : 'now', $utc);
        } catch (Throwable) {
            return new DateTimeImmutable('now', $utc);
        }
    }
}
