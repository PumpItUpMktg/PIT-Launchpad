<?php

namespace App\Publishing\Drip;

use App\Activity\ActivityRecorder;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Jobs\ReleasePublishDrip;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\IndexWatchlist;
use Illuminate\Support\Carbon;

/**
 * The publish drip (§ Publish drip): first-time publishes go live a batch at a time, the next batch only
 * when the previous one is indexed — so a site never has a hundred new URLs competing for the same crawl
 * budget at once (the 108-page "stuck zone" this replaces).
 *
 * - "In flight" = pages published within `stale_days` that Google has not indexed yet (read from the same
 *   watchlist the Indexing board shows). A page waiting longer than that stops counting — the Indexing
 *   board owns it — so one stubborn page never blocks the queue.
 * - "Slots" = batch − in flight. {@see release()} pushes that many queued pages: town pages biggest first
 *   (where the searches are), then everything else in the order it was queued.
 * - The queue is a `meta.drip_queued_at` marker on an approved page; a re-push of a live page (wp_post_id
 *   set) never queues. The hourly {@see ReleasePublishDrip} releases; an operator can release
 *   a batch now, or publish one page past the queue.
 */
final class PublishDrip
{
    public const QUEUED_KEY = 'drip_queued_at';

    /** Stamped when the drip releases a page; it holds a slot until the push lands and the watchlist takes over. */
    public const RELEASED_KEY = 'drip_released_at';

    public function __construct(
        private readonly IndexWatchlist $watchlist,
        private readonly ActivityRecorder $activity,
    ) {}

    /** Whether a publish of this content should queue rather than push: the drip is on and this is its first publish. */
    public function applies(Site $site, Content $content): bool
    {
        return $site->publishDrip()['enabled'] && $content->wp_post_id === null;
    }

    /** Queue a page (idempotent); returns its position, 1 = next out. */
    public function enqueue(Content $content, ?string $actorId = null): int
    {
        $meta = is_array($content->meta) ? $content->meta : [];
        if (! isset($meta[self::QUEUED_KEY])) {
            $meta[self::QUEUED_KEY] = Carbon::now()->toIso8601String();
            if ($actorId !== null) {
                $meta['drip_queued_by'] = $actorId;
            }
            $content->forceFill(['meta' => $meta])->save();
        }
        $site = Site::withoutGlobalScopes()->find($content->site_id);
        $position = 0;
        if ($site !== null) {
            foreach ($this->queued($site) as $i => $row) {
                if ($row['content_id'] === (string) $content->id) {
                    $position = $i + 1;
                }
            }
        }

        return max(1, $position);
    }

    /** Take a page off the queue without publishing it (it stays approved). */
    public function dequeue(Content $content): void
    {
        $meta = is_array($content->meta) ? $content->meta : [];
        unset($meta[self::QUEUED_KEY], $meta['drip_queued_by'], $meta[self::RELEASED_KEY]);
        $content->forceFill(['meta' => $meta])->save();
    }

    /**
     * The queue in release order: town pages biggest first, then by the time queued.
     *
     * @return list<array{content_id: string, title: string, kind: string, population: int, queued_at: string}>
     */
    public function queued(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Approved->value)
            ->whereNull('wp_post_id')
            ->whereRaw('CAST(meta AS TEXT) LIKE ?', ['%'.self::QUEUED_KEY.'%'])
            ->get(['id', 'title', 'kind', 'page_type', 'geo_id', 'location_id', 'parent_location_id', 'meta']);
        $geoIds = $pages->pluck('geo_id')->filter()->unique()->values()->all();
        $population = $geoIds === [] ? [] : CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('geo_id', $geoIds)->pluck('population', 'geo_id')->all();

        $rows = [];
        foreach ($pages as $page) {
            $queuedAt = is_array($page->meta) ? ($page->meta[self::QUEUED_KEY] ?? null) : null;
            if (! is_string($queuedAt)) {
                continue;
            }
            $isTown = $page->kind === ContentKind::Page && $page->page_type === PageType::Location && $page->location_id === null && $page->parent_location_id !== null;
            $rows[] = [
                'content_id' => (string) $page->id,
                'title' => (string) $page->title,
                'kind' => $page->kind === ContentKind::Post ? 'post' : ($page->page_type instanceof PageType ? $page->page_type->value : 'page'),
                'population' => $isTown ? (int) ($population[(string) $page->geo_id] ?? 0) : 0,
                'queued_at' => $queuedAt,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['population'], $a['queued_at']] <=> [$a['population'], $b['queued_at']]);

