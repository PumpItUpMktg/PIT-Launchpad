<?php

namespace App\Operator\Coverage;

use App\Console\Commands\RenameMarketArtifactsCommand;
use App\Locations\CoverageName;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Market;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Strips the leading numbered-list artifact ("4, Marshall" -> "Marshall") off a market and everything
 * coupled to it BY NAME, in lockstep, so the rename is a true no-op for the next build.
 *
 * Why the cascade (not just markets.name): the artifact's SOURCE is CoverageArea.name, copied at build
 * time into three places that are NOT connected by a foreign key —
 *   - Market.name        via GuidedEntityProjector::projectTerritories (firstOrCreate(['name' => area.name]))
 *   - Content.title       via BuildManifestAssembler (title = area.name . ', ' . state), baked at materialize
 *   - and re-matched      via marketForCoverageArea (Market.name === CoverageArea.name)
 * Renaming markets.name alone would desync the pair: the next build's projectTerritories re-mints the old
 * name as a DUPLICATE market. (Existing pages do not orphan — PageMaterializer re-links them by
 * build_pages.content_id, an id, and skips them, so their pinned market_id FK survives.) So the fix follows
 * the SOURCE: it aligns Market.name to the CoverageArea (the value projectTerritories reads) and cleans the
 * pages' titles. Slugs are left alone — LocationNesting recomputes each town slug from its title on every
 * build, so a corrected title yields a corrected slug on the next build (no live-URL surgery here).
 *
 * The one canonical stripper is {@see CoverageName::clean()} — the same normalizer the CoverageArea write
 * mutator uses — so every field lands on the identical cleaned value.
 *
 * Report-only by planning; {@see RenameMarketArtifactsCommand} is the thin,
 * report-only-by-default surface.
 */
final class MarketArtifactRenamer
{
    /**
     * The rename plan for a site: one row per market whose name carries the artifact, with the coupled
     * CoverageArea and the pinned town pages it will clean alongside it. A row flagged `collision` is left
     * for a manual merge (its cleaned name already belongs to another market) and is NOT applied.
     *
     * @return list<array{
     *   market_id: string, old: string, new: string, collision: bool,
     *   coverage_area_id: ?string, coverage_area_old: ?string, coverage_area_dirty: bool,
     *   pages: list<array{id: string, old_title: string, new_title: string, slug: string, published: bool}>,
     *   published_pages: int
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
            // very duplicate we are here to prevent (the latent re-mint may have already run). Flag + skip.
            $collision = $markets->contains(
                fn (Market $m): bool => $m->id !== $market->id && (string) $m->name === $new
            );

            $pages = Content::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->where('market_id', $market->id)
                ->get()
                ->filter(fn (Content $c): bool => CoverageName::isDirty((string) $c->title))
                ->map(fn (Content $c): array => [
                    'id' => (string) $c->id,
                    'old_title' => (string) $c->title,
                    'new_title' => CoverageName::clean((string) $c->title),
                    'slug' => (string) $c->slug,
                    'published' => $c->wp_post_id !== null,
                ])
                ->values()
                ->all();

            $rows[] = [
                'market_id' => (string) $market->id,
                'old' => $old,
                'new' => $new,
                'collision' => $collision,
                'coverage_area_id' => $areaId,
                'coverage_area_old' => $areaOld,
                'coverage_area_dirty' => $areaDirty,
                'pages' => $pages,
                'published_pages' => count(array_filter($pages, fn (array $p): bool => $p['published'])),
            ];
        }

        return $rows;
    }

    /**
     * Apply every non-colliding row in one transaction — align the market to the CoverageArea, clean the
     * CoverageArea itself if it predates the write mutator, and strip the pinned pages' titles. Returns the
     * number of markets renamed. Idempotent: a second run finds nothing dirty.
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

                foreach ($r['pages'] as $p) {
                    Content::withoutGlobalScopes()->whereKey($p['id'])->update(['title' => $p['new_title']]);
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
