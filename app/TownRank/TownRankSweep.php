<?php

namespace App\TownRank;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use Illuminate\Support\Carbon;

/**
 * The town-rank cadence (§ Town Rank, PR 3): which (keyword × mode) pairs a site is due to re-scan, what
 * that costs, and posting them. A pair is due when it has never been scanned, or its newest scan is
 * finalized and older than `town_rank.cadence_days`; a pending scan is never re-posted. The keyword set is
 * the site's grid keywords, the keywords tracked on the Town Rank wall, and any keyword already scanned here
 * (so a one-off --scan keeps refreshing).
 * A site whose due set exceeds the request ceiling is skipped whole and reported — never silently trimmed.
 */
final class TownRankSweep
{
    public function __construct(
        private readonly TownRankPoints $points,
        private readonly TownRankScanner $scanner,
    ) {}

    /**
     * @return list<array{keyword: Keyword, mode: string, last_scanned_at: string|null}>
     */
    public function due(Site $site): array
    {
        $cadence = max(1, (int) config('launchpad.town_rank.cadence_days', 7));
        $cutoff = Carbon::now()->subDays($cadence);

        // The wall's set, by flag alone: a keyword removed from the wall stops being swept (that is the
        // point of removing it), while its collected scans stay on record.
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('is_grid_keyword', true)->orWhere('track_town_rank', true))
            ->orderBy('query')
            ->get();

        $due = [];
        foreach ($keywords as $keyword) {
            foreach (TownRankScan::MODES as $mode) {
                $latest = TownRankScan::withoutGlobalScope(SiteScope::class)
                    ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                    ->orderByDesc('scanned_at')->first();
                if ($latest !== null && ($latest->status === 'pending' || ($latest->scanned_at !== null && $latest->scanned_at->gt($cutoff)))) {
                    continue;
                }
                $due[] = ['keyword' => $keyword, 'mode' => $mode, 'last_scanned_at' => $latest?->scanned_at?->toDateTimeString()];
            }
        }

        return $due;
    }

    /**
     * @return array{towns: int, due: list<array{keyword: Keyword, mode: string, last_scanned_at: string|null}>, requests: int, cost: float, ceiling: int, over_ceiling: bool}
     */
    public function plan(Site $site): array
    {
        $towns = count($this->points->forSite($site));
        $due = $this->due($site);
        $requests = $towns * count($due);
        $ceiling = max(0, (int) config('launchpad.town_rank.request_ceiling', 2000));

        return [
            'towns' => $towns,
            'due' => $due,
            'requests' => $requests,
            'cost' => $requests * TownRankScanner::costPerRequest(),
            'ceiling' => $ceiling,
            'over_ceiling' => $ceiling > 0 && $requests > $ceiling,
        ];
    }

    /**
     * Post every due pair (nothing when over the ceiling or nothing is due). Collection is the ingest sweep's.
     *
     * @return array{posted: int, requests: int, over_ceiling: bool}
     */
    public function run(Site $site): array
    {
        $plan = $this->plan($site);
        if ($plan['over_ceiling'] || $plan['due'] === [] || $plan['towns'] === 0) {
            return ['posted' => 0, 'requests' => $plan['requests'], 'over_ceiling' => $plan['over_ceiling']];
        }

        $posted = 0;
        foreach ($plan['due'] as $pair) {
            if ($this->scanner->post($site, $pair['keyword'], $pair['mode']) !== null) {
                $posted++;
            }
        }

        return ['posted' => $posted, 'requests' => $plan['requests'], 'over_ceiling' => false];
    }
}