        return $rows;
    }

    /**
     * Pages published within `stale_days` that Google has not indexed yet.
     *
     * @return list<array{content_id: string, title: string, days_waiting: int, verdict: string|null}>
     */
    public function inFlight(Site $site): array
    {
        $staleDays = $site->publishDrip()['stale_days'];
        $out = [];
        foreach ($this->watchlist->for($site)['rows'] as $row) {
            if ($row['state'] === 'indexed' || $row['days_waiting'] === null || $row['days_waiting'] > $staleDays) {
                continue;
            }
            $out[] = ['content_id' => $row['content_id'], 'title' => $row['title'], 'days_waiting' => (int) $row['days_waiting'], 'verdict' => $row['verdict']];
        }
        // Released but not live yet (the push is on the worker): holds its slot so two releases in the same
        // hour can't both count it as free.
        $released = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereNull('wp_post_id')
            ->whereRaw('CAST(meta AS TEXT) LIKE ?', ['%'.self::RELEASED_KEY.'%'])
            ->get(['id', 'title', 'meta']);
        foreach ($released as $page) {
            $at = is_array($page->meta) ? ($page->meta[self::RELEASED_KEY] ?? null) : null;
            if (! is_string($at) || Carbon::parse($at)->lt(Carbon::now()->subDays($staleDays))) {
                continue;
            }
            $out[] = ['content_id' => (string) $page->id, 'title' => (string) $page->title, 'days_waiting' => 0, 'verdict' => null];
        }

        return $out;
    }

    /**
     * Everything the panel and the command show.
     *
     * @return array{settings: array{enabled: bool, batch: int, stale_days: int}, in_flight: list<array{content_id: string, title: string, days_waiting: int, verdict: string|null}>, queued: list<array{content_id: string, title: string, kind: string, population: int, queued_at: string}>, slots: int}
     */
    public function status(Site $site): array
    {
        $settings = $site->publishDrip();
        $inFlight = $this->inFlight($site);

        return [
            'settings' => $settings,
            'in_flight' => $inFlight,
            'queued' => $this->queued($site),
            'slots' => max(0, $settings['batch'] - count($inFlight)),
        ];
    }

    /**
     * Release the next pages: as many as there are slots (or `$limit`), in queue order. Each goes through
     * the same idempotent PublishContent push as a direct publish.
     *
     * @return list<string> the content ids released
     */
    public function release(Site $site, ?int $limit = null, ?string $actorId = null): array
    {
        $status = $this->status($site);
        $take = $limit ?? $status['slots'];
        if ($take <= 0) {
            return [];
        }
        $released = [];
        foreach (array_slice($status['queued'], 0, $take) as $row) {
            $page = Content::withoutGlobalScope(SiteScope::class)->find($row['content_id']);
            if ($page === null) {
                continue;
            }
            $this->dequeue($page);
            $meta = is_array($page->meta) ? $page->meta : [];
            $meta[self::RELEASED_KEY] = Carbon::now()->toIso8601String();
            $page->forceFill(['meta' => $meta])->save();
            PublishContent::dispatch((string) $page->id, $actorId);
            $released[] = (string) $page->id;
        }
        if ($released !== []) {
            $this->activity->record(
                (string) $site->id,
                ActivityRecorder::PUBLISH_RELEASED,
                sprintf('Publish drip released %d %s (%d still waiting for Google, %d queued)', count($released), count($released) === 1 ? 'page' : 'pages', count($status['in_flight']), max(0, count($status['queued']) - count($released))),
                ['pages' => count($released)],
                clientVisible: true,
            );
        }

        return $released;
    }

    /** Turn the drip on or off, or change its batch / stale days, on the site's own override. @param  array{enabled?: bool, batch?: int, stale_days?: int}  $changes */
    public function configure(Site $site, array $changes): array
    {
        $current = $site->publishDrip();
        $next = [
            'enabled' => (bool) ($changes['enabled'] ?? $current['enabled']),
            'batch' => max(1, min(100, (int) ($changes['batch'] ?? $current['batch']))),
            'stale_days' => max(1, min(90, (int) ($changes['stale_days'] ?? $current['stale_days']))),
        ];
        $site->forceFill(['publish_drip' => $next])->save();

        return $next;
    }
}
