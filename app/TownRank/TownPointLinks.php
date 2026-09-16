<?php

namespace App\TownRank;

use App\Locations\CoverageWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Links a scan's stored points to the site's CURRENT towns (§ Town Rank).
 *
 * A point is written with the coverage-area row id of the town it measured, but that id is a surrogate:
 * {@see CoverageWriter::write()} rebuilds a site's computed coverage by deleting every
 * non-manual `CoverageArea` and inserting fresh rows, so every id changes on every rebuild (the writer
 * even re-keys its own `page_selected` snapshot by GEOID to survive it). Nothing re-points the stored scan
 * points, so after a rebuild the report joined ranks to towns that no longer existed and every town
 * rendered "not found" — a whole board of grey over data that was completely intact.
 *
 * So the link is resolved on the durable identity instead, cheapest first: the Census GEOID (unique per
 * site), then the stored row id (still right when no rebuild has happened), then the town's label + state
 * — and only when that label matches exactly one town, since {@see TownLabels} county-qualifies duplicates.
 */
final class TownPointLinks
{
    /**
     * The scan's points keyed by the CURRENT coverage-area id of the town each one measured. Points whose
     * town is no longer covered are dropped (there is no row to show them on); a town with no point is
     * absent, which the report reads as unscanned.
     *
     * @template TPoint of Model
     *
     * @param  list<array<string, mixed>>  $towns  the site's town list ({@see TownRankPoints::forSite()})
     * @param  iterable<TPoint>  $points  town-rank points or coverage-mode geo-grid points
     * @return Collection<string, TPoint>
     */
    public static function byTown(array $towns, iterable $points): Collection
    {
        [$byGeoId, $byId, $byName] = self::indexes($towns);

        /** @var Collection<string, TPoint> $out */
        $out = new Collection;
        foreach ($points as $point) {
            $id = self::resolve($point, $byGeoId, $byId, $byName);
            if ($id !== null && ! $out->has($id)) {
                $out->put($id, $point);
            }
        }

        return $out;
    }

    /**
     * The current coverage-area id for one point, or null when its town is no longer covered (or its label
     * is shared and so can't identify a town).
     *
     * @param  array<string, string>  $byGeoId
     * @param  array<string, string>  $byId
     * @param  array<string, string|null>  $byName
     */
    public static function resolve(Model $point, array $byGeoId, array $byId, array $byName): ?string
    {
        $geoId = self::text($point->getAttribute('geo_id'));
        if ($geoId !== null && isset($byGeoId[$geoId])) {
            return $byGeoId[$geoId];
        }

        $stored = self::text($point->getAttribute('coverage_area_id'));
        if ($stored !== null && isset($byId[$stored])) {
            return $stored;
        }

        // A map-pack point carries no state, so its label is matched on its own — and only when exactly one
        // town wears that label.
        $label = self::text($point->getAttribute('label'));
        $name = self::nameKey($label, self::text($point->getAttribute('state')));

        return $name !== null ? ($byName[$name] ?? null) : null;
    }

    /**
     * The lookup tables {@see resolve()} takes, built from the site's town list.
     *
     * @param  list<array<string, mixed>>  $towns
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string|null>}
     */
    public static function indexes(array $towns): array
    {
        $byGeoId = [];
        $byId = [];
        $byName = [];
        foreach ($towns as $town) {
            $id = (string) $town['coverage_area_id'];
            $byId[$id] = $id;
            $geoId = self::text($town['geo_id'] ?? null);
            if ($geoId !== null) {
                $byGeoId[$geoId] = $id;
            }
            $label = self::text($town['label'] ?? null);
            foreach ([self::nameKey($label, self::text($town['state'] ?? null)), self::nameKey($label, null)] as $name) {
                if ($name !== null) {
                    // A label two towns share can't identify either of them — mark it ambiguous, never guess.
                    $byName[$name] = array_key_exists($name, $byName) && $byName[$name] !== $id ? null : $id;
                }
            }
        }

        return [$byGeoId, $byId, $byName];
    }

    private static function nameKey(?string $label, ?string $state): ?string
    {
        return $label === null ? null : mb_strtolower($label).'|'.mb_strtolower((string) $state);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
