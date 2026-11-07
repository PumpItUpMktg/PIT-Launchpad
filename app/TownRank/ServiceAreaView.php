<?php

namespace App\TownRank;

use App\Jobs\BuildServiceArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A service area's page ({@see ServiceAreas::area()}) read from cache and built on the worker — never
 * computed inside a web request. Opening an area builds a card per tracked keyword, each reading that
 * keyword's full report (its two latest scans, their baselines, the results JSON) plus the GBP grid: the
 * same read that timed the Town rank wall out at the gateway ({@see TownRankWall}), and it timed this page
 * out the same way. Same remedy: the page shows what the worker last built, asks for a rebuild when the
 * copy is missing or stale, and says "building" only before the first one lands. A scan completing
 * forgets the site's areas so the next open rebuilds against the new data.
 */
final class ServiceAreaView
{
    public const TTL_SECONDS = 86400;

    public const FRESH_SECONDS = 300;

    /** @return array{area: array<string, mixed>, built_at: string}|null */
    public static function get(Site $site, string $locationId): ?array
    {
        $raw = Cache::get(self::key((string) $site->id, $locationId));

        return is_array($raw) && is_array($raw['area'] ?? null) && is_string($raw['built_at'] ?? null) ? $raw : null;
    }

    /** Build now (the job body) and store; null when the location is not this site's. */
    public static function build(Site $site, string $locationId): ?array
    {
        $area = app(ServiceAreas::class)->area($site, $locationId);
        if ($area === null) {
            Cache::forget(self::key((string) $site->id, $locationId));

            return null;
        }
        $view = ['area' => $area, 'built_at' => Carbon::now()->toIso8601String()];
        Cache::put(self::key((string) $site->id, $locationId), $view, self::TTL_SECONDS);

        return $view;
    }

    public static function refresh(Site $site, string $locationId): void
    {
        BuildServiceArea::dispatch((string) $site->id, $locationId);
    }

    /**
     * What the page reads: the built copy (possibly stale) after asking for a rebuild when missing or
     * older than `$maxAgeSeconds`. Null means "building — nothing to show yet".
     *
     * @return array{area: array<string, mixed>, built_at: string}|null
     */
    public static function ensureFresh(Site $site, string $locationId, int $maxAgeSeconds = self::FRESH_SECONDS): ?array
    {
        $view = self::get($site, $locationId);
        if ($view === null || Carbon::parse($view['built_at'])->diffInSeconds(Carbon::now()) >= $maxAgeSeconds) {
            self::refresh($site, $locationId);
            $view = self::get($site, $locationId) ?? $view;   // a sync queue has rebuilt it by now
        }

        return $view;
    }

    /** Drop every built area of a site (a scan completed — the next open rebuilds on the new data). */
    public static function forgetSite(string $siteId): void
    {
        $ids = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->pluck('id');
        foreach ($ids as $id) {
            Cache::forget(self::key($siteId, (string) $id));
        }
    }

    private static function key(string $siteId, string $locationId): string
    {
        return "town_rank:area:{$siteId}:{$locationId}";
    }
}
