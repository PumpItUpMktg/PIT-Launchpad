<?php

use App\Enums\GeoIntent;
use App\Enums\GeoPromptSource;
use App\Geo\GeoPromptSeeder;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Collection;

afterEach(fn () => CurrentSite::clear());

function geoSeedSite(string $brand = 'Sump Pump Gurus'): Site
{
    return Site::factory()->create(['brand_name' => $brand]);
}

function geoTown(Site $site, string $name = 'Union', string $tier = 'major', int $pop = 60000, bool $selected = true): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => 'NJ',
        'size_tier' => $tier, 'population' => $pop, 'page_selected' => $selected,
    ]);
}

/** @return Collection<int, GeoPrompt> */
function geoPrompts(Site $site)
{
    return GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
}

it('auto-seeds the service × town × intent matrix, tagged with dimensions', function () {
    config(['launchpad.geo.seed.max_prompts' => 100, 'launchpad.geo.seed.max_towns' => 40]);
    $site = geoSeedSite();
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair']);
    $town = geoTown($site, 'Union');

    $r = app(GeoPromptSeeder::class)->seed($site);

    // 4 geo intents × 1 town + 2 non-geo (reviews + how_to) = 6.
    expect($r)->toMatchArray(['created' => 6, 'skipped' => 0, 'services' => 1, 'towns' => 1]);

    $prompts = geoPrompts($site);
    expect($prompts)->toHaveCount(6);

    $hire = $prompts->first(fn (GeoPrompt $p) => $p->intent === GeoIntent::Hire);
    expect($hire->prompt)->toBe('Who is the best sump pump repair company in Union, NJ?')
        ->and($hire->source)->toBe(GeoPromptSource::Auto)
        ->and($hire->service_id)->toBe($svc->id)
        ->and($hire->coverage_area_id)->toBe($town->id)
        ->and($hire->size_tier?->value)->toBe('major')
        ->and($hire->active)->toBeTrue();

    // Non-geo how-to has no town; reviews names the brand.
    expect($prompts->first(fn (GeoPrompt $p) => $p->intent === GeoIntent::HowTo)->coverage_area_id)->toBeNull()
        ->and($prompts->first(fn (GeoPrompt $p) => $p->intent === GeoIntent::Reviews)->prompt)->toContain('Sump Pump Gurus');
});

it('is idempotent — re-seeding adds nothing new', function () {
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id]);
    geoTown($site);

    app(GeoPromptSeeder::class)->seed($site);
    $second = app(GeoPromptSeeder::class)->seed($site);

    expect($second['created'])->toBe(0)->and($second['skipped'])->toBeGreaterThan(0);
});

it('respects the max-prompts cap', function () {
    config(['launchpad.geo.seed.max_prompts' => 3, 'launchpad.geo.seed.max_towns' => 40]);
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id]);
    geoTown($site);

    expect(app(GeoPromptSeeder::class)->seed($site)['created'])->toBe(3);
});

it('caps towns, biggest first', function () {
    config(['launchpad.geo.seed.max_towns' => 1, 'launchpad.geo.seed.max_prompts' => 100]);
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $major = geoTown($site, 'BigCity', 'major', 80000);
    geoTown($site, 'SmallBoro', 'small', 4000);

    $r = app(GeoPromptSeeder::class)->seed($site);

    expect($r['towns'])->toBe(1)
        ->and(geoPrompts($site)->pluck('coverage_area_id')->filter()->unique()->values()->all())->toBe([$major->id]);
});

it('scopes seeding to one brick-and-mortar shop', function () {
    config(['launchpad.geo.seed.max_towns' => 40, 'launchpad.geo.seed.max_prompts' => 100]);
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $shopA = 'loc-a';
    $townA = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Ashop', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true, 'source_location_ids' => [$shopA]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Belsewhere', 'state' => 'MD', 'size_tier' => 'major', 'population' => 55000, 'page_selected' => true, 'source_location_ids' => ['loc-b']]);

    app(GeoPromptSeeder::class)->seed($site, $shopA);

    // Only shop A's town is seeded — the other shop's town (e.g. an MD area) is left out.
    expect(geoPrompts($site)->pluck('coverage_area_id')->filter()->unique()->values()->all())->toBe([$townA->id]);
});

it('only seeds published towns (page_selected)', function () {
    config(['launchpad.geo.seed.max_towns' => 40, 'launchpad.geo.seed.max_prompts' => 100]);
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $published = geoTown($site, 'Published', 'major', 60000, selected: true);
    geoTown($site, 'Unpublished', 'major', 60000, selected: false);

    app(GeoPromptSeeder::class)->seed($site);

    expect(geoPrompts($site)->pluck('coverage_area_id')->filter()->unique()->values()->all())->toBe([$published->id]);
});

it('skips brand reviews when the site has no brand name', function () {
    config(['launchpad.geo.seed.max_prompts' => 100, 'launchpad.geo.seed.max_towns' => 40]);
    $site = geoSeedSite('');
    Service::factory()->create(['site_id' => $site->id]);
    geoTown($site);

    $r = app(GeoPromptSeeder::class)->seed($site);

    // 4 geo × 1 town + 1 non-geo (how_to; reviews skipped) = 5.
    expect($r['created'])->toBe(5)
        ->and(geoPrompts($site)->contains(fn (GeoPrompt $p) => $p->intent === GeoIntent::Reviews))->toBeFalse();
});

it('the seed-geo-prompts command runs for a site', function () {
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id]);
    geoTown($site);

    $this->artisan('sandhog:seed-geo-prompts', ['site' => $site->id])
        ->expectsOutputToContain('created')
        ->assertSuccessful();
});
