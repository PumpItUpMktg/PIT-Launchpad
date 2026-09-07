<?php

use App\Integrations\Local\LocalSignalProvider;
use App\Integrations\Local\LocalSignals;
use App\Integrations\Local\MockLocalSignalProvider;
use App\Locations\LocalRelevance;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;

/** A physical location whose GBP locality is $city, $state. */
function cityLocation(Site $site, string $city, string $state): Location
{
    return Location::factory()->create(['site_id' => $site->id, 'address_components' => [
        ['types' => ['locality'], 'long_name' => $city],
        ['types' => ['administrative_area_level_1'], 'short_name' => $state],
    ]]);
}

/** Build a covered town quickly. */
function town(Site $site, string $geo, string $tier, ?int $pop, bool $selected = false, string $source = 'county'): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id,
        'geo_id' => $geo,
        'name' => 'Town '.$geo,
        'size_tier' => $tier,
        'population' => $pop,
        'page_selected' => $selected,
        'source' => $source,
    ]);
}

test('the initial seed selects the major and large towns and leaves the rest in reserve', function () {
    $site = Site::factory()->create();
    town($site, '01', 'major', 60000);
    town($site, '02', 'large', 35000);
    town($site, '03', 'medium', 20000);
    town($site, '04', 'small', 5000);

    $seeded = app(LocalRelevance::class)->seedInitialSelection($site);

    expect($seeded)->toBe(2)
        ->and(CoverageArea::where('site_id', $site->id)->where('page_selected', true)->pluck('geo_id')->sort()->values()->all())
        ->toBe(['01', '02']);
});

test('the seed is a no-op once the pool has been curated', function () {
    $site = Site::factory()->create();
    town($site, '01', 'major', 60000, selected: true); // already curated
    town($site, '02', 'large', 35000);

    expect(app(LocalRelevance::class)->seedInitialSelection($site))->toBe(0)
        ->and(CoverageArea::where('site_id', $site->id)->where('page_selected', true)->count())->toBe(1);
});

test('the seed never selects a town that is a physical location\'s own city — its landing already covers it', function () {
    $site = Site::factory()->create();
    cityLocation($site, 'Downingtown', 'PA'); // this location already gets a "/downingtown-pa/" landing

    // A large town that IS the location's own city — must NOT be selected (would duplicate the landing).
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'D1', 'name' => 'Downingtown', 'state' => 'PA', 'size_tier' => 'large', 'population' => 40000, 'page_selected' => false, 'source' => 'county']);
    // A different large town — selected as normal.
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'D2', 'name' => 'Exton', 'state' => 'PA', 'size_tier' => 'large', 'population' => 30000, 'page_selected' => false, 'source' => 'county']);

    $seeded = app(LocalRelevance::class)->seedInitialSelection($site);

    expect($seeded)->toBe(1)
        ->and(CoverageArea::where('site_id', $site->id)->where('page_selected', true)->pluck('name')->all())->toBe(['Exton']);
});

test('the drip never graduates a town that is a physical location\'s own city, even at a passing score', function () {
    $site = Site::factory()->create();
    cityLocation($site, 'Downingtown', 'PA');
    $selfCity = CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'D1', 'name' => 'Downingtown', 'state' => 'PA', 'size_tier' => 'medium', 'population' => 20000, 'page_selected' => false, 'source' => 'county']);

    /** @var MockLocalSignalProvider $provider */
    $provider = app(LocalSignalProvider::class);
    // A signal profile that clears the threshold — only the self-city guard keeps it out.
    $provider->set($site->id, 'D1', new LocalSignals('D1', 20000, competitorDensity: 0.0, marketReviewIndex: 1.0, demandIndex: 1.0));

    expect(app(LocalRelevance::class)->dripGraduate($site))->toBe(0)
        ->and($selfCity->refresh()->page_selected)->toBeFalse();
});

test('the drip graduates a reserve town that earns enough local relevance and skips a weak one', function () {
    $site = Site::factory()->create();
    $strong = town($site, '10', 'medium', 20000);
    $weak = town($site, '11', 'medium', 20000);

    /** @var MockLocalSignalProvider $provider */
    $provider = app(LocalSignalProvider::class);
    $provider->set($site->id, '10', new LocalSignals('10', 20000, competitorDensity: 0.0, marketReviewIndex: 1.0, demandIndex: 1.0));
    $provider->set($site->id, '11', new LocalSignals('11', 20000, competitorDensity: 1.0, marketReviewIndex: 0.0, demandIndex: 0.0));

    $graduated = app(LocalRelevance::class)->dripGraduate($site);

    expect($graduated)->toBe(1)
        ->and($strong->refresh()->page_selected)->toBeTrue()
        ->and($weak->refresh()->page_selected)->toBeFalse();
});

test('manual priority towns are never touched by the seed or the drip', function () {
    $site = Site::factory()->create();
    $manual = town($site, '20', 'small', 4000, selected: true, source: 'manual');
    town($site, '21', 'major', 60000);

    $relevance = app(LocalRelevance::class);
    $relevance->seedInitialSelection($site); // major '21' selected; manual untouched
    $relevance->dripGraduate($site);

    expect($manual->refresh()->page_selected)->toBeTrue()
        ->and($manual->source)->toBe('manual');
});

test('the same town scores differently for two different businesses', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    town($a, '30', 'medium', 20000);
    town($b, '30', 'medium', 20000);

    $relevance = app(LocalRelevance::class);
    $rowA = collect($relevance->forSite($a))->firstWhere('geo_id', '30');
    $rowB = collect($relevance->forSite($b))->firstWhere('geo_id', '30');

    // Per-business signals — the whole point: no two sites get identical local data. Compare the full
    // signal profile (not one dimension), so distinctness holds even against an astronomically-rare
    // single-value hash collision.
    $profile = fn (array $row): array => [
        $row['signals']->demandIndex,
        $row['signals']->competitorDensity,
        $row['signals']->marketReviewIndex,
    ];
    expect($profile($rowA))->not->toBe($profile($rowB));
});

test('the read-model carries score, readiness, and selection state per town', function () {
    $site = Site::factory()->create();
    town($site, '40', 'major', 60000, selected: true);

    $row = collect(app(LocalRelevance::class)->forSite($site))->firstWhere('geo_id', '40');

    expect($row['selected'])->toBeTrue()
        ->and($row['score'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
        ->and($row)->toHaveKeys(['tier', 'population', 'ready', 'manual', 'signals']);
});
