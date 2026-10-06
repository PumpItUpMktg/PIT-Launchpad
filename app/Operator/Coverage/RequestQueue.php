<?php

namespace App\Operator\Coverage;

use App\Activity\ActivityRecorder;
use App\Enums\ContentKind;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * "Request today" (§ Indexing): Google offers no API to request indexing for an ordinary page — the
 * one lever is the Request-indexing button after inspecting a URL in Search Console, about ten a day per
 * property. So the order of those ten matters. This queue is the day's list: the most valuable pages
 * Google has NOT crawled (never a crawled-and-declined page — that is the rework window), waiting at least
 * `request_after_days`, not requested within `request_cooldown_days` — service and hub pages first, then
 * towns biggest first, then posts longest-waiting first — each with its inspect link. "Requested" stamps
 * the page so it rotates off tomorrow's list and the Why? panel shows the date.
 */
final class RequestQueue
{
    public const META_KEY = 'indexing_requested_at';

    public const HISTORY_KEY = 'indexing_requests';

    public function __construct(
        private readonly IndexWatchlist $watchlist,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @return array{limit: int, quota: int, after_days: int, cooldown_days: int, connected: bool, rows: list<array{content_id: string, title: string, kind: string, url: ?string, days_waiting: int, population: int, verdict: ?string, inspect_url: ?string, requested_at: ?string, requests: int}>, eligible: int, requested_today: int}
     */
    public function for(Site $site, ?int $limit = null): array
    {
        $limit ??= self::limit();
        $afterDays = self::afterDays();
        $cooldown = self::cooldownDays();
        $notCrawled = [IndexCoverageState::DiscoveredNotIndexed->value, IndexCoverageState::Unknown->value, IndexCoverageState::NotIndexedOther->value, null];

        $list = $this->watchlist->for($site);
        $rows = array_values(array_filter(
            $list['rows'],
            fn (array $r): bool => $r['state'] !== 'indexed' && ($r['days_waiting'] ?? 0) >= $afterDays && in_array($r['verdict'], $notCrawled, true),
        ));
        $pages = Content::withoutGlobalScope(SiteScope::class)->whereKey(array_column($rows, 'content_id'))
            ->get(['id', 'kind', 'page_type', 'geo_id', 'location_id', 'parent_location_id', 'meta'])->keyBy('id');
        $geoIds = $pages->pluck('geo_id')->filter()->unique()->values()->all();
        $population = $geoIds === [] ? [] : CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('geo_id', $geoIds)->pluck('population', 'geo_id')->all();

        $today = Carbon::now()->startOfDay();
        $cutoff = Carbon::now()->subDays($cooldown);
        $out = [];
        $requestedToday = 0;
        foreach ($rows as $row) {
            $page = $pages->get($row['content_id']);
            if ($page === null) {
                continue;
            }
            $meta = is_array($page->meta) ? $page->meta : [];
            $requestedAt = isset($meta[self::META_KEY]) ? Carbon::parse((string) $meta[self::META_KEY]) : null;
            $requests = is_array($meta[self::HISTORY_KEY] ?? null) ? count($meta[self::HISTORY_KEY]) : ($requestedAt !== null ? 1 : 0);
            if ($requestedAt !== null && $requestedAt->gte($today)) {
                $requestedToday++;
            }
            if ($requestedAt !== null && $requestedAt->gt($cutoff)) {
                continue;   // asked recently — give Google its time
            }
            $isTown = $page->kind === ContentKind::Page && $page->page_type === PageType::Location && $page->location_id === null && $page->parent_location_id !== null;
            $tier = match (true) {
                $page->kind === ContentKind::Page && ! $isTown => 0,   // service / hub / core pages: few, and every town links from them
                $isTown => 1,
                default => 2,
            };
            $pop = $isTown ? (int) ($population[(string) $page->geo_id] ?? 0) : 0;
            $out[] = [
                'content_id' => $row['content_id'],
                'title' => $row['title'],
                'kind' => $isTown ? 'town' : $row['kind'],
                'url' => $row['url'],
                'days_waiting' => (int) ($row['days_waiting'] ?? 0),
                'population' => $pop,
                'verdict' => $row['verdict'],
                'inspect_url' => StuckPages::inspectUrl($site, $row['url']),
                'requested_at' => $requestedAt?->toIso8601String(),
                'requests' => $requests,
                '_sort' => [$tier, -$pop, -(int) ($row['days_waiting'] ?? 0), $row['title']],
            ];
        }
        usort($out, fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);
        $eligible = count($out);
        $out = array_map(function (array $r): array {
            unset($r['_sort']);

            return $r;
        }, array_slice($out, 0, $limit));

        return [
            'limit' => $limit,
            'quota' => self::quota(),
            'after_days' => $afterDays,
            'cooldown_days' => $cooldown,
            'connected' => (bool) ($list['readiness']['connected'] ?? false),
            'rows' => $out,
            'eligible' => $eligible,
            'requested_today' => $requestedToday,
        ];
    }

    /** The operator pressed Request indexing in Search Console for this page: stamp it (it rotates off the list). */
    public function markRequested(Content $page, ?string $actorId = null): void
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        $now = Carbon::now()->toIso8601String();
        $meta[self::META_KEY] = $now;
        $history = is_array($meta[self::HISTORY_KEY] ?? null) ? $meta[self::HISTORY_KEY] : [];
        $history[] = $now;
        $meta[self::HISTORY_KEY] = array_slice($history, -10);
        $page->forceFill(['meta' => $meta])->save();
        $this->activity->record((string) $page->site_id, ActivityRecorder::PUBLISH_RELEASED, 'Indexing requested in Search Console for “'.$page->title.'”', [], (string) $page->title, $actorId);
    }

    /** Undo a stamp pressed by mistake. */
    public function unmark(Content $page): void
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        unset($meta[self::META_KEY]);
        $history = is_array($meta[self::HISTORY_KEY] ?? null) ? $meta[self::HISTORY_KEY] : [];
        array_pop($history);
        $meta[self::HISTORY_KEY] = $history;
        $page->forceFill(['meta' => $meta])->save();
    }

    /** When a page was last requested, from its meta (the Why? panel's fact). */
    public static function requestedAt(Content $page): ?string
    {
        $meta = is_array($page->meta) ? $page->meta : [];

        return isset($meta[self::META_KEY]) ? (string) $meta[self::META_KEY] : null;
    }

    public static function limit(): int
    {
        return max(1, (int) config('launchpad.indexing.request.limit', 10));
    }

    public static function quota(): int
    {
        return max(1, (int) config('launchpad.indexing.request.quota_per_day', 10));
    }

    public static function afterDays(): int
    {
        return max(0, (int) config('launchpad.indexing.request.after_days', 14));
    }

    public static function cooldownDays(): int
    {
        return max(1, (int) config('launchpad.indexing.request.cooldown_days', 14));
    }
}
