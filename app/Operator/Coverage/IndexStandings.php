<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Filament\Pages\IndexingBoard;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Support\Cadence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The read-model behind the operator **Indexing** surface — Google index coverage for one tenant, from
 * the persisted `page_index_states` table. UI-agnostic and testable; the Filament page
 * ({@see IndexingBoard}) is thin over it.
 *
 * HTTP-free by construction — reads ONLY `page_index_states`, never a live GSC / URL-Inspection call
 * (that provider sits behind the `sandhog:sync-index` capture path that WRITES this table).
 *
 * Two design points the surface exists to make honest:
 *   • The per-reason breakdown is the point, not the count — grouped by `index_verdict` (the reliable
 *     reason key: 'PASS' for indexed, else the {@see IndexCoverageState} value; `coverage_state` holds
 *     Google's raw free-text and is not safe to group on).
 *   • Sitemap-published vs all-known — a URL Launchpad published (a Content page, `content_id` set) is
 *     distinguished from a URL Google merely found (WP archives outside our sitemap, `content_id` null),
 *     so a large "not indexed" number over discovered archives never masks a healthy published set.
 */
class IndexStandings
{
    /** `index_verdict` values that are a CORRECT exclusion (not a defect, not "pending"). */
    private const EXCLUDED = [IndexCoverageState::ExcludedRedirect->value, IndexCoverageState::ExcludedCanonical->value];

    /**
     * @return array{
     *     published: array{total: int, indexed: int, not_indexed: int, excluded: int, reasons: list<array{state: string, label: string, count: int}>},
     *     all_known: array{total: int, indexed: int, not_indexed: int, excluded: int, reasons: list<array{state: string, label: string, count: int}>},
     *     discovered_only: int,
     *     all_known_available: bool,
     *     inspected_count: int,
     *     published_content_count: int,
     *     coverage_gap: int,
     *     data_through: ?string,
     *     last_inspected_at: ?string,
     *     freshness: array{oldest: ?string, newest: ?string, stale: int, total: int, interval_days: ?float}
     * }
     */
    public function for(?string $siteId): array
    {
        // Whether the all-known capture path is wired. Until it is, `page_index_states` holds only the
        // URLs Launchpad published — so the surface must SAY the all-known view is off, not silently show
        // a panel that mirrors the published set (a fixture can supply the data production can't).
        $allKnownAvailable = (bool) config('launchpad.indexing.all_known_capture', false);

        if ($siteId === null) {
            $empty = ['total' => 0, 'indexed' => 0, 'not_indexed' => 0, 'excluded' => 0, 'reasons' => []];

            return [
                'published' => $empty, 'all_known' => $empty, 'discovered_only' => 0,
                'all_known_available' => $allKnownAvailable,
                'inspected_count' => 0, 'published_content_count' => 0, 'coverage_gap' => 0, 'data_through' => null,
                'last_inspected_at' => null,
                'freshness' => ['oldest' => null, 'newest' => null, 'stale' => 0, 'total' => 0, 'interval_days' => null],
            ];
        }

        /** @var Collection<int, PageIndexState> $rows */
        $rows = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['content_id', 'index_verdict', 'last_inspected_at']);

        $publishedRows = $rows->filter(fn (PageIndexState $r): bool => $r->content_id !== null);
        $published = $this->summarize($publishedRows);
        $allKnown = $this->summarize($rows);

        // Coverage lag made visible: the inspector is daily and budget-capped, so it reaches only part of
        // the published set each run. "inspected of published" (362 of 474) is a real fact about how much
        // of the site the panel actually describes — never let the inspected count read as the whole site.
        $inspected = $published['total'];
        $publishedContent = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('status', ContentStatus::Published->value)
            ->count();

        // Freshness: a daily sync that fails silently would leave the panel confidently showing week-old
        // verdicts. Stamp the panel with the newest verdict's as-of date so staleness is visible.
        $lastInspected = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->max('last_inspected_at');

        return [
            'published' => $published,
            'all_known' => $allKnown,
            'discovered_only' => $allKnown['total'] - $published['total'],
            'all_known_available' => $allKnownAvailable,
            'inspected_count' => $inspected,
            'published_content_count' => $publishedContent,
            'coverage_gap' => max(0, $publishedContent - $inspected),
            'data_through' => $lastInspected !== null ? Carbon::parse($lastInspected)->toDateString() : null,
            'last_inspected_at' => $lastInspected !== null ? (string) $lastInspected : null,
            'freshness' => $this->vintage($publishedRows),
        ];
    }

    /**
     * How old the verdicts on this board actually are — oldest, newest, and how many have gone past the
     * expected cadence.
     *
     * The newest stamp alone OVERSTATES freshness, and does so worst on exactly the sites that need it
     * most. The inspector is budget-capped, so a large site is re-checked a slice at a time: a run that
     * touched forty of five hundred URLs an hour ago leaves the panel reading "as of today" over four
     * hundred verdicts that are weeks old. The range is the honest answer to "when are these from" —
     * one date can only answer it for a site small enough to be inspected in a single pass.
     *
     * Rows with no inspection stamp are not counted as stale: an un-stamped row was never checked, which
     * the coverage gap already reports, and folding the two together would double-count it.
     *
     * @param  Collection<int, PageIndexState>  $rows
     * @return array{oldest: ?string, newest: ?string, stale: int, total: int, interval_days: ?float}
     */
    private function vintage(Collection $rows): array
    {
        $stamped = $rows->filter(fn (PageIndexState $r): bool => $r->last_inspected_at !== null);
        $intervalSeconds = Cadence::intervalSeconds('index');
        $intervalDays = $intervalSeconds !== null ? round($intervalSeconds / 86400, 1) : null;

        if ($stamped->isEmpty()) {
            return ['oldest' => null, 'newest' => null, 'stale' => 0, 'total' => 0, 'interval_days' => $intervalDays];
        }

        /** @var Collection<int, Carbon> $stamps */
        $stamps = $stamped->map(fn (PageIndexState $r): Carbon => Carbon::parse($r->last_inspected_at));
        $cutoff = $intervalSeconds !== null ? Carbon::now()->subSeconds($intervalSeconds) : null;

        return [
            'oldest' => $stamps->min()?->toDateString(),
            'newest' => $stamps->max()?->toDateString(),
            'stale' => $cutoff === null ? 0 : $stamps->filter(fn (Carbon $at): bool => $at->lessThan($cutoff))->count(),
            'total' => $stamped->count(),
            'interval_days' => $intervalDays,
        ];
    }

    /**
     * @param  Collection<int, PageIndexState>  $rows
     * @return array{total: int, indexed: int, not_indexed: int, excluded: int, reasons: list<array{state: string, label: string, count: int}>}
     */
    private function summarize(Collection $rows): array
    {
        $verdict = fn (PageIndexState $r): string => $r->index_verdict !== null && $r->index_verdict !== ''
            ? $r->index_verdict
            : IndexCoverageState::NotInspected->value;

        $indexed = $rows->filter(fn (PageIndexState $r): bool => $r->index_verdict === 'PASS')->count();
        $excluded = $rows->filter(fn (PageIndexState $r): bool => in_array($verdict($r), self::EXCLUDED, true))->count();

        // Reasons: every non-indexed row grouped by its verdict, resolved to a label, biggest first.
        $reasons = $rows
            ->filter(fn (PageIndexState $r): bool => $r->index_verdict !== 'PASS')
            ->groupBy($verdict)
            ->map(fn (Collection $group, string $state): array => [
                'state' => $state,
                'label' => IndexCoverageState::tryFrom($state)?->label() ?? $state,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'total' => $rows->count(),
            'indexed' => $indexed,
            'not_indexed' => $rows->count() - $indexed - $excluded,
            'excluded' => $excluded,
            'reasons' => $reasons,
        ];
    }
}
