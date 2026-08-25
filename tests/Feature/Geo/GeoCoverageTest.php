<?php

use App\Enums\GeoIntent;
use App\Geo\GeoCoverage;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;

afterEach(fn () => CurrentSite::clear());

function covTown(Site $site, string $name, string $tier, int $pop, array $locationIds = []): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'size_tier' => $tier,
        'population' => $pop, 'page_selected' => true, 'source_location_ids' => $locationIds,
    ]);
}

function covPrompt(Site $site, ?Service $service, ?CoverageArea $town, GeoIntent $intent): GeoPrompt
{
    return GeoPrompt::create([
        'site_id' => $site->id, 'service_id' => $service?->id, 'coverage_area_id' => $town?->id,
        'size_tier' => $town?->size_tier, 'intent' => $intent->value,
        'prompt' => 'q '.$intent->value.' '.($town?->name ?? '-'), 'active' => true,
    ]);
}

function covSnap(GeoPrompt $prompt, string $engine, bool $cited, array $competitors = []): void
{
    GeoSnapshot::create([
        'site_id' => $prompt->site_id, 'geo_prompt_id' => $prompt->id, 'engine' => $engine,
        'cited' => $cited, 'competitors' => $competitors, 'checked_at' => now(),
    ]);
}

it('builds the coverage matrix + gaps + summary from tagged prompts and snapshots', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $repair = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $install = Service::factory()->create(['site_id' => $site->id, 'name' => 'Install']);
    $union = covTown($site, 'Union', 'major', 60000);
    $clifton = covTown($site, 'Clifton', 'large', 35000);

    // Repair × Union: cited in one of two engines → strong (cited in any).
    $rep_union = covPrompt($site, $repair, $union, GeoIntent::Hire);
    covSnap($rep_union, 'claude', cited: true);
    covSnap($rep_union, 'perplexity', cited: false);

    // Install × Clifton: cited in NO engine, competitor named → weak + a gap.
    $ins_clifton = covPrompt($site, $install, $clifton, GeoIntent::Hire);
    covSnap($ins_clifton, 'claude', cited: false, competitors: ['Rival Plumbing']);
    covSnap($ins_clifton, 'perplexity', cited: false);

    // Repair × Clifton: has a prompt but no measurement → pending.
    covPrompt($site, $repair, $clifton, GeoIntent::Cost);

    // (Install × Union is untested — no prompt.)

    $r = app(GeoCoverage::class)->report($site);

    expect($r['cells'][$repair->id][$union->id]['state'])->toBe('strong')
        ->and($r['cells'][$repair->id][$union->id]['pct'])->toBe(100)
        ->and($r['cells'][$install->id][$clifton->id]['state'])->toBe('weak')
        ->and($r['cells'][$install->id][$clifton->id]['pct'])->toBe(0)
        ->and($r['cells'][$repair->id][$clifton->id]['state'])->toBe('pending')
        ->and($r['cells'][$install->id][$union->id] ?? null)->toBeNull();   // untested blind spot

    expect($r['summary'])->toMatchArray(['prompts' => 3, 'measured' => 2, 'cited' => 1, 'untested_cells' => 1, 'engines' => 2]);

    // The only absent-gap is Install × Clifton, with its competitor.
    expect($r['gaps'])->toHaveCount(1)
        ->and($r['gaps'][0])->toMatchArray(['service' => 'Install', 'town' => 'Clifton', 'tier' => 'large', 'intent' => 'Hire', 'engines_measured' => 2, 'competitors' => ['Rival Plumbing']]);

    // Columns: biggest town first (major before large).
    expect(array_column($r['columns'], 'name'))->toBe(['Union', 'Clifton']);
});

it('scopes the report to a single brick-and-mortar shop', function () {
    $site = Site::factory()->create();
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $shopA = 'loc-a';
    $shopB = 'loc-b';
    $townA = covTown($site, 'Ashopville', 'major', 60000, [$shopA]);
    $townB = covTown($site, 'Bshoptown', 'major', 55000, [$shopB]);
    covPrompt($site, $svc, $townA, GeoIntent::Hire);
    covPrompt($site, $svc, $townB, GeoIntent::Hire);

    // No shop → both towns.
    expect(array_column(app(GeoCoverage::class)->report($site)['columns'], 'name'))->toBe(['Ashopville', 'Bshoptown']);

    // Shop A → only its town + prompt; the columns carry the owning location.
    $scoped = app(GeoCoverage::class)->report($site, $shopA);
    expect(array_column($scoped['columns'], 'name'))->toBe(['Ashopville'])
        ->and($scoped['summary']['prompts'])->toBe(1)
        ->and($scoped['columns'][0]['location_id'])->toBe($shopA);
});

it('ranks absent-gaps biggest-town first, then by competitors', function () {
    $site = Site::factory()->create();
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $big = covTown($site, 'BigCity', 'major', 60000);
    $small = covTown($site, 'SmallBoro', 'small', 5000);

    $smallGap = covPrompt($site, $svc, $small, GeoIntent::Hire);      // small town
    covSnap($smallGap, 'claude', cited: false, competitors: ['A', 'B']);
    $bigGap = covPrompt($site, $svc, $big, GeoIntent::Hire);          // major town → ranks first
    covSnap($bigGap, 'claude', cited: false);

    $gaps = app(GeoCoverage::class)->report($site)['gaps'];

    expect($gaps)->toHaveCount(2)
        ->and($gaps[0]['town'])->toBe('BigCity')    // major beats small even with fewer competitors
        ->and($gaps[1]['town'])->toBe('SmallBoro');
});
