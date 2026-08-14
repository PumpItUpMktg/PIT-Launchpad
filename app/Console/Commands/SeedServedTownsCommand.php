<?php

namespace App\Console\Commands;

use App\Enums\SizeTier;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\County;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Seed each county Location's `served_towns` from the Census — the scripted import that replaces
 * hand-typing NJ's 564 municipalities. For every Location matched to a county (by name, or the
 * explicit --counties list), it enumerates the county's subdivisions ({@see MunicipalityGazetteer}),
 * joins ACS population ({@see CensusPopulation}) for the Large/Medium/Small tiering, orders the towns
 * largest-first, and writes the {name, state, lat, lng, geocoded} rows `served_towns` expects.
 *
 * It writes ONLY `served_towns` — never `county_geoids` — so it never triggers the CoverageArea
 * town-page explosion. The cannibalization guard (a town belongs to exactly ONE location per site)
 * is honored: a municipality name that appears in more than one seeded county is qualified with its
 * county — "Washington Twp (Morris)" vs "Washington Twp (Mercer)" — so each resolves uniquely; a town
 * that still collides with an EXISTING non-seeded location is reported and skipped, never overwritten.
 *
 *   launchpad:seed-served-towns {site} [--state=34] [--counties=Bergen --counties=Essex] [--dry-run]
 */
class SeedServedTownsCommand extends Command
{
    protected $signature = 'launchpad:seed-served-towns
        {site : the Site id}
        {--state=34 : the state FIPS (or 2-letter code) the counties live in — NJ = 34}
        {--counties=* : limit to these county names or 5-digit GEOIDs (default: every county Location on the site)}
        {--dry-run : print the plan and write nothing}';

    protected $description = 'Seed county Locations\' served_towns from the Census (population-tiered), never county_geoids.';

    /** State 2-letter code → FIPS, for the --state convenience (only the ones we onboard). */
    private const STATE_FIPS = ['NJ' => '34', 'NY' => '36', 'PA' => '42', 'CT' => '09'];

    public function handle(MunicipalityGazetteer $gazetteer, CensusPopulation $population): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $stateFips = $this->resolveStateFips((string) $this->option('state'));
        if ($stateFips === null) {
            $this->error('Unrecognized --state (give a 2-digit FIPS like 34, or a code like NJ).');

            return self::FAILURE;
        }

        // County lookup for the state, keyed both by normalized name and by 5-digit GEOID.
        $byName = [];
        $byGeoId = [];
        foreach ($gazetteer->countiesInState($stateFips) as $county) {
            $byName[$this->normalizeCountyName($county->name)] = $county;
            $byGeoId[$county->geoId] = $county;
        }
        if ($byGeoId === []) {
            $this->error("No counties returned for state FIPS {$stateFips} — is the gazetteer reachable / CENSUS key set?");

            return self::FAILURE;
        }

        /** @var list<string> $filter */
        $filter = array_values(array_filter((array) $this->option('counties'), fn ($c): bool => trim((string) $c) !== ''));
        $filterNames = [];
        foreach ($filter as $token) {
            $filterNames[$this->normalizeCountyName((string) $token)] = true;
        }

        $locations = Location::withoutGlobalScopes()->where('site_id', $site->id)->orderBy('name')->get();
        if ($locations->isEmpty()) {
            $this->error('Site has no locations — create the county Location records first.');

            return self::FAILURE;
        }

        // Resolve each location to a county (skip the ones we can't match / were filtered out).
        /** @var list<array{location: Location, county: County}> $matched */
        $matched = [];
        foreach ($locations as $location) {
            $key = $this->normalizeCountyName((string) $location->name);
            $county = $byName[$key] ?? $byGeoId[$this->firstGeoId($location)] ?? null;
            if ($county === null) {
                $this->line("   <fg=yellow>skip</> {$location->name} — no county match in state {$stateFips}");

                continue;
            }
            if ($filterNames !== [] && ! isset($filterNames[$this->normalizeCountyName($county->name)]) && ! isset($filterNames[$county->geoId])) {
                continue; // outside the requested --counties subset
            }
            $matched[] = ['location' => $location, 'county' => $county];
        }

