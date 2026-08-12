<?php

use App\Enums\MunicipalityType;
use App\Enums\SizeTier;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\JobCapture\Geography\GeographyResolver;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobCounty;
use App\Models\Site;

/** Bind a canned Census stack: Bedminster (MCD) in Somerset County, NJ, with a mocked ACS population. */
function bindGeography(int $population = 24_000): void
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(
        municipalities: [new Municipality('3403500100', 'Bedminster', MunicipalityType::CountySubdivision, 'NJ', 40.66, -74.65)],
        counties: [new County('34035', 'Somerset County', '34', '035')],
    ));

    $pop = Mockery::mock(CensusPopulation::class);
    $pop->shouldReceive('forCounty')->andReturn(['3403500100' => $population]);
    app()->instance(CensusPopulation::class, $pop);
}

test('it resolves a job to canonical city + county with population, tier, and stored jitter', function () {
    bindGeography(24_000);
    $job = Job::factory()->create([
        'site_id' => Site::factory()->create()->id,
        'lat_true' => 40.665, 'lng_true' => -74.655,
        'lat_jittered' => null, 'lng_jittered' => null,
    ]);

    app(GeographyResolver::class)->resolve($job);
    $job->refresh();

    $city = JobCity::find($job->job_city_id);
    $county = JobCounty::find($job->job_county_id);

    expect($job->lat_jittered)->not->toBeNull()
        ->and($job->lng_jittered)->not->toBeNull()
        ->and($city->place_geoid)->toBe('3403500100')
        ->and($city->population)->toBe(24_000)
        ->and($city->size_tier)->toBe(SizeTier::Medium)
        ->and($city->slug)->toBe('bedminster-nj')
        ->and($city->county->is($county))->toBeTrue()
        ->and($county->county_geoid)->toBe('34035')
        ->and($county->slug)->toBe('somerset-county-nj');
});

test('it is idempotent — never re-jitters and never duplicates registry rows', function () {
    bindGeography();
    $job = Job::factory()->create([
        'site_id' => Site::factory()->create()->id,
        'lat_true' => 40.665, 'lng_true' => -74.655,
        'lat_jittered' => 40.0, 'lng_jittered' => -74.0,   // already jittered — must be preserved
    ]);

    app(GeographyResolver::class)->resolve($job);
    $job->refresh();
    expect((float) $job->lat_jittered)->toBe(40.0)
        ->and((float) $job->lng_jittered)->toBe(-74.0);

    app(GeographyResolver::class)->resolve($job);

    expect(JobCity::count())->toBe(1)
        ->and(JobCounty::count())->toBe(1);
});

test('a job with no true point is a no-op', function () {
    bindGeography();
    $job = Job::factory()->create([
        'site_id' => Site::factory()->create()->id,
        'lat_true' => null, 'lng_true' => null,
    ]);

    app(GeographyResolver::class)->resolve($job);

    expect($job->refresh()->job_city_id)->toBeNull()
        ->and(JobCity::count())->toBe(0)
        ->and(JobCounty::count())->toBe(0);
});
