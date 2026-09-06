<?php

namespace App\Operator\Coverage;

use App\Console\Commands\MergeMarketsCommand;
use App\Locations\CoverageName;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Merges a DUPLICATE market into its clean twin and deletes the duplicate.
 *
 * Two market rows that share a `geo_id` (a Census place code) are the SAME place — a duplicate, whatever
 * their names ("1, Abingdon" over an existing "Abingdon"). `geo_id` is the authoritative signal: the name
 * check can't see it (the renamer's collision flag only knows two rows would COLLIDE, not that they are the
 * same place). So this compares on `geo_id` — the primary key for "is this the same market".
 *
 * Disposition, per duplicate group of one CLEAN-named survivor + one or more numbered duplicates:
 *   - reassign every dependent of the duplicate to the survivor — keywords, content, position snapshots,
 *     geo prompts (direct market_id), and the service / proof / media pivots (dedupe-safe: the survivor
 *     inherits the union, the duplicate's rows drop on delete);
 *   - delete the duplicate market;
 *   - CLEAN the place's `CoverageArea` name if it is still numbered — there is exactly ONE CoverageArea per
 *     (site, geo_id) (a unique index), so if it still reads "1, Abingdon" the next build's `projectTerritories`
 *     firstOrCreate's THAT and re-mints the duplicate. Aligning it to the survivor's clean name (via the one
 *     canonical {@see CoverageName::clean()}) closes that; it is the town's only CoverageArea, so it is
 *     cleaned in place, never deleted.
 *
 * Only the unambiguous case auto-merges: exactly one clean-named survivor + ≥1 dirty duplicate on a geo_id.
 * A group that is all-clean, all-dirty, or many-to-many is flagged AMBIGUOUS and left for a human — no guess
 * about which row survives. Report-only by planning; {@see MergeMarketsCommand} is report-only by default.
 * (The reassigned duplicate pages are themselves duplicates — they merge under the survivor, then go to the
 * town-page dedup path; this tool merges MARKETS, not pages.)
 */
final class MarketMerger
{
    /**
     * @return list<array{
     *   geo_id: string, ambiguous: bool, names: list<string>,
     *   winner_id: ?string, winner_name: ?string, loser_id: ?string, loser_name: ?string,
     *   area_id: ?string, area_dirty: bool,
     *   dependents: array{keywords:int,content:int,snapshots:int,geo_prompts:int,services:int,proof:int,media:int}
     * }>
     */
    public function plan(Site $site): array
    {
        $markets = Market::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->whereNotNull('geo_id')->where('geo_id', '!=', '')
            ->get();

        $rows = [];
        foreach ($markets->groupBy('geo_id') as $geoId => $group) {
            if ($group->count() < 2) {
                continue; // no twin — not a duplicate
            }

            $clean = $group->filter(fn (Market $m): bool => ! CoverageName::isDirty((string) $m->name))->values();
            $dirty = $group->filter(fn (Market $m): bool => CoverageName::isDirty((string) $m->name))->values();

            // Auto-merge ONLY the clear case: one clean survivor + one-or-more numbered duplicates.
            if ($clean->count() !== 1 || $dirty->isEmpty()) {
                $rows[] = [
                    'geo_id' => (string) $geoId, 'ambiguous' => true,
                    'names' => $group->map(fn (Market $m): string => (string) $m->name)->all(),
                    'winner_id' => null, 'winner_name' => null, 'loser_id' => null, 'loser_name' => null,
                    'area_id' => null, 'area_dirty' => false, 'dependents' => $this->zeroDeps(),
                ];

                continue;
            }

            [$areaId, $areaDirty] = $this->coverageAreaFor($site, (string) $geoId);
            $winner = $clean->first();
            foreach ($dirty as $loser) {
                $rows[] = [
                    'geo_id' => (string) $geoId, 'ambiguous' => false,
                    'names' => $group->map(fn (Market $m): string => (string) $m->name)->all(),
                    'winner_id' => (string) $winner->id, 'winner_name' => (string) $winner->name,
                    'loser_id' => (string) $loser->id, 'loser_name' => (string) $loser->name,
                    'area_id' => $areaId, 'area_dirty' => $areaDirty,
                    'dependents' => $this->deps($site, (string) $loser->id),
                ];
            }
        }

        return $rows;
    }

    /** Apply every unambiguous merge. Returns the number of duplicate markets merged + deleted. */
    public function apply(Site $site): int
    {
        $plan = array_values(array_filter($this->plan($site), fn (array $r): bool => ! $r['ambiguous']));

        foreach ($plan as $r) {
            /** @var string $winnerId */
            $winnerId = $r['winner_id'];
            /** @var string $loserId */
            $loserId = $r['loser_id'];

            DB::transaction(function () use ($site, $winnerId, $loserId, $r): void {
                $winner = Market::withoutGlobalScopes()->findOrFail($winnerId);
                $loser = Market::withoutGlobalScopes()->findOrFail($loserId);

                // Pivots — the survivor inherits the duplicate's relations (dedupe-safe); the duplicate's own
                // rows drop when the market is deleted (cascadeOnDelete).
                $winner->services()->syncWithoutDetaching($loser->services()->pluck('services.id')->all());
                $winner->proofItems()->syncWithoutDetaching($loser->proofItems()->pluck('proof_items.id')->all());
                $winner->mediaAssets()->syncWithoutDetaching($loser->mediaAssets()->pluck('media_assets.id')->all());

                // Direct market_id FKs — reassign to the survivor.
                Keyword::withoutGlobalScopes()->where('market_id', $loserId)->update(['market_id' => $winnerId]);
                Content::withoutGlobalScopes()->where('market_id', $loserId)->update(['market_id' => $winnerId]);
                PositionSnapshot::query()->where('market_id', $loserId)->update(['market_id' => $winnerId]);
                DB::table('geo_prompts')->where('market_id', $loserId)->update(['market_id' => $winnerId]);

                // Delete the duplicate market. Then, if the place's ONE CoverageArea (unique per (site, geo_id))
                // is still numbered, clean it to the survivor's name so the next build's projectTerritories
                // firstOrCreate's the survivor by name and re-mints nothing. It is the town's only area — cleaned
                // in place, never deleted.
                Market::withoutGlobalScopes()->whereKey($loserId)->delete();
                if ($r['area_id'] !== null && $r['area_dirty']) {
                    $area = CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->find($r['area_id']);
                    if ($area !== null) {
                        $clean = CoverageName::clean((string) $area->getAttribute('name'));
                        CoverageArea::withoutGlobalScopes()->whereKey($r['area_id'])->update(['name' => $clean]);
                    }
                }
            });
        }

        return count($plan);
    }

    /**
     * The place's single CoverageArea (unique per (site, geo_id)) and whether its name is still numbered.
     * A dirty area is the re-mint risk: the next build firstOrCreate's the market from THIS name, so if it
     * still reads "1, Abingdon" the merge must clean it. Returns [id|null, dirty].
     *
     * @return array{0: ?string, 1: bool}
     */
    private function coverageAreaFor(Site $site, string $geoId): array
    {
        $area = CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->where('geo_id', $geoId)->first();
        if ($area === null) {
            return [null, false];
        }

        return [(string) $area->id, CoverageName::isDirty((string) $area->getAttribute('name'))];
    }

    /** @return array{keywords:int,content:int,snapshots:int,geo_prompts:int,services:int,proof:int,media:int} */
    private function deps(Site $site, string $marketId): array
    {
        return [
            'keywords' => Keyword::withoutGlobalScopes()->where('market_id', $marketId)->count(),
            'content' => Content::withoutGlobalScopes()->where('market_id', $marketId)->count(),
            'snapshots' => PositionSnapshot::query()->where('market_id', $marketId)->count(),
            'geo_prompts' => DB::table('geo_prompts')->where('market_id', $marketId)->count(),
            'services' => DB::table('market_service')->where('market_id', $marketId)->count(),
            'proof' => DB::table('proof_item_market')->where('market_id', $marketId)->count(),
            'media' => DB::table('media_asset_market')->where('market_id', $marketId)->count(),
        ];
    }

    /** @return array{keywords:int,content:int,snapshots:int,geo_prompts:int,services:int,proof:int,media:int} */
    private function zeroDeps(): array
    {
        return ['keywords' => 0, 'content' => 0, 'snapshots' => 0, 'geo_prompts' => 0, 'services' => 0, 'proof' => 0, 'media' => 0];
    }
}