        if ($matched === []) {
            $this->error('No location matched a county (by name or GEOID) within the requested set.');

            return self::FAILURE;
        }

        // Pass 1 — gather every county's towns, then find names shared across >1 seeded county.
        /** @var array<string, list<array{name: string, state: string, lat: float|null, lng: float|null, geocoded: bool, pop: int|null}>> $townsByLocation */
        $townsByLocation = [];
        $nameCounts = [];
        foreach ($matched as $row) {
            $county = $row['county'];
            $pop = $population->forCounty($county->stateFips, $county->countyFips);
            $towns = [];
            foreach ($gazetteer->subdivisionsInCounty($county->stateFips, $county->countyFips) as $m) {
                if (! $this->isRealMunicipality($m)) {
                    continue; // "County subdivisions not defined" and the like
                }
                $towns[] = [
                    'name' => $m->name,
                    'state' => strtoupper((string) ($m->state ?? '')),
                    'lat' => $m->lat,
                    'lng' => $m->lng,
                    'geocoded' => $m->lat !== null && $m->lng !== null,
                    'pop' => $pop[$m->geoId] ?? null,
                ];
                $nameCounts[$this->nameKey($m->name, (string) ($m->state ?? ''))] = ($nameCounts[$this->nameKey($m->name, (string) ($m->state ?? ''))] ?? 0) + 1;
            }
            $townsByLocation[$row['location']->id] = $towns;
        }

        // Pass 2 — qualify cross-county duplicates, tier-order, drop clashes with non-seeded locations, write.
        $seededIds = array_map(fn (array $r): string => $r['location']->id, $matched);
        $existingOwners = $this->existingTownOwners($site->id, $seededIds);

        $grandTotal = 0;
        $grandTiers = ['major' => 0, 'large' => 0, 'medium' => 0, 'small' => 0, 'ungrouped' => 0];
        $qualified = 0;
        $skippedClashes = [];

        foreach ($matched as $row) {
            $location = $row['location'];
            $county = $row['county'];
            $countyLabel = $this->normalizeCountyName($county->name, keepCase: true);

            /** @var list<array{name: string, state: string, lat: float|null, lng: float|null, geocoded: bool}> $rows */
            $rows = [];
            $tiers = ['major' => 0, 'large' => 0, 'medium' => 0, 'small' => 0, 'ungrouped' => 0];
            foreach ($this->sortByPopulation($townsByLocation[$location->id]) as $town) {
                $name = $town['name'];
                if (($nameCounts[$this->nameKey($name, $town['state'])] ?? 0) > 1) {
                    $name = "{$name} ({$countyLabel})"; // disambiguate the ~9 "Washington Twp"s
                    $qualified++;
                }

                $owner = $existingOwners[$this->nameKey($name, $town['state'])] ?? null;
                if ($owner !== null && $owner !== $location->id) {
                    $skippedClashes[] = "{$name} (owned by {$owner})";

                    continue; // never overwrite a town an existing non-seeded location already claims
                }

                $rows[] = [
                    'name' => $name,
                    'state' => $town['state'],
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'geocoded' => $town['geocoded'],
                ];
                $tiers[$this->tierKey($town['pop'])]++;
            }

            $this->line(sprintf(
                '   %-18s %-14s %4d towns  (%d major · %d large · %d medium · %d small · %d ungrouped)',
                $countyLabel, "[{$county->geoId}]", count($rows),
                $tiers['major'], $tiers['large'], $tiers['medium'], $tiers['small'], $tiers['ungrouped'],
            ));

            if (! $this->option('dry-run')) {
                $location->forceFill(['served_towns' => $rows])->save();
            }

            $grandTotal += count($rows);
            foreach ($tiers as $k => $v) {
                $grandTiers[$k] += $v;
            }
        }

