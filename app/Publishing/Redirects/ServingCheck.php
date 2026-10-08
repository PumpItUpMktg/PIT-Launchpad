<?php

namespace App\Publishing\Redirects;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is a redirect actually SERVING? A live request to the from URL must answer a 3xx whose Location
 * resolves to the to path. No WordPress read-back for a redirect exists, so this is the only honest
 * confirmation — and it is the gate every resolver passes before removing a page, because a redirect
 * written but not served would leave a 404 where Google holds a URL.
 *
 * The cache-buster query forces a CDN cache MISS, so this confirms the ORIGIN redirect rather than a
 * stale edge copy. "Verified" therefore means origin-verified; the edge may serve the old page until
 * purged, which the commands flag after --execute.
 */
final class ServingCheck
{
    public function confirms(Site $site, string $fromPath, string $toPath): bool
    {
        $domain = $site->domain_url;
        if (! is_string($domain) || trim($domain) === '') {
            return false;
        }

        $fromUrl = rtrim(trim($domain), '/').'/'.trim($fromPath, '/').'/?__lpverify='.time();
        $want = self::path($toPath);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = Http::withoutRedirecting()->timeout(10)->get($fromUrl);
            } catch (Throwable) {
                continue;
            }

            if (in_array($response->status(), [301, 302, 307, 308], true)) {
                $location = (string) $response->header('Location');
                if ($location !== '' && self::path((string) parse_url($location, PHP_URL_PATH)) === $want) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What a path answers today, un-followed: the HTTP status and, for a redirect, where it points.
     * Null status when the site cannot be reached. One request; the caller decides what the answer means.
     *
     * @return array{status: ?int, location: ?string}
     */
    public function answers(Site $site, string $path): array
    {
        $domain = $site->domain_url;
        if (! is_string($domain) || trim($domain) === '') {
            return ['status' => null, 'location' => null];
        }

        try {
            $response = Http::withoutRedirecting()->timeout(10)->get(rtrim(trim($domain), '/').'/'.trim($path, '/').'/');
            $location = (string) $response->header('Location');

            return ['status' => $response->status(), 'location' => $location !== '' ? $location : null];
        } catch (Throwable) {
            return ['status' => null, 'location' => null];
        }
    }

    /** Redirect path form: leading slash, no trailing slash, lowercased. */
    public static function path(string $value): string
    {
        $parsed = parse_url($value, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;

        return mb_strtolower('/'.trim($path, '/'));
    }
}
