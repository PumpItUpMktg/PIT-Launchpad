<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\GeoGrid\CoverageGrid;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GbpProfile;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\TownRankScan;
use App\TownPages\PrioritySectionStatus;
use Illuminate\Support\Carbon;

/**
 * The numbers on an office's title bar on the Pages board (§ Operate): the build (towns, selected, built,
 * queued, published), what Google did with it (indexed, waiting, declined; impressions and clicks over
 * the window), and what it earned (in the pack, on page 1, sections live, the listing's rating). Every
 * figure is read from what the boards already keep — nothing here calls out — and the strip says when the
 * indexing and search data were last refreshed, because a sum is only as current as its newest row.
 */
final class OfficeBuildStrip
{
    public function __construct(
        private readonly LocationDashboard $dashboard,
        private readonly CoverageGrid $grid,
        private readonly PrioritySectionStatus $sections,
    ) {}

    /**
     * @return array{
     *   towns: int, selected: int, built: int, queued: int, published: int,
     *   indexed: int, waiting: int, declined: int, inspected_at: Carbon|null,
     *   impressions: int, clicks: int, window_days: int,
     *   keyword: string|null, top3: int|null, page1: int|null, scanned: int|null, in_pack_pct: float|null,
     *   sections_live: int, rating: float|null, ratings_total: int|null
     * }
     */
    public function for(Location $office): array
    {
        $site = $office->site;
        $towns = $this->grid->pointsFor($office);
        $populations = [];
        foreach ($towns as $t) {
            $populations[$t['geo_id']] = $t['population'];
        }
        $selected = $site === null ? 0 : CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('geo_id', array_keys($populations))->where('page_selected', true)->count();

        // The cluster: the office's own page and the town pages under it.
        $cluster = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $office->site_id)
            ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Location->value)
            ->where(fn ($q) => $q->where('location_id', $office->id)->orWhere('parent_location_id', $office->id))
            ->get(['id', 'status']);
        $published = $cluster->where('status', ContentStatus::Published)->count();
        $queued = $cluster->whereIn('status', [ContentStatus::Approved, ContentStatus::Rendering, ContentStatus::Publishing])->count();

        // What Google did with the published pages: the dashboard's honest union (impressions OR a PASS verdict)
        // is the indexed count; declined is Google's explicit "crawled, not indexed"; waiting is the rest.
        $d = $this->dashboard->for($office);
        $states = PageIndexState::withoutGlobalScope(SiteScope::class)->where('site_id', $office->site_id)
            ->whereIn('content_id', $cluster->where('status', ContentStatus::Published)->pluck('id')->all())
            ->get(['content_id', 'coverage_state', 'last_inspected_at']);
        $indexed = (int) ($d['indexing']['indexed'] ?? 0);
        $declined = min((int) ($d['indexing']['pending'] ?? 0), $states->where('coverage_state', IndexCoverageState::CrawledNotIndexed->value)->count());
        $waiting = max(0, (int) ($d['indexing']['pending'] ?? 0) - $declined);

        $mode = $d['town_rank']['modes'][TownRankScan::MODE_TOWN_QUERY] ?? null;
        $scanned = is_array($mode) ? (int) $mode['top3'] + (int) $mode['page1'] + (int) $mode['page2'] + (int) $mode['beyond'] + (int) $mode['not_found'] : null;

        $sectionsLive = 0;
        if ($site !== null && $populations !== []) {
            foreach ($this->sections->forGeoIds($site, $populations) as $st) {
                if ($st['state'] === PrioritySectionStatus::CURRENT) {
                    $sectionsLive++;
                }
            }
        }
        $profile = $office->place_id === null ? null : GbpProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $office->site_id)->where('place_id', $office->place_id)->first();

        return [
            'towns' => count($towns),
            'selected' => $selected,
            'built' => $cluster->count(),
            'queued' => $queued,
            'published' => $published,
            'indexed' => $indexed,
            'waiting' => $waiting,
            'declined' => $declined,
            'inspected_at' => $states->max('last_inspected_at'),
            'impressions' => (int) ($d['performance']['impressions'] ?? 0),
            'clicks' => (int) ($d['performance']['clicks'] ?? 0),
            'window_days' => (int) ($d['performance']['window_days'] ?? 28),
            'keyword' => $d['town_rank']['keyword'] ?? null,
            'top3' => is_array($mode) ? (int) $mode['top3'] : null,
            'page1' => is_array($mode) ? (int) $mode['top3'] + (int) $mode['page1'] : null,
            'scanned' => $scanned,
            'in_pack_pct' => isset($d['geo_grid']['mean_solv']) && is_numeric($d['geo_grid']['mean_solv']) ? round((float) $d['geo_grid']['mean_solv'], 1) : null,
            'sections_live' => $sectionsLive,
            'rating' => $profile?->rating,
            'ratings_total' => $profile?->ratings_total,
        ];
    }
}
