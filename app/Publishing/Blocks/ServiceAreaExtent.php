<?php

namespace App\Publishing\Blocks;

use App\Local\Grounding\TownFloodFacts;
use App\Local\Grounding\TownHousingFacts;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * How far a location's service area actually reaches, in the direction a reader thinks in.
 *
 * A list of town names answers "who do you serve"; it does not answer the question a visitor actually
 * has, which is "do you come to me?". The extent does: the furthest served town each way, and the
 * distance at the widest point. Every number is derived from the coverage we already claim — the towns
 * this location serves, their coordinates, their measured distance — so it cannot drift from the map
 * and the list beside it.
 *
 * HUB PAGES ONLY, and that is the whole design. The same four extremities on seven hundred town pages
 * would be boilerplate by the second one; a town page already carries its own nearest neighbours,
 * computed from its own coordinates.
 *
 * At most ONE local detail follows, taken verbatim from the per-town fact composers — which only speak
 * at the ends of a range, so a detail appears when there is something notable to say and stays quiet
 * when there isn't. Four facts in a row would be a data dump wearing a sentence.
 */
final class ServiceAreaExtent
{
    /** Below this many named directions the sentence says less than the list already does. */
    private const MIN_DIRECTIONS = 3;

    public function __construct(
        private readonly TownHousingFacts $housing = new TownHousingFacts,
        private readonly TownFloodFacts $flood = new TownFloodFacts,
    ) {}

    /**
     * Nought to two sentences: the extent, then at most one local detail about a town it named.
     *
     * @return list<string>
     */
    public function sentences(string $siteId, Location $location, string $city): array
    {
        $lat = $location->lat !== null ? (float) $location->lat : null;
        $lng = $location->lng !== null ? (float) $location->lng : null;
        if ($lat === null || $lng === null) {
            return [];   // nothing to measure from
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get(['name', 'geo_id', 'lat', 'lng', 'distance_miles', 'source_location_ids'])
            ->filter(fn (CoverageArea $a): bool => is_array($a->source_location_ids)
                && in_array((string) $location->id, array_map('strval', $a->source_location_ids), true))
            ->values();
        if ($towns->count() < self::MIN_DIRECTIONS) {
            return [];
        }

        $named = $this->extremes($towns, $lat, $lng);
        if (count($named) < self::MIN_DIRECTIONS) {
            return [];   // a footprint too tight to describe by compass says nothing worth saying
        }

        $parts = [];
        foreach ($named as $direction => $town) {
            $parts[] = $direction.' to '.$this->displayName($town);
        }
        $lead = trim($city) !== '' ? 'From '.trim($city).' that reaches ' : 'That reaches ';
        $sentence = $lead.$this->phrase($parts);

        $furthest = $this->furthestMiles($named, $lat, $lng);
        $sentence .= $furthest !== null
            ? ' — about '.$furthest.' miles at the widest point.'
            : '.';

        $out = [$sentence];
        $detail = $this->detail($named);
        if ($detail !== null) {
            $out[] = $detail;
        }

        return $out;
    }

    /**
     * The furthest town in each compass direction FROM THE LOCATION — a town only counts for a direction
     * it actually lies in, and is named once, so four directions name four towns rather than one corner
     * town twice.
     *
     * Directions are filled in map order (north, east, south, west), each taking the furthest town still
     * unspent. Ranking every claim together instead reads worse than it sounds: a degree of longitude is
     * not a degree of latitude, so the widest-offset-first pass handed a north-west town its WEST claim
     * and left north unnamed with no town to fill it.
     *
     * @param  Collection<int, CoverageArea>  $towns
     * @return array<string, CoverageArea>
     */
    private function extremes(Collection $towns, float $lat, float $lng): array
    {
        $offsets = [
            'north' => fn (CoverageArea $a): float => (float) $a->lat - $lat,
            'east' => fn (CoverageArea $a): float => (float) $a->lng - $lng,
            'south' => fn (CoverageArea $a): float => $lat - (float) $a->lat,
            'west' => fn (CoverageArea $a): float => $lng - (float) $a->lng,
        ];

        $named = [];
        $used = [];
        foreach ($offsets as $direction => $offset) {
            $best = null;
            $bestOffset = 0.0;
            foreach ($towns as $town) {
                $key = $this->townKey($town);
                if (isset($used[$key])) {
                    continue;
                }
                $value = $offset($town);
                if ($value > $bestOffset) {
                    $best = $town;
                    $bestOffset = $value;
                }
            }
            if ($best !== null) {
                $named[$direction] = $best;
                $used[$this->townKey($best)] = true;
            }
        }

        return $named;
    }

    /** A town's durable identity for "already named": its GEOID, else its name. */
    private function townKey(CoverageArea $town): string
    {
        $geoId = trim((string) $town->geo_id);

        return $geoId !== '' ? $geoId : mb_strtolower(trim((string) $town->name));
    }

    /**
     * The widest reach among the named towns, in miles. The stored distance is to the nearest base, so a
     * great-circle from THIS location is used when it is missing — never a guess, always a measurement.
     *
     * @param  array<string, CoverageArea>  $named
     */
    private function furthestMiles(array $named, float $lat, float $lng): ?int
    {
        $miles = 0.0;
        foreach ($named as $town) {
            $measured = $town->distance_miles !== null
                ? (float) $town->distance_miles
                : $this->haversine($lat, $lng, (float) $town->lat, (float) $town->lng);
            $miles = max($miles, $measured);
        }

        return $miles >= 1.0 ? (int) round($miles) : null;
    }

    /**
     * One local detail about a town the sentence named, or null. Taken verbatim from the fact composers,
     * which speak only at the ends of a range — so this is quiet unless the fact is worth a reader's time.
     *
     * @param  array<string, CoverageArea>  $named
     */
    private function detail(array $named): ?string
    {
        foreach ($named as $town) {
            $geoId = trim((string) $town->geo_id);
            if ($geoId === '') {
                continue;
            }
            foreach ([$this->housing->for($this->housing->row($geoId)), $this->flood->for($this->flood->row($geoId))] as $facts) {
                foreach ($facts as $fact) {
                    // The median-year line is true of every town and reads as filler here; the shares and
                    // the flood mapping are what distinguish one end of a service area from the other.
                    if (! str_contains($fact, 'median home')) {
                        return $fact;
                    }
                }
            }
        }

        return null;
    }

    /** "Warrington" — the coverage name without its trailing state, which the sentence does not need. */
    private function displayName(CoverageArea $town): string
    {
        return trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim((string) $town->name)));
    }

    /**
     * @param  list<string>  $parts
     */
    private function phrase(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 3958.8 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
