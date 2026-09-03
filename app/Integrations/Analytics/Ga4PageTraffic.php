<?php

namespace App\Integrations\Analytics;

use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * The card-facing GA4 traffic adapter — bridges the shared platform Google grant onto the Live
 * boards' {@see PageTrafficProvider} contract (`connected` / `sessions`), the sibling of the GSC
 * bridge. A tenant is "connected" when the one grant is live AND this Site has a GA4 property picked
 * ({@see GoogleAccount} + Site.ga4_property, both from PR-A); a card then shows real
 * sessions, otherwise the honest "Connect GA4" / "collecting" prompt — never a fabricated zero.
 *
 * `sessions` runs ONE GA4 Data API report per page (a pagePath-equals filter, `sessions` metric →
 * the totals row), cached per (property × path × window) so a board render doesn't fan out into a
 * GA4 call per card. A transient API error or a page with no rows yet resolves to null (the
 * "collecting" cell); a real zero-session row returns 0.
 */
class Ga4PageTraffic implements PageTrafficProvider
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly int $cacheTtl = 21600,
    ) {}

    public function connected(Site $site): bool
    {
        $account = $this->connections->account();

        return $account !== null
            && ! $account->needsReconnect()
            && is_string($site->ga4_property)
            && $site->ga4_property !== '';
    }

    public function sessions(Site $site, string $path, int $days = 28): ?int
    {
        if (! $this->connected($site)) {
            return null;
        }

        $property = (string) $site->ga4_property;
        $pagePath = '/'.ltrim($path, '/');
        $key = $this->sessionsKey($property, $pagePath, $days);

        // Cache the count (and the no-data sentinel) so repeated card renders don't re-hit GA4.
        $result = $this->cache->remember($key, $this->cacheTtl, function () use ($property, $pagePath, $days): array {
            $sessions = $this->fetchSessions($property, $pagePath, $days);

            return $sessions === null ? ['none' => true] : ['sessions' => $sessions];
        });

        return $this->sessionsFromCache($result);
    }

    public function sessionsCached(Site $site, string $path, int $days = 28): ?int
    {
        if (! $this->connected($site)) {
            return null;
        }

        // Cache-only: return the warmed count if present, never fetch. A miss (null) is indistinguishable
        // from a warmed no-data sentinel by design — both render as pending, never a live GA4 call.
        $pagePath = '/'.ltrim($path, '/');

        return $this->sessionsFromCache($this->cache->get($this->sessionsKey((string) $site->ga4_property, $pagePath, $days)));
    }

    public function refresh(Site $site, string $path, int $days = 28): ?int
    {
        if (! $this->connected($site)) {
            return null;
        }

        $property = (string) $site->ga4_property;
        $pagePath = '/'.ltrim($path, '/');

        // ALWAYS fetch and overwrite (put, not remember) so the weekly warm re-pulls even while the prior
        // long-TTL entry is still live. The TTL spans a warm interval so the render's cache-only read stays
        // fresh between passes; the no-data sentinel is cached too so a page with no rows renders "pending"
        // (not "Refreshing…") until it earns traffic.
        $sessions = $this->fetchSessions($property, $pagePath, $days);
        $result = $sessions === null ? ['none' => true] : ['sessions' => $sessions];
        $this->cache->put($this->sessionsKey($property, $pagePath, $days), $result, $this->cacheTtl);

        return $this->sessionsFromCache($result);
    }

    /**
     * @param  mixed  $result  the cached `['sessions' => int]`, a `['none' => true]` sentinel, or null on a miss
     */
    private function sessionsFromCache(mixed $result): ?int
    {
        if (! is_array($result) || isset($result['none']) || ! isset($result['sessions'])) {
            return null;
        }

        return (int) $result['sessions'];
    }

    private function sessionsKey(string $property, string $pagePath, int $days): string
    {
        return 'ga4:sessions:'.md5($property.'|'.$pagePath.'|'.$days);
    }

    /**
     * One GA4 Data API report of a page's sessions over the window. Null on a transient API error or
     * when GA4 has no row for the page yet; a real zero-session row returns 0.
     */
    private function fetchSessions(string $property, string $pagePath, int $days): ?int
    {
        $account = $this->connections->account();
        if ($account === null) {
            return null;
        }

        // Property id may be stored as "properties/123" or bare "123".
        $propertyId = str_starts_with($property, 'properties/') ? substr($property, 11) : $property;

        // WordPress serves permalinks WITH a trailing slash, so GA4 records the pagePath as "/foo/" — but
        // the control-plane slug is "/foo". Match BOTH variants (inListFilter) so an EXACT filter never
        // silently misses a trafficked page. Root ("/") has only one form.
        $base = rtrim($pagePath, '/');
        $variants = $base === '' ? ['/'] : [$base, $base.'/'];

        try {
            $json = $this->connections->request(
                $account,
                'post',
                rtrim($this->baseUrl, '/')."/properties/{$propertyId}:runReport",
                ['json' => [
                    'dateRanges' => [[
                        'startDate' => Carbon::now()->subDays($days)->format('Y-m-d'),
                        'endDate' => 'today',
                    ]],
                    'metrics' => [['name' => 'sessions']],
                    'dimensionFilter' => [
                        'filter' => [
                            'fieldName' => 'pagePath',
                            'inListFilter' => ['values' => $variants],
                        ],
                    ],
                ]],
            );
        } catch (GoogleException) {
            return null;
        }

        $rows = (array) ($json['rows'] ?? []);
        if ($rows === []) {
            return null;
        }

        // With no dimension requested GA4 returns one aggregated totals row, but sum defensively in case a
        // row-per-path shape comes back.
        $total = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $total += (int) round((float) ($row['metricValues'][0]['value'] ?? 0));
            }
        }

        return $total;
    }
}
