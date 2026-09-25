<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\ContentCard;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;

/**
 * The Indexing board's watchlist: every published page that is NOT yet in the index, with the date it was
 * published, so the operator can see what is waiting and for how long — plus, for a few days after it
 * lands, the page as a green "indexed" row before it falls off.
 *
 * Three states, the same verdict rule every surface uses ({@see ContentCard::resolveIndex}):
 *  - `published`  — Launchpad published it; Search Console has not inspected it yet. Plain.
 *  - `inspected`  — inspected, not indexed (the verdict says why). Amber.
 *  - `indexed`    — a PASS verdict OR impressions in the window. Green, and listed only while the index
 *                   date is within `launchpad.indexing.watch_days`; older than that it is simply indexed
 *                   and belongs on the page cards, not here.
 *
 * The index date is the earlier of the first PASS ({@see PageIndexState::$indexed_at}) and the first
 * impression ({@see PageImpressions::firstSeen()}) — whichever proved it first.
 *
 * Order: the waiting pages first, oldest published at the top (the ones stuck longest), then the landed
 * pages newest-indexed first.
 */
class IndexWatchlist
{
    public function __construct(private readonly PageImpressions $impressions) {}

    /**
     * @return array{
     *     rows: list<array{content_id: string, title: string, url: ?string, kind: string, published_at: ?string, inspected_at: ?string, indexed_at: ?string, state: string, reason: ?string, days_waiting: ?int}>,
     *     waiting: int, inspected: int, landed: int, watch_days: int
     * }
     */
    public function for(?Site $site): array
    {
        $watchDays = max(1, (int) config('launchpad.indexing.watch_days', 5));
        if ($site === null) {
            return ['rows' => [], 'waiting' => 0, 'inspected' => 0, 'landed' => 0, 'watch_days' => $watchDays];
        }

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type', 'published_at']);

        $verdicts = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('content_id')
            ->get(['content_id', 'index_verdict', 'last_inspected_at', 'indexed_at'])
            ->keyBy(fn (PageIndexState $r): string => (string) $r->content_id);

        $recent = $this->impressions->recent($site, $pages);
        $firstSeen = $this->impressions->firstSeen($site, $pages);

        $cutoff = Carbon::now()->subDays($watchDays)->startOfDay();
        $today = Carbon::now()->startOfDay();

        $waiting = [];
        $landed = [];
        foreach ($pages as $page) {
            $id = (string) $page->id;
            /** @var PageIndexState|null $verdict */
            $verdict = $verdicts->get($id);
            $inspected = $verdict !== null && $verdict->last_inspected_at !== null;
            $pass = $verdict !== null && $verdict->index_verdict === 'PASS';
            $inGoogle = isset($recent[$id]);

            $published = $page->published_at instanceof Carbon ? $page->published_at : null;
            $row = [
                'content_id' => $id,
                'title' => trim((string) $page->title),
                'url' => PublicUrl::forContent($site->domain_url, $page),
                // A post has no page type — it is just its kind.
                'kind' => $page->page_type->value ?? $page->kind->value,
                'published_at' => $published?->toDateString(),
                'inspected_at' => $inspected ? $verdict->last_inspected_at->toDateString() : null,
                'indexed_at' => null,
                'state' => 'published',
                'reason' => null,
                'days_waiting' => $published === null ? null : (int) $published->copy()->startOfDay()->diffInDays($today),
            ];

            if ($pass || $inGoogle) {
                $indexedAt = $this->indexDate($pass ? $verdict->indexed_at : null, $firstSeen[$id] ?? null);
                // No date at all (a PASS row from before the stamp existed with nothing else to go on) reads
                // as long indexed — it has nothing to show on a "just landed" list.
                if ($indexedAt === null || $indexedAt->lessThan($cutoff)) {
                    continue;
                }
                $row['state'] = 'indexed';
                $row['indexed_at'] = $indexedAt->toDateString();
                $row['days_waiting'] = $published === null ? null : (int) $published->copy()->startOfDay()->diffInDays($indexedAt->copy()->startOfDay());
                $landed[] = $row;

                continue;
            }

            if ($inspected) {
                $row['state'] = 'inspected';
                $row['reason'] = IndexCoverageState::tryFrom((string) $verdict->index_verdict)?->label() ?? (string) $verdict->index_verdict;
            }
            $waiting[] = $row;
        }

        usort($waiting, fn (array $a, array $b): int => [$a['published_at'] ?? '9999', $a['title']] <=> [$b['published_at'] ?? '9999', $b['title']]);
        usort($landed, fn (array $a, array $b): int => [$b['indexed_at'], $a['title']] <=> [$a['indexed_at'], $b['title']]);

        return [
            'rows' => [...$waiting, ...$landed],
            'waiting' => count(array_filter($waiting, fn (array $r): bool => $r['state'] === 'published')),
            'inspected' => count(array_filter($waiting, fn (array $r): bool => $r['state'] === 'inspected')),
            'landed' => count($landed),
            'watch_days' => $watchDays,
        ];
    }

    /** The earlier of the first PASS and the first impression — whichever proved the page indexed first. */
    private function indexDate(?Carbon $firstPass, ?string $firstImpression): ?Carbon
    {
        $dates = array_filter([
            $firstPass?->copy()->startOfDay(),
            $firstImpression === null ? null : Carbon::parse($firstImpression)->startOfDay(),
        ]);
        if ($dates === []) {
            return null;
        }
        usort($dates, fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return $dates[0];
    }
}
