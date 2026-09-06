<?php

namespace App\Operator\Coverage;

use App\Console\Commands\RenameMarketArtifactsCommand;
use App\Locations\CoverageName;
use App\Models\CoverageArea;
use App\Models\Market;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Strips the leading numbered-list artifact ("4, Marshall" -> "Marshall") off a market NAME — and its
 * source CoverageArea, so the rename is a true no-op for the next build.
 *
 * Scope is deliberately `markets.name` (+ its CoverageArea source), NOT the published pages. Renaming a
 * market's town pages' TITLES was tried and pulled: the numbered markets that have published pages turned
 * out to be RUNAWAY DUPLICATES (a town with 9 pages, `-3 -4 -5 -6` slugs) — a duplication problem the
 * pages get DEDUPED for, never retitled (retitling 27 live indexed pages is a content change that would
 * paper over the duplication). This tool is for the genuine numbered-only markets (a unique geo_id, no
 * clean twin): clean the name, leave the pages to the dedup path.
 *
 * Why the CoverageArea is still touched (it is not a page): the artifact's SOURCE is CoverageArea.name,
 * which GuidedEntityProjector::projectTerritories reads to firstOrCreate the market BY NAME. If the market
 * is renamed out of step with its CoverageArea, the next build re-mints the old numbered name as a
 * duplicate. Aligning both (Market.name === CoverageArea.name, via the one canonical {@see CoverageName::clean()})
 * keeps the rename durable. A market whose cleaned name already belongs to another market (a real duplicate,
 * e.g. "1, Abingdon" over an existing "Abingdon") is a `collision` — skipped for the merge tool, never
 * renamed into a second duplicate.
 *
 * Report-only by planning; {@see RenameMarketArtifactsCommand} is the thin, report-only-by-default surface.
 */
final class MarketArtifactRenamer
{
    /**
     * The rename plan for a site: one row per market whose name carries the artifact, with the coupled
     * CoverageArea it will align alongside it. A `collision` row (cleaned name already owned by another
     * market — a duplicate) is left for the merge tool and is NOT applied.
     *
     * @return list<array{
     *   market_id: string, old: string, new: string, collision: bool,
     *   coverage_area_id: ?string, coverage_area_old: ?string, coverage_area_dirty: bool
     * }>
     */
    public function plan(Site $site): array
    {
        $markets = Market::withoutGlobalScopes()->where('site_id', $site->id)->get();

        $rows = [];
        foreach ($markets as $market) {
            $old = (string) $market->name;
            if (! CoverageName::isDirty($old)) {
                continue;
            }

            // The CoverageArea is the SOURCE projectTerritories reads, so the market must land on ITS cleaned
            // name — matched authoritatively by geo_id (a Census place code), else by cleaned-name equality.
            $area = $this->coverageAreaFor($site, $market);
            $areaId = null;
            $areaOld = null;
            $areaDirty = false;
            $new = CoverageName::clean($old);
            if ($area !== null) {
                $areaName = $this->areaName($area);
                $areaId = (string) $area->id;
                $areaOld = $areaName;
                $areaDirty = CoverageName::isDirty($areaName);
                $new = CoverageName::clean($areaName);
            }

            // Safeguard: renaming into an existing DIFFERENT market of the same clean name would create the
            // very duplicate we are here to prevent (a numbered row over a clean twin). Flag + skip → merge tool.
            $collision = $markets->contains(
                fn (Market $m): bool => $m->id !== $market->id && (string) $m->name === $new
            );

            $rows[] = [
                'market_id' => (string) $market->id,
                'old' => $old,
                'new' => $new,
                'collision' => $collision,
                'coverage_area_id' => $areaId,
                'coverage_area_old' => $areaOld,
                'coverage_area_dirty' => $areaDirty,
            ];
        }

        return $rows;
    }

    /**
     * Apply every non-colliding row in one transaction — align the market to its CoverageArea (and clean the
     * CoverageArea itself if it predates the write mutator). Published pages are NOT touched (dedup, not
     * retitle). Returns the number of markets renamed. Idempotent: a second run finds nothing dirty.
     */
    public function apply(Site $site): int
    {
        $plan = array_values(array_filter($this->plan($site), fn (array $r): bool => ! $r['collision']));

        DB::transaction(function () use ($plan): void {
            foreach ($plan as $r) {
                Market::withoutGlobalScopes()->whereKey($r['market_id'])->update(['name' => $r['new']]);

                if ($r['coverage_area_id'] !== null && $r['coverage_area_dirty']) {
                    CoverageArea::withoutGlobalScopes()->whereKey($r['coverage_area_id'])->update(['name' => $r['new']]);
                }
            }
        });

        return count($plan);
    }

    /**
     * The CoverageArea coupled to a market: by geo_id (authoritative Census place code) first, else by
     * cleaned-name equality — matching cleaned forms so an already-normalized area still pairs with a
     * still-dirty market.
     */
    private function coverageAreaFor(Site $site, Market $market): ?CoverageArea
    {
        $geoId = $market->geo_id !== null && trim((string) $market->geo_id) !== '' ? (string) $market->geo_id : null;
        if ($geoId !== null) {
            $byGeo = CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->where('geo_id', $geoId)->first();
            if ($byGeo !== null) {
                return $byGeo; // geo_id is authoritative
            }
        }

        $cleanMarket = CoverageName::clean((string) $market->name);
        foreach (CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->get() as $area) {
            if (CoverageName::clean($this->areaName($area)) === $cleanMarket) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Read a CoverageArea's stored name as a string. Via getAttribute() deliberately: the model's `name()`
     * accessor is a set-only Attribute (Attribute<never, string>), so a direct `$area->name` READ is typed
     * `never` by static analysis — this reads the stored value without tripping that.
     */
    private function areaName(CoverageArea $area): string
    {
        return (string) $area->getAttribute('name');
    }
}
