<?php

namespace App\TownRank;

use App\Jobs\BuildTownRankWall;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The Town Rank wall, read from cache and built on the worker — never computed inside a web request.
 *
 * A wall is every tracked keyword's card: 28 keyword reports, each reading its two latest scans and their
 * baselines (~100k point rows for a whole site) plus the map-pack grid. Rendering that per page load, and
 * again every 30 s while the wall polls, is what timed the page out at the gateway whenever the collectors
 * were writing to the same tables. Now the page reads {@see get()}; a missing or stale copy asks the worker
 * for a rebuild ({@see refresh()} — one unique job per site) and the page shows what it has, with when it
 * was built. Collection progress on the cards moves as the copy is rebuilt: every FRESH_SECONDS while
 * someone is watching a collecting wall, and the moment a scan completes.
 */
final class TownRankWall
{
    /** How long a built wall is kept — a day; a site nobody opens stops being rebuilt. */
    public const TTL_SECONDS = 86400;

    /** A copy older than this is rebuilt when the page asks (the poll while collecting lands here). */
    public const FRESH_SECONDS = 90;

    /**
     * The built wall, or null when none has been built yet.
     *
     * @return array{cards: list<array<string, mixed>>, built_at: string}|null
     */
    public static function get(Site $site): ?array
    {
        $raw = Cache::get(self::key((string) $site->id));

        return is_array($raw) && is_array($raw['cards'] ?? null) && is_string($raw['built_at'] ?? null) ? $raw : null;
    }

    /** Build the wall now (the worker's job body) and store it. */
    public static function build(Site $site): array
    {
        $wall = ['cards' => app(TownRankBoard::class)->cards($site, false), 'built_at' => Carbon::now()->toIso8601String()];
        Cache::put(self::key((string) $site->id), $wall, self::TTL_SECONDS);

        return $wall;
    }

    /** Ask the worker for a rebuild (unique per site — a rebuild already queued or running is not doubled). */
    public static function refresh(Site $site): void
    {
        BuildTownRankWall::dispatch((string) $site->id);
    }

    /**
     * What the page reads: the built wall (possibly stale) after asking for a rebuild when it is missing
     * or older than `$maxAgeSeconds`. Null means "building — nothing to show yet".
     *
     * @return array{cards: list<array<string, mixed>>, built_at: string}|null
     */
    public static function ensureFresh(Site $site, int $maxAgeSeconds = self::FRESH_SECONDS): ?array
    {
        $wall = self::get($site);
        if ($wall === null || Carbon::parse($wall['built_at'])->diffInSeconds(Carbon::now()) >= $maxAgeSeconds) {
            self::refresh($site);
            $wall = self::get($site) ?? $wall;   // a sync queue (tests, the console) has rebuilt it by now
        }

        return $wall;
    }

    public static function forget(Site $site): void
    {
        Cache::forget(self::key((string) $site->id));
    }

    private static function key(string $siteId): string
    {
        return "town_rank:wall:{$siteId}";
    }
}
