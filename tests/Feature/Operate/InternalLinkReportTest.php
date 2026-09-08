<?php

use App\Analytics\Gsc\Grain;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operate\InternalLinkReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const ILR_HOME = 'https://il.example';

function ilrTown(Site $site, Location $market, string $name, string $slug): Content
{
    // Empty body + slot_payload → visible text is just the title (thin) → word-scaled cap 0.
    return Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $market->id, 'primary_service_id' => null,
        'title' => $name, 'slug' => $slug, 'body' => '', 'slot_payload' => [],
    ]);
}

function ilrGscPosition(Site $site, string $slug, float $position): void
{
    $date = now()->subDays(2)->toDateString();
    $url = ILR_HOME.'/'.trim($slug, '/').'/';
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, $date, $url]), 'date' => $date, 'url' => $url,
        'impressions' => 100, 'clicks' => 5, 'ctr' => 0, 'position' => $position,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ilrReport(Site $site): array
{
    return app(InternalLinkReport::class)->forSite($site);
}

it('reports the plan spine shape: breakdown, reciprocals, over-cap and top-3 targets', function () {
    $site = Site::factory()->create(['domain_url' => ILR_HOME]);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);

    // Market landing (location_id set) + Areas directory page — the two structural hub sources.
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => $market->id,
        'title' => 'Newark', 'slug' => 'newark', 'body' => '', 'slot_payload' => [],
    ]);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Utility, 'standard_type' => StandardPageType::AreasWeServe,
        'title' => 'Areas', 'slug' => 'areas-we-serve', 'body' => '', 'slot_payload' => [],
    ]);

    // Two same-tier towns, indexed, ~1.4mi apart → a mesh neighbour pair (A→B and B→A).
    $alpha = ilrTown($site, $market, 'Alpha', 'alpha');
    $bravo = ilrTown($site, $market, 'Bravo', 'bravo');
    foreach ([['Alpha', 40.70, $alpha], ['Bravo', 40.72, $bravo]] as [$name, $lat, $page]) {
        CoverageArea::factory()->create([
            'site_id' => $site->id, 'geo_id' => '340230'.($name === 'Alpha' ? '01' : '02'), 'name' => $name,
            'size_tier' => 'major', 'lat' => $lat, 'lng' => -74.50, 'source_location_ids' => [$market->id],
        ]);
        PageIndexState::create([
            'site_id' => $site->id, 'content_id' => $page->id,
            'url' => ILR_HOME.'/'.strtolower($name), 'url_normalized' => ILR_HOME.'/'.strtolower($name),
            'index_verdict' => 'PASS',
        ]);
    }

    ilrGscPosition($site, 'alpha', 2.0); // Alpha already ranks top 3 → needs no link

    $plan = ilrReport($site)['plan'];

    // Six edges: landing→{A,B} (market), areas→{A,B} (areas), A↔B (mesh).
    expect($plan['breakdown']['market→town'])->toBe(2)
        ->and($plan['breakdown']['areas→town'])->toBe(2)
        ->and($plan['breakdown']['town→town'])->toBe(2)
        ->and($plan['by_source_type'])->toMatchArray(['market' => 2, 'areas' => 2, 'mesh' => 2])
        ->and($plan['reciprocal_pairs'])->toBe(1)          // Alpha ↔ Bravo — the link-wheel signature
        ->and($plan['top3_targets'])->toBe(1)              // Alpha (GSC position 2)
        ->and($plan['distinct_targets'])->toBe(2)
        ->and($plan['over_cap_count'])->toBeGreaterThanOrEqual(1); // thin pages → cap 0, every source exceeds
});

it('is read-only — proposes and persists nothing', function () {
    $site = Site::factory()->create(['domain_url' => ILR_HOME]);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => $market->id, 'title' => 'Newark', 'slug' => 'newark',
    ]);
    ilrTown($site, $market, 'Alpha', 'alpha');
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3402301', 'name' => 'Alpha', 'size_tier' => 'major', 'source_location_ids' => [$market->id]]);

    ilrReport($site);

    expect(DB::table('link_plans')->count())->toBe(0)
        ->and(DB::table('link_plan_items')->count())->toBe(0);
});

it('runs the read-only command for an operator', function () {
    $this->actingAs(User::factory()->create()); // operator by default
    $site = Site::factory()->create(['domain_url' => ILR_HOME]);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => $market->id, 'title' => 'Newark', 'slug' => 'newark',
    ]);
    ilrTown($site, $market, 'Alpha', 'alpha');
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3402301', 'name' => 'Alpha', 'size_tier' => 'major', 'source_location_ids' => [$market->id]]);

    $this->artisan('launchpad:report-internal-links', ['--site' => $site->id])
        ->assertSuccessful()
        ->expectsOutputToContain('PLAN spine');
});
