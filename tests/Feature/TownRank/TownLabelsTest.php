<?php

use App\Enums\MunicipalityType;
use App\GeoGrid\CoverageGrid;
use App\Models\CoverageArea;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\TownLabels;
use App\TownRank\TownRankPoints;
use App\TownRank\TownRankScanner;
use Illuminate\Support\Facades\Http;

it('qualifies same-named towns by county (registry name, else FIPS, else "place") and leaves unique names alone', function () {
    JobCounty::query()->create(['county_geoid' => '42095', 'state_fips' => '42', 'name' => 'Northampton County', 'state' => 'PA', 'slug' => 'northampton-county-pa']);

    $labels = app(TownLabels::class)->for([
        ['id' => 'a', 'name' => 'Bethlehem', 'state' => 'PA', 'geo_id' => '4209506088'],   // Northampton (known)
        ['id' => 'b', 'name' => 'Bethlehem', 'state' => 'PA', 'geo_id' => '4207706088'],   // Lehigh (unknown to the registry)
        ['id' => 'c', 'name' => 'Bethlehem', 'state' => 'PA', 'geo_id' => '4200000001'],   // fixture: a 10-digit id in an unknown county
        ['id' => 'd', 'name' => 'Washington', 'state' => 'NJ', 'geo_id' => '3477510'],     // 7-digit place id — no county
        ['id' => 'e', 'name' => 'washington', 'state' => 'NJ', 'geo_id' => '3402777510'],  // case-insensitive duplicate
        ['id' => 'f', 'name' => 'Bethlehem', 'state' => 'NJ', 'geo_id' => '3401905230'],   // different state → its own group, unique
        ['id' => 'g', 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590'],
    ]);

    expect($labels['a'])->toBe('Bethlehem (Northampton)')
        ->and($labels['b'])->toBe('Bethlehem (county 077)')
        ->and($labels['c'])->toBe('Bethlehem (county 000)')
        ->and($labels['d'])->toBe('Washington (place)')
        ->and($labels['e'])->toBe('washington (county 027)')
        ->and($labels['f'])->toBe('Bethlehem')
        ->and($labels['g'])->toBe('Hackettstown');
});

it('drops Census placeholder and unpopulated rows from the point set, keeps the bare name for queries, and labels the rest', function () {
    $ids = collect(['t-0', 't-1', 't-2'])->map(fn ($i): string => $i);
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
    ]);
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.6, 'lng' => -75.4]);
    $mk = fn (string $name, ?int $pop, string $geo, float $lat, ?string $state = 'PA') => CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => $state, 'population' => $pop, 'geo_id' => $geo, 'lat' => $lat, 'lng' => -75.4,
        'source_location_ids' => [$loc->id], 'type' => strlen($geo) === 7 ? MunicipalityType::Place : MunicipalityType::CountySubdivision,
    ]);
    $mk('Bethlehem', 56000, '4209506088', 40.62);
    $mk('Bethlehem', 25000, '4207706088', 40.60);
    $mk('Easton', 28000, '4209522000', 40.69);
    $mk('County subdivisions not defined', 0, '4209500000', 40.50);   // Census placeholder
    $mk('South Orange', null, '3401369420', 40.75, 'NJ');            // unpopulated pseudo-area
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);

    $points = app(TownRankPoints::class)->forSite($site);
    expect(array_column($points, 'label'))->toBe(['Bethlehem (county 095)', 'Easton', 'Bethlehem (county 077)'])
        ->and(array_column($points, 'name'))->toBe(['Bethlehem', 'Easton', 'Bethlehem']);

    // The coverage grid drops the placeholder too (a wasted Maps task per scan otherwise); the unpopulated
    // pseudo-area is a town-rank exclusion only.
    expect(array_column(app(CoverageGrid::class)->pointsFor($loc), 'label'))->not->toContain('County subdivisions not defined')
        ->and(array_column(app(CoverageGrid::class)->pointsFor($loc), 'label'))->toContain('South Orange');

    // Queries are built from the bare name — never "Bethlehem (county 095)".
    $scan = app(TownRankScanner::class)->post($site, $kw, TownRankScan::MODE_TOWN_QUERY);
    expect($scan->points()->orderBy('label')->pluck('query')->all())->toBe(['sump pump service Bethlehem PA', 'sump pump service Bethlehem PA', 'sump pump service Easton PA'])
        ->and($scan->points()->pluck('label')->all())->toContain('Bethlehem (county 095)');
});