        $this->newLine();
        $this->line(sprintf('TOTAL: %d towns across %d county location(s) — %d major · %d large · %d medium · %d small · %d ungrouped',
            $grandTotal, count($matched),
            $grandTiers['major'], $grandTiers['large'], $grandTiers['medium'], $grandTiers['small'], $grandTiers['ungrouped']));
        if ($qualified > 0) {
            $this->line("Disambiguated {$qualified} duplicate town name(s) with their county.");
        }
        if ($skippedClashes !== []) {
            $this->warn('Skipped '.count($skippedClashes).' town(s) already owned by a non-seeded location: '.implode('; ', $skippedClashes));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — nothing written. Re-run without --dry-run to seed served_towns.');
        } else {
            $this->newLine();
            $this->info('Seeded served_towns (county_geoids untouched — no town-page explosion).');
        }

        return self::SUCCESS;
    }

    private function resolveStateFips(string $state): ?string
    {
        $state = strtoupper(trim($state));
        if (preg_match('/^\d{2}$/', $state) === 1) {
            return $state;
        }

        return self::STATE_FIPS[$state] ?? null;
    }

    /** "Essex County, New Jersey" / "Essex County" / "Essex" → "essex" (or "Essex" with keepCase). */
    private function normalizeCountyName(string $name, bool $keepCase = false): string
    {
        $name = trim(explode(',', $name, 2)[0]);
        $name = (string) preg_replace('/\s+county$/i', '', $name);
        $name = trim($name);

        return $keepCase ? $name : mb_strtolower($name);
    }

    private function firstGeoId(Location $location): string
    {
        $geoIds = is_array($location->county_geoids) ? $location->county_geoids : [];

        return isset($geoIds[0]) ? (string) $geoIds[0] : '';
    }

    /** Real MCDs only — TIGER returns pseudo-subdivisions ("County subdivisions not defined") we don't page. */
    private function isRealMunicipality(Municipality $m): bool
    {
        $name = trim($m->name);

        return $name !== ''
            && preg_match('/not defined/i', $name) !== 1
            && preg_match('/\p{L}/u', $name) === 1;
    }

    private function tierKey(?int $population): string
    {
        $tier = SizeTier::forPopulation($population);

        return $tier === null ? 'ungrouped' : $tier->value;
    }

    /**
     * @param  list<array{name: string, state: string, lat: float|null, lng: float|null, geocoded: bool, pop: int|null}>  $towns
     * @return list<array{name: string, state: string, lat: float|null, lng: float|null, geocoded: bool, pop: int|null}>
     */
    private function sortByPopulation(array $towns): array
    {
        usort($towns, function (array $a, array $b): int {
            return ($b['pop'] ?? -1) <=> ($a['pop'] ?? -1) ?: strcmp($a['name'], $b['name']);
        });

        return $towns;
    }

    /**
     * Existing served-town ownership on OTHER (non-seeded) locations — nameKey => owning location name,
     * so a seed never silently overwrites a town another page already claims.
     *
     * @param  list<string>  $seededIds
     * @return array<string, string>
     */
    private function existingTownOwners(string $siteId, array $seededIds): array
    {
        $owners = [];
        $others = Location::withoutGlobalScopes()
            ->where('site_id', $siteId)
            ->whereNotIn('id', $seededIds)
            ->get(['id', 'name', 'served_towns']);

        foreach ($others as $location) {
            foreach ($location->served_towns ?? [] as $town) {
                $key = $this->nameKey((string) ($town['name'] ?? ''), (string) ($town['state'] ?? ''));
                if ($key !== '|') {
                    $owners[$key] = (string) $location->name;
                }
            }
        }

        return $owners;
    }

    private function nameKey(string $name, string $state): string
    {
        return mb_strtolower(trim($name)).'|'.strtoupper(trim($state));
    }
}
