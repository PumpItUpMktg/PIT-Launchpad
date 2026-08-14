<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Location;
use App\Models\Site;

/**
 * NJ gazetteer with two counties that SHARE a municipality name ("Washington Twp" in both Bergen and
 * Morris) — the cannibalization case the command must disambiguate — plus a pseudo-subdivision that
 * must be filtered. ACS populations drive the Large/Medium/Small tiering and the largest-first order.
 */
function njGazetteer(): void
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(
        counties: [
            new County('34003', 'Bergen', '34', '003'),
            new County('34027', 'Morris County', '34', '027'), // trailing "County" — normalization must strip it
        ],
        subdivisions: [
            '34:003' => [
                new Municipality('3400330000', 'Hackensack', MunicipalityType::CountySubdivision, 'NJ', 40.88, -74.04),
                new Municipality('3400363000', 'Ridgewood', MunicipalityType::CountySubdivision, 'NJ', 40.98, -74.11),
                new Municipality('3400377000', 'Washington Twp', MunicipalityType::CountySubdivision, 'NJ', 41.00, -74.06),
                new Municipality('3400399999', 'County subdivisions not defined', MunicipalityType::CountySubdivision, 'NJ', 0.0, 0.0),
            ],
            '34:027' => [
                new Municipality('3402745000', 'Morristown', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.48),
                new Municipality('3402777000', 'Washington Twp', MunicipalityType::CountySubdivision, 'NJ', 40.76, -74.79),
                new Municipality('3402707000', 'Boonton', MunicipalityType::CountySubdivision, 'NJ', 40.90, -74.40),
            ],
        ],
    ));
}

/** A CensusPopulation stub keyed "stateFips:countyFips" — no HTTP, no key. */
function njPopulation(): void
{
    $pop = new class extends CensusPopulation
    {
        /** @var array<string, array<string, int>> */
        public array $map = [
            '34:003' => ['3400330000' => 46030, '3400363000' => 25000, '3400377000' => 9000],
            '34:027' => ['3402745000' => 20000, '3402777000' => 18000, '3402707000' => 8000],
        ];

        public function __construct() {}

        public function forCounty(string $stateFips, string $countyFips): array
        {
            return $this->map["{$stateFips}:{$countyFips}"] ?? [];
        }
    };

    app()->instance(CensusPopulation::class, $pop);
}

/** @return list<array<string, mixed>> */
function townsOf(Site $site, string $name): array
{
    return (array) Location::withoutGlobalScopes()->where('site_id', $site->id)->where('name', $name)->value('served_towns');
}

function njCountySite(): Site
{
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Bergen County', 'lat' => null, 'lng' => null]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Morris', 'lat' => null, 'lng' => null]);

    return $site;
}

beforeEach(function () {
    njGazetteer();
    njPopulation();
});

it('seeds served_towns for each county location, tiered and largest-first', function () {
    $site = njCountySite();

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id])
        ->expectsOutputToContain('Seeded served_towns')
        ->assertSuccessful();

    $bergen = townsOf($site, 'Bergen County');

    // The pseudo-subdivision is filtered; three real towns remain, largest population first.
    expect($bergen)->toHaveCount(3)
        ->and($bergen[0]['name'])->toBe('Hackensack')          // 46,030 — leads
        ->and($bergen[0]['state'])->toBe('NJ')
        ->and($bergen[0]['geocoded'])->toBeTrue()
        ->and(array_column($bergen, 'name'))->not->toContain('County subdivisions not defined');
});

it('never writes county_geoids (no town-page explosion)', function () {
    $site = njCountySite();

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id])->assertSuccessful();

    $ids = Location::withoutGlobalScopes()->where('site_id', $site->id)->pluck('county_geoids');
    expect($ids->every(fn ($v): bool => $v === null))->toBeTrue();
});

it('disambiguates a town name shared across two counties with its county', function () {
    $site = njCountySite();

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id])
        ->expectsOutputToContain('Disambiguated')
        ->assertSuccessful();

    expect(array_column(townsOf($site, 'Bergen County'), 'name'))->toContain('Washington Twp (Bergen)')
        ->and(array_column(townsOf($site, 'Morris'), 'name'))->toContain('Washington Twp (Morris)');
});

it('reports the whole-portfolio tier split on its TOTAL line', function () {
    $site = njCountySite();

    // Bergen: Hackensack 46k large, Ridgewood 25k medium, Washington 9k small.
    // Morris: Morristown 20k medium, Washington 18k medium, Boonton 8k small.
    // Union: 0 major · 1 large · 3 medium · 2 small · 0 ungrouped.
    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id])
        ->expectsOutputToContain('0 major · 1 large · 3 medium · 2 small · 0 ungrouped')
        ->assertSuccessful();
});

it('--dry-run writes nothing', function () {
    $site = njCountySite();

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(townsOf($site, 'Bergen County'))->toBe([])
        ->and(townsOf($site, 'Morris'))->toBe([]);
});

it('--counties limits the seed to the requested subset', function () {
    $site = njCountySite();

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id, '--counties' => ['Bergen']])
        ->assertSuccessful();

    expect(townsOf($site, 'Bergen County'))->toHaveCount(3)
        ->and(townsOf($site, 'Morris'))->toBe([]); // untouched — outside the subset
});

it('skips a town already owned by a non-seeded location, never overwriting it', function () {
    $site = njCountySite();
    // A hand-built location that already claims Hackensack — not a county name, so it is never seeded.
    Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'Flagship',
        'served_towns' => [['name' => 'Hackensack', 'state' => 'NJ', 'lat' => 40.88, 'lng' => -74.04, 'geocoded' => true]],
    ]);

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id])
        ->expectsOutputToContain('already owned by a non-seeded location')
        ->assertSuccessful();

    // Bergen gets its other two towns, but Hackensack stays with Flagship.
    expect(array_column(townsOf($site, 'Bergen County'), 'name'))->not->toContain('Hackensack')
        ->and(townsOf($site, 'Bergen County'))->toHaveCount(2);
});

it('fails on an unknown site', function () {
    $this->artisan('launchpad:seed-served-towns', ['site' => 'nope'])->assertFailed();
});

it('accepts a 2-letter state code as well as a FIPS', function () {
    $site = njCountySite();

    $this->artisan('launchpad:seed-served-towns', ['site' => $site->id, '--state' => 'NJ'])
        ->assertSuccessful();

    expect(townsOf($site, 'Bergen County'))->toHaveCount(3);
});
