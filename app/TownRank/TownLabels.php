<?php

namespace App\TownRank;

use App\Models\JobCounty;

/**
 * Display labels for a set of towns that disambiguate same-named municipalities (§ Town Rank): a site that
 * covers two Washingtons and three Bethlehems needs each dot and row to say which one. A name that is unique
 * within the set (per state) is left alone; a repeated one gets a qualifier — the county name when the
 * {@see JobCounty} registry knows the county (from the 10-digit subdivision GEOID's first five digits), else
 * the county FIPS, or "(place)" for a 7-digit place id that carries no county. Pure over the given rows plus
 * one registry read; the bare name stays available for queries.
 */
final class TownLabels
{
    /**
     * @param  list<array{id: string, name: string, state: string|null, geo_id: string|null}>  $towns
     * @return array<string, string> id => display label
     */
    public function for(array $towns): array
    {
        $groups = [];
        foreach ($towns as $town) {
            $groups[$this->key($town['name'], $town['state'])][] = $town;
        }

        $countyIds = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            foreach ($group as $town) {
                $fips = $this->countyFips($town['geo_id']);
                if ($fips !== null) {
                    $countyIds[$fips] = true;
                }
            }
        }
        $countyNames = $countyIds === [] ? [] : JobCounty::query()
            ->whereIn('county_geoid', array_keys($countyIds))
            ->pluck('name', 'county_geoid')
            ->map(fn ($n): string => (string) $n)
            ->all();

        $labels = [];
        foreach ($groups as $group) {
            $qualify = count($group) > 1;
            foreach ($group as $town) {
                $labels[$town['id']] = $qualify
                    ? $town['name'].' ('.$this->qualifier($town['geo_id'], $countyNames).')'
                    : $town['name'];
            }
        }

        return $labels;
    }

    /** @param array<string, string> $countyNames */
    private function qualifier(?string $geoId, array $countyNames): string
    {
        $fips = $this->countyFips($geoId);
        if ($fips === null) {
            return 'place';
        }
        if (isset($countyNames[$fips])) {
            return preg_replace('/\s+County$/i', '', $countyNames[$fips]) ?? $countyNames[$fips];
        }

        return 'county '.substr($fips, 2);
    }

    /** The 5-digit STATE+COUNTY FIPS of a 10-digit county-subdivision GEOID; null for a 7-digit place id. */
    private function countyFips(?string $geoId): ?string
    {
        $geoId = trim((string) $geoId);

        return strlen($geoId) === 10 ? substr($geoId, 0, 5) : null;
    }

    private function key(string $name, ?string $state): string
    {
        return mb_strtolower(trim($name)).'|'.mb_strtolower((string) $state);
    }
}
