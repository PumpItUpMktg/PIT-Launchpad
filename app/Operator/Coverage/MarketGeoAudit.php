<?php

namespace App\Operator\Coverage;

use App\Models\Content;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit of a site's Markets across THREE lenses, so a corrupt/fabricated market's disposition
 * (repair the geo vs delete the market) is decided per row, never blanket:
 *
 *  1. GEO validity — valid / out_of_area (a real Earth coord outside the US service area, e.g. the
 *     South-Pacific pair that centred a grid over open ocean) / missing (null).
 *  2. DEPENDENTS — keywords pinned, content/town pages parented, position snapshots, and the
 *     service/proof/media pivots. A market with dependents is a heavier deletion than an orphan.
 *  3. AUTHORITATIVE identity — the market's `geo_id` (a Census place code) is the strongest signal it is a
 *     REAL enumerated place, ranked ABOVE the name/Location heuristic: five markets that looked fabricated
 *     ("Marshall", "Halls Cross Roads") turned out to be real Harford County, MD places (geo_id prefix 24,
 *     consecutive place codes) carrying only a `"N, "` name-numbering artifact — disposition rename, not
 *     delete. A market WITH a geo_id is never a delete candidate.
 *  4. LOCATION correspondence — a fallback HEURISTIC (no FK exists) for a market lacking a geo_id: does its
 *     city/state match one of the site's Locations (own cityState, or a served town)? Flagged as a
 *     heuristic, never a verdict.
 *
 * A market that is geo-valid, un-artifacted, and confirmed real (geo_id OR Location) is not surfaced
 * (unless includeClean). "No suspects" is a REAL clean result, not a failure to detect. Read-only.
 */
final class MarketGeoAudit
{
    /**
     * @return list<array{
     *   name: string, region: string, tier: string, geo: string, lat: ?string, lng: ?string,
     *   geo_id: ?string, name_artifact: bool, location_match: bool,
     *   dependents: array{keywords:int,content:int,snapshots:int,services:int,proof:int,media:int},
     *   total_dependents: int, advisory: string
     * }>
     */
    public function suspects(Site $site, bool $includeClean = false): array
    {
        $markets = Market::withoutGlobalScopes()->where('site_id', $site->id)->get();
        if ($markets->isEmpty()) {
            return [];
        }

        $ids = $markets->pluck('id')->all();
        $keywords = $this->modelCounts(Keyword::withoutGlobalScopes()->whereIn('market_id', $ids));
        $content = $this->modelCounts(Content::withoutGlobalScopes()->whereIn('market_id', $ids));
        $snapshots = $this->modelCounts(PositionSnapshot::withoutGlobalScopes()->whereIn('market_id', $ids));
        $services = $this->pivotCounts('market_service', $ids);
        $proof = $this->pivotCounts('proof_item_market', $ids);
        $media = $this->pivotCounts('media_asset_market', $ids);
        $places = $this->locationPlaces($site);

        $rows = [];
        foreach ($markets as $market) {
            $geo = ($market->lat === null || $market->lng === null)
                ? 'missing'
                : ($market->hasValidGeo() ? 'valid' : 'out_of_area');
            $geoId = ($market->geo_id !== null && trim((string) $market->geo_id) !== '') ? (string) $market->geo_id : null;
            $artifact = $this->hasNumberingArtifact((string) $market->name);
            $match = $this->matchesLocation($market, $places);

            // Not a suspect: geo-valid, un-artifacted, and confirmed a real place (geo_id OR Location).
            $suspect = $geo !== 'valid' || $artifact || ($geoId === null && ! $match);
            if (! $suspect && ! $includeClean) {
                continue;
            }

            $deps = [
                'keywords' => (int) ($keywords[$market->id] ?? 0),
                'content' => (int) ($content[$market->id] ?? 0),
                'snapshots' => (int) ($snapshots[$market->id] ?? 0),
                'services' => (int) ($services[$market->id] ?? 0),
                'proof' => (int) ($proof[$market->id] ?? 0),
                'media' => (int) ($media[$market->id] ?? 0),
            ];
            $total = array_sum($deps);

            $rows[] = [
                'name' => (string) $market->name,
                'region' => (string) ($market->region ?? ''),
                'tier' => $market->tier->value ?? '?',
                'geo' => $geo,
                'lat' => $market->lat !== null ? (string) $market->lat : null,
                'lng' => $market->lng !== null ? (string) $market->lng : null,
                'geo_id' => $geoId,
                'name_artifact' => $artifact,
                'location_match' => $match,
                'dependents' => $deps,
                'total_dependents' => $total,
                'advisory' => $this->advisory($geo, $geoId, $artifact, $match, $total),
            ];
        }

        return $rows;
    }

