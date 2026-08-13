<?php

use App\JobCapture\Review\JobStorefrontResolver;
use App\Models\Job;
use App\Models\JobCounty;
use App\Models\Location;
use App\Models\Site;

function makeJobInCounty(Site $site, ?JobCounty $county): Job
{
    return Job::factory()->create([
        'site_id' => $site->id,
        'job_county_id' => $county?->id,
    ]);
}

it('resolves the storefront whose served counties cover the job’s county', function () {
    $site = Site::factory()->create();
    $county = JobCounty::factory()->create(['county_geoid' => '34001']);

    $storefront = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Ocean City Shop', 'is_storefront' => true,
        'county_geoids' => ['34001', '34009'],
    ]);
    // A non-storefront location must never be picked, even if it serves the county.
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Mobile Unit', 'is_storefront' => false, 'county_geoids' => ['34001'],
    ]);

    $resolver = app(JobStorefrontResolver::class);
    $name = $resolver->resolve(makeJobInCounty($site, $county), $resolver->storefronts($site->id));

    expect($name)->toBe('Ocean City Shop');
});

it('matches a storefront by its own home county', function () {
    $site = Site::factory()->create();
    $county = JobCounty::factory()->create(['county_geoid' => '34005']);
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'HQ', 'is_storefront' => true,
        'home_county_geoid' => '34005', 'county_geoids' => [],
    ]);

    $resolver = app(JobStorefrontResolver::class);
    expect($resolver->resolve(makeJobInCounty($site, $county), $resolver->storefronts($site->id)))->toBe('HQ');
});

it('returns null when no storefront covers the county, or the job has no county', function () {
    $site = Site::factory()->create();
    $county = JobCounty::factory()->create(['county_geoid' => '34999']);
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Elsewhere', 'is_storefront' => true, 'county_geoids' => ['34001'],
    ]);

    $resolver = app(JobStorefrontResolver::class);
    $storefronts = $resolver->storefronts($site->id);

    expect($resolver->resolve(makeJobInCounty($site, $county), $storefronts))->toBeNull()
        ->and($resolver->resolve(makeJobInCounty($site, null), $storefronts))->toBeNull();
});
