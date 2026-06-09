<?php
/**
 * Upserts 301 redirects keyed on from_url, so re-pushes never duplicate.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class RedirectStore
{
    /**
     * @param  array<int, array<string, mixed>>  $redirects
     */
    public function upsert(array $redirects): int
    {
        // The engine pushes the full active set each time, so replace the whole
        // map — stale entries (no longer in the latest push) are dropped.
        $map = [];

        foreach ($redirects as $redirect) {
            $from = isset($redirect['from_url']) ? self::normalize((string) $redirect['from_url']) : '';
            $to = (string) ($redirect['to_url'] ?? '');

            if ($from === '' || $to === '') {
                continue;
            }

            $map[$from] = [
                'to_url' => $to,
                'code' => (int) ($redirect['code'] ?? 301),
            ];
        }

        update_option(Meta::OPTION_REDIRECTS, $map, false);

        return count($map);
    }

    public static function normalize(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;

        return '/' . trim($path, '/');
    }
}