    /**
     * Advisory ONLY — the operator decides per row. `geo_id` is authoritative: a Census place code proves a
     * real enumerated place, so such a market is NEVER a delete candidate (repair its geo, rename its
     * artifact, or keep it). Delete is reserved for a market with no geo_id, no Location, and no dependents.
     */
    private function advisory(string $geo, ?string $geoId, bool $artifact, bool $locationMatch, int $totalDependents): string
    {
        $real = $geoId !== null || $locationMatch;

        return match (true) {
            $geo !== 'valid' => 'repair geo — '.($real
                ? 'a real place ('.($geoId !== null ? "geo_id {$geoId}" : 'Location match').') with a bad coordinate'
                : 'no geo_id or Location either — verify the place before repairing'),
            $artifact => 'rename — "N, " numbering artifact in the name'.($geoId !== null ? " (real place, geo_id {$geoId})" : ''),
            $totalDependents > 0 => 'review — has dependents (deletion cascades; keep if this is a real market)',
            $geoId !== null => "keep — has a Census geo_id ({$geoId}); a real enumerated place",
            $locationMatch => 'keep — matches a Location (likely a legitimate seasoning market)',
            default => 'delete candidate — no geo_id, no Location, no dependents (possibly fabricated)',
        };
    }

    /** A leading numbering artifact, e.g. "1, Abingdon". A name defect on an otherwise-real market. */
    private function hasNumberingArtifact(string $name): bool
    {
        return preg_match('/^\s*\d+\s*,\s*/', $name) === 1;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return array<string, int>
     */
    private function modelCounts($query): array
    {
        return $query->whereNotNull('market_id')
            ->selectRaw('market_id, count(*) as n')->groupBy('market_id')
            ->pluck('n', 'market_id')->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function pivotCounts(string $table, array $ids): array
    {
        return DB::table($table)->whereIn('market_id', $ids)
            ->selectRaw('market_id, count(*) as n')->groupBy('market_id')
            ->pluck('n', 'market_id')->all();
    }

    /**
     * The site's Locations as a set of place keys — each Location's own city/state AND its served towns,
     * keyed both with and without state so a region-format mismatch doesn't hide a real correspondence.
     *
     * @return array<string, true>
     */
    private function locationPlaces(Site $site): array
    {
        $set = [];
        foreach (Location::withoutGlobalScopes()->where('site_id', $site->id)->get() as $location) {
            $cs = $location->cityState();
            $this->addPlace($set, $cs['city'], $cs['state']);
            foreach ((array) ($location->served_towns ?? []) as $town) {
                $this->addPlace($set, (string) data_get($town, 'name', ''), (string) data_get($town, 'state', ''));
            }
        }

        return $set;
    }

    /** @param  array<string, true>  $set */
    private function addPlace(array &$set, string $city, string $state): void
    {
        $city = $this->cleanName($city);
        if ($city === '') {
            return;
        }
        $set[$this->key($city, $state)] = true;
        $set[$this->key($city, '')] = true; // name-only fallback
    }

    /** @param  array<string, true>  $places */
    private function matchesLocation(Market $market, array $places): bool
    {
        $name = $this->cleanName((string) $market->name);
        $region = (string) ($market->region ?? '');

        return isset($places[$this->key($name, $region)]) || isset($places[$this->key($name, '')]);
    }

    /** Strip a leading numbering artifact ("1, Abingdon" → "Abingdon"), lower + trim. */
    private function cleanName(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/^\s*\d+\s*,\s*/', '', trim($value))));
    }

    private function key(string $city, string $state): string
    {
        return $this->cleanName($city).'|'.mb_strtolower(trim($state));
    }
}
