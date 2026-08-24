<?php

use App\Enums\GeoIntent;
use App\Enums\GeoPromptSource;
use App\Geo\GeoPromptSeeder;
use App\Models\GeoPrompt;
use App\Models\Market;
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

/** @return Collection<int, GeoPrompt> */
function geoPrompts(Site $site)
{
    return GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
}

it('auto-seeds the service × market × intent matrix, tagged with dimensions', function () {
    config(['launchpad.geo.seed.max_prompts' => 100, 'launchpad.geo.seed.max_markets' => 5]);
    $site = geoSeedSite();
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair']);
    $priority = Market::factory()->priority()->create(['site_id' => $site->id, 'name' => 'Union', 'region' => 'NJ']);

    $r = app(GeoPromptSeeder::class)->seed($site);

    // 4 geo intents × 1 market + 2 non-geo (reviews + how_to) = 6.
    expect($r)->toMatchArray(['created' => 6, 'skipped' => 0, 'services' => 1, 'markets' => 1]);

    $prompts = geoPrompts($site);
    expect($prompts)->toHaveCount(6);

    $hire = $prompts->first(fn (GeoPrompt $p) => $p->intent === GeoIntent::Hire);
    expect($hire->prompt)->toBe('Who is the best sump pump repair company in Union, NJ?')
        ->and($hire->source)->toBe(GeoPromptSource::Auto)
        ->and($hire->service_id)->toBe($svc->id)
        ->and($hire->market_id)->toBe($priority->id)
        ->and($hire->active)->toBeTrue();

    // Non-geo how-to has no market; reviews names the brand.
    expect($prompts->first(fn (GeoPrompt $p) => $p->intent === GeoIntent::HowTo)->market_id)->toBeNull()
        ->and($prompts->first(fn (GeoPrompt $p) => $p->intent === GeoIntent::Reviews)->prompt)->toContain('Sump Pump Gurus');
});

it('is idempotent — re-seeding adds nothing new', function () {
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id]);
    Market::factory()->priority()->create(['site_id' => $site->id]);

    app(GeoPromptSeeder::class)->seed($site);
    $second = app(GeoPromptSeeder::class)->seed($site);

    expect($second['created'])->toBe(0)->and($second['skipped'])->toBeGreaterThan(0);
});

it('respects the max-prompts cap', function () {
    config(['launchpad.geo.seed.max_prompts' => 3, 'launchpad.geo.seed.max_markets' => 5]);
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id]);
    Market::factory()->priority()->create(['site_id' => $site->id]);

    expect(app(GeoPromptSeeder::class)->seed($site)['created'])->toBe(3);
});

it('caps markets, priority tier first', function () {
    config(['launchpad.geo.seed.max_markets' => 1, 'launchpad.geo.seed.max_prompts' => 100]);
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $priority = Market::factory()->priority()->create(['site_id' => $site->id, 'name' => 'Prio']);
    Market::factory()->coverage()->create(['site_id' => $site->id, 'name' => 'Cov']);

    $r = app(GeoPromptSeeder::class)->seed($site);

    expect($r['markets'])->toBe(1)
        ->and(geoPrompts($site)->pluck('market_id')->filter()->unique()->values()->all())->toBe([$priority->id]);
});

it('skips brand reviews when the site has no brand name', function () {
    config(['launchpad.geo.seed.max_prompts' => 100, 'launchpad.geo.seed.max_markets' => 5]);
    $site = geoSeedSite('');
    Service::factory()->create(['site_id' => $site->id]);
    Market::factory()->priority()->create(['site_id' => $site->id]);

    $r = app(GeoPromptSeeder::class)->seed($site);

    // 4 geo × 1 market + 1 non-geo (how_to; reviews skipped) = 5.
    expect($r['created'])->toBe(5)
        ->and(geoPrompts($site)->contains(fn (GeoPrompt $p) => $p->intent === GeoIntent::Reviews))->toBeFalse();
});

it('the seed-geo-prompts command runs for a site', function () {
    $site = geoSeedSite();
    Service::factory()->create(['site_id' => $site->id]);
    Market::factory()->priority()->create(['site_id' => $site->id]);

    $this->artisan('sandhog:seed-geo-prompts', ['site' => $site->id])
        ->expectsOutputToContain('created')
        ->assertSuccessful();
});
