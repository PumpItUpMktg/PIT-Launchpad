<?php

namespace App\GeoGrid;

use App\Locations\CoverageWriter;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\TownRank\TownPointLinks;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored scan point's town population — the weight behind every population-weighted coverage number
 * (found rate, SoLV, the Local Visibility Score, the map's per-town label).
 *
 * Looking it up by the point's stored coverage-area row id silently returns 0 after a coverage rebuild,
 * because {@see CoverageWriter::write()} replaces every computed town row (the same defect
 * that greyed the rank boards). A town weighted 0 drops out of the metric entirely, so a rebuild quietly
 * shrank the denominator instead of failing loudly. The link is resolved on the town's durable identity
 * instead — {@see TownPointLinks} — and the population read from the town that resolves.
 *
 * One query per site per request (the service is bound `scoped`).
 */
final class TownPopulations
{
    /** @var array<string, array{geo: array<string, string>, id: array<string, string>, name: array<string, string|null>, pop: array<string, int>}> */
    private array $memo = [];

    /** The population of the town this point measures, or 0 when it resolves to no current town. */
    public function of(string $siteId, Model $point): int
    {
        $tables = $this->tables($siteId);
        $id = TownPointLinks::resolve($point, $tables['geo'], $tables['id'], $tables['name']);

        return $id !== null ? ($tables['pop'][$id] ?? 0) : 0;
    }

    /** Drop the per-request memo (tests, or a long-lived process that changed coverage). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @return array{geo: array<string, string>, id: array<string, string>, name: array<string, string|null>, pop: array<string, int>}
     */
    private function tables(string $siteId): array
    {
        if (isset($this->memo[$siteId])) {
            return $this->memo[$siteId];
        }

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['id', 'geo_id', 'name', 'state', 'population']);

        $towns = [];
        $pop = [];
        foreach ($areas as $area) {
            $id = (string) $area->id;
            $towns[] = ['coverage_area_id' => $id, 'geo_id' => $area->geo_id, 'label' => $area->name, 'state' => $area->state];
            $pop[$id] = (int) ($area->population ?? 0);
        }
        [$geo, $byId, $name] = TownPointLinks::indexes($towns);

        return $this->memo[$siteId] = ['geo' => $geo, 'id' => $byId, 'name' => $name, 'pop' => $pop];
    }
}
