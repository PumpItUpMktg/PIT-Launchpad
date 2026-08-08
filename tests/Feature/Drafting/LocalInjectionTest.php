<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\ContentEngine\Drafting\LocalInjectionPolicy;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\MunicipalityType;
use App\Models\CoverageArea;
use App\Models\Location;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function storefront(string $siteId, string $city, string $countyGeoid, string $countyName, float $lat, float $lng, ?string $id = null): Location
{
    return Location::factory()->create(array_filter([
        'id' => $id,
        'site_id' => $siteId, 'is_storefront' => true, 'home_county_geoid' => $countyGeoid,
        'lat' => $lat, 'lng' => $lng,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => $city],
            ['types' => ['administrative_area_level_2'], 'long_name' => $countyName],
            ['types' => ['administrative_area_level_1'], 'short_name' => 'NJ'],
        ],
    ], fn ($v) => $v !== null));
}

function servingCity(string $siteId, string $name, string $countyGeoid, float $lat, float $lng): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $siteId, 'name' => $name, 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => $countyGeoid.fake()->unique()->numerify('#####'), 'lat' => $lat, 'lng' => $lng,
    ]);
}

function reactiveRequest(string $siteId, string $sourceUrl = 'https://news/1'): DraftRequest
{
    return new DraftRequest(
        siteId: $siteId, kind: ContentKind::Post, intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News, title: 'Storm prep', sourceName: 'Local Tribune',
        sourceUrl: $sourceUrl, localRelevance: true,
    );
}

test('a locally-relevant reactive draft anchors to the storefront and offers one same-county story town', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    storefront($site->id, 'Montclair', '34013', 'Essex County', 40.83, -74.21);
    servingCity($site->id, 'Verona', '34013', 40.83, -74.24);       // Essex → eligible (spoke)
    servingCity($site->id, 'Downingtown', '42029', 40.00, -75.70);  // Chester Co, PA → must never be offered

    $claude = new FakeClaudeClient(Draft::post($claim->id, ['towns' => ['Verona']]));
    $content = DraftingHarness::engine($claude)->run(reactiveRequest($site->id))->content;

    expect($claude->prompts[0])
        ->toContain('Montclair')                             // brick-and-mortar anchor
        ->toContain('Verona')                                // same-county story town
        ->toContain('Essex County')                          // county framing
        ->toContain('ONLY if the story genuinely applies')   // optional, never forced
        ->not->toContain('Downingtown');                     // cross-county drift excluded
    expect($content->meta['towns'])->toContain('Verona');
});

test('anchors to the storefront alone when no serving city shares its county', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    storefront($site->id, 'Doylestown', '42017', 'Bucks County', 40.31, -75.13);
    servingCity($site->id, 'Verona', '34013', 40.83, -74.24); // different county → not eligible

    $claude = new FakeClaudeClient(Draft::post($claim->id, ['towns' => []]));
    DraftingHarness::engine($claude)->run(reactiveRequest($site->id));

    expect($claude->prompts[0])
        ->toContain('Doylestown')
        ->toContain('tie it back to our Doylestown location')
        ->not->toContain('Verona');
});

test('the anchor rotates across storefronts, deterministic per post', function () {
    ['site' => $site] = DraftingHarness::fixture();
    storefront($site->id, 'Montclair', '34013', 'Essex County', 40.83, -74.21, '00000000000000000000000001');
    storefront($site->id, 'Doylestown', '42017', 'Bucks County', 40.31, -75.13, '00000000000000000000000002');

    // Seeds whose crc32 parity differs land on different storefronts (ordered by id: Montclair, Doylestown).
    $even = collect(range(1, 60))->map(fn ($i) => "s$i")->first(fn ($s) => crc32($s) % 2 === 0);
    $odd = collect(range(1, 60))->map(fn ($i) => "s$i")->first(fn ($s) => crc32($s) % 2 === 1);

    $policy = app(LocalInjectionPolicy::class);
    expect($policy->angleFor(reactiveRequest($site->id, $even))->anchorTown)->toBe('Montclair')
        ->and($policy->angleFor(reactiveRequest($site->id, $odd))->anchorTown)->toBe('Doylestown')
        // Same seed is stable.
        ->and($policy->angleFor(reactiveRequest($site->id, $even))->anchorTown)->toBe('Montclair');
});

test('a directed (evergreen) draft is kept town-agnostic even with a storefront', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    storefront($site->id, 'Montclair', '34013', 'Essex County', 40.83, -74.21);

    $claude = new FakeClaudeClient(Draft::post($claim->id));
    $request = new DraftRequest(
        siteId: $site->id, kind: ContentKind::Post, intakeType: IntakeType::Directed,
        trigger: DraftTrigger::Gap, title: 'How sump pumps work', localRelevance: false,
    );
    DraftingHarness::engine($claude)->run($request);

    expect($claude->prompts[0])->toContain('town-agnostic')->not->toContain('Montclair');
});

test('a reactive draft without local relevance is not localized', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    storefront($site->id, 'Montclair', '34013', 'Essex County', 40.83, -74.21);

    $claude = new FakeClaudeClient(Draft::post($claim->id));
    $request = new DraftRequest(
        siteId: $site->id, kind: ContentKind::Post, intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News, title: 'National rebate news', sourceName: 'Wire', localRelevance: false,
    );
    DraftingHarness::engine($claude)->run($request);

    expect($claude->prompts[0])->toContain('town-agnostic')->not->toContain('Montclair');
});
