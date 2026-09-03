<?php

use App\Enums\PageType;
use App\Filament\Pages\Operate\OperateTierProgression;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operate\TierProgression;
use Livewire\Livewire;

const TP_HOME = 'https://spg.example';

function tpCoverage(Site $site, string $name, ?string $tier, string $marketId, string $geo): void
{
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => $geo, 'name' => $name, 'size_tier' => $tier,
        'population' => 20000, 'source' => 'county', 'source_location_ids' => [$marketId], 'page_selected' => true,
    ]);
}

function tpTownPage(Site $site, string $name, string $marketId, string $slug, bool $indexed = false): Content
{
    $page = Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $marketId, 'primary_service_id' => null, 'title' => $name, 'slug' => $slug,
    ]);
    if ($indexed) {
        $url = TP_HOME.'/'.$slug;
        PageIndexState::create(['site_id' => $site->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url), 'index_verdict' => 'PASS']);
    }

    return $page;
}

it('groups towns by market and tier band with built/served/indexed, state, pills, and inbound counts', function () {
    $site = Site::factory()->create(['domain_url' => TP_HOME]);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    // A published location landing hub → the internal-link graph grids it down to every town it parents.
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => $market->id, 'title' => 'Newark', 'slug' => 'newark']);

    tpCoverage($site, 'Big', 'large', $market->id, 'B1');
    tpCoverage($site, 'Mid', 'medium', $market->id, 'M1');
    tpTownPage($site, 'Big', $market->id, 'big', indexed: true);
    tpTownPage($site, 'Mid', $market->id, 'mid'); // not indexed → the market's problem

    $markets = app(TierProgression::class)->forSite($site);

    expect($markets)->toHaveCount(1);
    $m = $markets[0];
    expect($m['built'])->toBe(2)
        ->and($m['served'])->toBe(2)
        ->and($m['problem_count'])->toBe(1)
        ->and($m['has_problem'])->toBeTrue();

    $large = collect($m['tiers'])->firstWhere('tier', 'large');
    $medium = collect($m['tiers'])->firstWhere('tier', 'medium');

    expect($large['built'])->toBe(1)->and($large['indexed'])->toBe(1)->and($large['state'])->toBe('complete')
        ->and($large['towns'][0]['index_state'])->toBe('indexed')
        ->and($large['towns'][0]['inbound_links'])->toBeGreaterThanOrEqual(1) // the landing hub grids to it
        ->and($medium['built'])->toBe(1)->and($medium['indexed'])->toBe(0)->and($medium['state'])->toBe('indexing')
        ->and($medium['towns'][0]['index_state'])->toBe('unknown');
});

it('shows a locked band with its unlock condition when the tier above is not indexed and nothing is built', function () {
    $site = Site::factory()->create(['domain_url' => TP_HOME]);
    $market = Location::factory()->for($site)->create(['name' => 'Trenton']);

    tpCoverage($site, 'BigTown', 'large', $market->id, 'L1');
    tpCoverage($site, 'MidTown', 'medium', $market->id, 'M1'); // served, but no built medium page
    tpTownPage($site, 'BigTown', $market->id, 'bigtown'); // large built, NOT indexed → locks medium

    $m = app(TierProgression::class)->forSite($site)[0];
    $medium = collect($m['tiers'])->firstWhere('tier', 'medium');

    expect($medium['built'])->toBe(0)
        ->and($medium['served'])->toBe(1)
        ->and($medium['state'])->toBe('locked')
        ->and($medium['unlock'])->toContain('Large');
});

it('sorts markets with a problem to the top', function () {
    $site = Site::factory()->create(['domain_url' => TP_HOME]);
    $clean = Location::factory()->for($site)->create(['name' => 'Clean']);
    $problem = Location::factory()->for($site)->create(['name' => 'Problem']);

    tpCoverage($site, 'C1', 'major', $clean->id, 'C1');
    tpTownPage($site, 'C1', $clean->id, 'c1', indexed: true); // clean market: all indexed
    tpCoverage($site, 'P1', 'major', $problem->id, 'P1');
    tpTownPage($site, 'P1', $problem->id, 'p1'); // problem market: not indexed

    $markets = app(TierProgression::class)->forSite($site);

    expect($markets[0]['name'])->toBe('Problem')
        ->and($markets[0]['has_problem'])->toBeTrue()
        ->and($markets[1]['has_problem'])->toBeFalse();
});

it('renders the tier-progression page for an operator', function () {
    $this->actingAs(User::factory()->create()); // operator by default
    $site = Site::factory()->create(['domain_url' => TP_HOME]);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    tpCoverage($site, 'Big', 'large', $market->id, 'B1');
    tpTownPage($site, 'Big', $market->id, 'big', indexed: true);

    Livewire::test(OperateTierProgression::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Newark')
        ->assertSee('Big');
});
