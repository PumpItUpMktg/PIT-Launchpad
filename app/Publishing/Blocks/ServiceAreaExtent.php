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

    /** The gap in pre-1960 housing share that makes the ends of a territory worth contrasting. */
    private const AGE_CONTRAST = 0.25;

    public function __construct(
        private readonly TownHousingFacts $housing = new TownHousingFacts,
        private readonly TownFloodFacts $flood = new TownFloodFacts,
    ) {}

    /**
     * The coverage paragraph for a hub page: what we cover, how far it runs, what is interesting about
     * the range, and what to do if you are outside it.
     *
     * One paragraph, not a stack of one-line statements. Three facts written as three separate sentences
     * — county, extent, a percentage — read as a data dump with a heading, which is what this replaces.
     *
     * @param  list<string>  $counties  the served county names, display-ready
     * @return list<string> one paragraph, or the plain county line when there is no extent to describe
     */
    public function paragraph(string $siteId, Location $location, string $city, array $counties, int $locationCount = 1): array
    {
        $counties = array_values(array_filter(array_map('trim', $counties), fn (string $c): bool => $c !== ''));
        $named = $this->named($siteId, $location);

        $where = $this->openingClause($city, $counties);
        if ($where === null) {
            return [];   // no county captured and no towns named: nothing honest to say
        }

        $sentences = [$where];

        $reach = $this->reachSentence($named, $location);
        if ($reach !== null) {
            $sentences[] = $reach;
        }

        $range = $this->rangeSentence($named);
        if ($range !== null) {
            $sentences[] = $range;
        }

        $sentences[] = $this->invitation($city, $locationCount);

        return [implode(' ', $sentences)];
    }

    /**
     * "From our Doylestown location we serve Bucks County and the communities around it."
     *
     * @param  list<string>  $counties
     */
    private function openingClause(string $city, array $counties): ?string
    {
        $from = trim($city) !== '' ? 'From our '.trim($city).' location we serve ' : 'We serve ';
        if ($counties !== []) {
            return $from.$this->countyPhrase($counties).' and the communities around it.';
        }

        return trim($city) !== '' ? 'We serve '.trim($city).' and the communities around it.' : null;
    }

    /**
     * "That territory runs from Riegelsville in the north to Bristol in the south, and from Milford in
     * the west to Morrisville in the east — about 30 miles at its widest."
     *
     * @param  array<string, CoverageArea>  $named
     */
    private function reachSentence(array $named, Location $location): ?string
    {
        $pairs = [];
        foreach ([['north', 'south'], ['west', 'east']] as [$a, $b]) {
            if (isset($named[$a], $named[$b])) {
                $pairs[] = 'from '.$this->displayName($named[$a]).' in the '.$a
                    .' to '.$this->displayName($named[$b]).' in the '.$b;
            }
        }
        if ($pairs === []) {
            return null;   // a footprint that does not span an axis is already described by the list
        }

        $miles = $this->furthestMiles($named, (float) $location->lat, (float) $location->lng);
        $tail = $miles !== null ? ' — about '.$miles.' miles at its widest' : '';

        return 'That territory runs '.implode(', and ', $pairs).$tail.'.';
    }

    /**
     * The interesting thing about a service area is its RANGE, not one number from one town: a contrast
     * between the ends says something a homeowner recognises, where "79% of Riegelsville's homes predate
     * 1960" alone is a statistic sitting on its own.
     *
     * Falls back to a single notable fact when only one end has data, and to nothing when neither does.
     *
     * @param  array<string, CoverageArea>  $named
     */
    private function rangeSentence(array $named): ?string
    {
        $ages = [];
        foreach ($named as $town) {
            $geoId = trim((string) $town->geo_id);
            $housing = $geoId === '' ? null : $this->housing->row($geoId);
            $share = $housing?->pre1960Share();
            if ($share !== null) {
                $ages[] = ['name' => $this->displayName($town), 'share' => $share];
            }
        }

        if (count($ages) >= 2) {
            usort($ages, fn (array $a, array $b): int => $b['share'] <=> $a['share']);
            $oldest = $ages[0];
            $newest = $ages[count($ages) - 1];
            // Only worth a sentence when the ends genuinely differ — a territory of uniform age has no
            // contrast to draw, and inventing one would be the filler this is meant to replace.
            if ($oldest['share'] - $newest['share'] >= self::AGE_CONTRAST) {
                return sprintf(
                    'The housing varies as much as the distance: about %d%% of homes in %s were built before 1960, against %d%% in %s.',
                    (int) round($oldest['share'] * 100),
                    $oldest['name'],
                    (int) round($newest['share'] * 100),
                    $newest['name'],
                );
            }
        }

        foreach ($named as $town) {
            $geoId = trim((string) $town->geo_id);
            if ($geoId === '') {
                continue;
            }
            foreach ([$this->housing->for($this->housing->row($geoId)), $this->flood->for($this->flood->row($geoId))] as $facts) {
                foreach ($facts as $fact) {
                    if (! str_contains($fact, 'median home')) {
                        return $fact;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The close: what to do if you are outside the list. A tenant with ONE location never claims another
     * could reach you — the offer has to be true before it is made.
     */
    private function invitation(string $city, int $locationCount): string
    {
        if ($locationCount > 1) {
            return trim($city) !== ''
                ? 'If your town is not listed below, call us — if we cannot reach you from '.trim($city).', one of our other locations may.'
                : 'If your town is not listed below, call us — another of our locations may reach you.';
        }

        return 'If your town is not listed below, call us and we will tell you straight whether we cover it.';
    }

    /**
     * The towns named in each direction, or [] when there is nothing to measure from.
     *
     * @return array<string, CoverageArea>
     */
    private function named(string $siteId, Location $location): array
    {
        $lat = $location->lat !== null ? (float) $location->lat : null;
        $lng = $location->lng !== null ? (float) $location->lng : null;
        if ($lat === null || $lng === null) {
            return [];
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get(['name', 'geo_id', 'lat', 'lng', 'distance_miles', 'source_location_ids'])
            ->filter(fn (CoverageArea $a): bool => is_array($a->source_location_ids)
                && in_array((string) $location->id, array_map('strval', $a->source_location_ids), true))
            ->values();

        return $towns->count() < self::MIN_DIRECTIONS ? [] : $this->extremes($towns, $lat, $lng);
    }

    /**
     * @param  list<string>  $counties
     */
    private function countyPhrase(array $counties): string
    {
        if (count($counties) > 1 && array_reduce($counties, fn (bool $c, string $n): bool => $c && str_ends_with($n, ' County'), true)) {
            return $this->phrase(array_map(fn (string $n): string => rtrim(substr($n, 0, -7)), $counties)).' counties';
        }

        return $this->phrase($counties);
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
