<?php

use App\Enums\BuildSource;
use App\Enums\PageType;
use App\Locations\TownPageGeoAnchor;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;

function tpaTown(Site $site, string $title, string $marketId): Content
{
    return Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $marketId, 'primary_service_id' => null, 'title' => $title, 'slug' => strtolower($title),
    ]);
}

function tpaCoverage(Site $site, string $name, string $geoId, array $sourceLocationIds): void
{
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => $geoId, 'name' => $name, 'size_tier' => 'large',
        'population' => 30000, 'source_location_ids' => $sourceLocationIds,
    ]);
}

it('anchors only the unambiguous town pages and surfaces the rest — never guessing', function () {
    $site = Site::factory()->create();
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    $other = Location::factory()->for($site)->create(['name' => 'Elsewhere']);

    // Unique: one 'Hoboken' coverage reachable from the market → anchorable.
    $hoboken = tpaTown($site, 'Hoboken', $market->id);
    tpaCoverage($site, 'Hoboken', '3401732250', [$market->id]);

    // Ambiguous: two 'Washington' coverage areas, both reachable → surfaced, not anchored.
    $washington = tpaTown($site, 'Washington', $market->id);
    tpaCoverage($site, 'Washington', '3401977000', [$market->id]);
    tpaCoverage($site, 'Washington', '3402577000', [$market->id]);

    // Unreachable: 'Trenton' coverage exists but only reachable from a DIFFERENT market → surfaced.
    $trenton = tpaTown($site, 'Trenton', $market->id);
    tpaCoverage($site, 'Trenton', '3402174000', [$other->id]);

    // No coverage: no 'Nowhere' coverage → surfaced.
    $nowhere = tpaTown($site, 'Nowhere', $market->id);

    $anchor = app(TownPageGeoAnchor::class);
    $plan = $anchor->plan($site);

    expect($plan['total'])->toBe(4)
        ->and($plan['already'])->toBe(0)
        ->and($plan['anchorable'])->toHaveCount(1)
        ->and($plan['anchorable'][0])->toMatchArray(['page_id' => (string) $hoboken->id, 'geo_id' => '3401732250'])
        ->and($plan['ambiguous'])->toHaveCount(1)
        ->and($plan['ambiguous'][0]['page_id'])->toBe((string) $washington->id)
        ->and($plan['ambiguous'][0]['candidates'])->toHaveCount(2)
        ->and($plan['unreachable'])->toHaveCount(1)
        ->and($plan['unreachable'][0]['page_id'])->toBe((string) $trenton->id)
        ->and($plan['no_coverage'])->toHaveCount(1)
        ->and($plan['no_coverage'][0]['page_id'])->toBe((string) $nowhere->id);

    // Report-only: plan() writes nothing.
    expect($hoboken->fresh()->geo_id)->toBeNull();

    // Execute: only the unambiguous page is anchored; the surfaced ones stay null.
    expect($anchor->execute($site))->toBe(1);
    expect($hoboken->fresh()->geo_id)->toBe('3401732250')
        ->and($washington->fresh()->geo_id)->toBeNull()
        ->and($trenton->fresh()->geo_id)->toBeNull()
        ->and($nowhere->fresh()->geo_id)->toBeNull();

    // Re-run is idempotent: the anchored page is counted as already-anchored, not re-written.
    expect($anchor->plan($site)['already'])->toBe(1);
});

it('runs report-first by default (writes nothing) and writes under --execute', function () {
    $this->actingAs(User::factory()->create()); // operator by default
    $site = Site::factory()->create();
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    $page = tpaTown($site, 'Hoboken', $market->id);
    tpaCoverage($site, 'Hoboken', '3401732250', [$market->id]);

    $this->artisan('launchpad:anchor-town-pages', ['--site' => $site->id])
        ->expectsOutputToContain('Read-only')
        ->assertSuccessful();
    expect($page->fresh()->geo_id)->toBeNull(); // report-only wrote nothing

    $this->artisan('launchpad:anchor-town-pages', ['--site' => $site->id, '--execute' => true])
        ->expectsOutputToContain('anchored 1')
        ->assertSuccessful();
    expect($page->fresh()->geo_id)->toBe('3401732250');
});

it('anchors a page the plan built from its manifest entry — exactly, not by name', function () {
    $site = Site::factory()->create();
    $market = Location::factory()->for($site)->create(['name' => 'Doylestown']);

    // Two same-named towns under the SAME parent: by name this is ambiguous and must not be guessed.
    $washingtonA = CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400111111',
        'name' => 'Washington', 'population' => 9000, 'source_location_ids' => [$market->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400122222',
        'name' => 'Washington', 'population' => 8000, 'source_location_ids' => [$market->id]]);

    $page = tpaTown($site, 'Washington', $market->id);

    // Without the plan link, the name match is ambiguous — surfaced, never anchored.
    expect(app(TownPageGeoAnchor::class)->plan($site)['ambiguous'])->toHaveCount(1);

    // With it, the town is known: the manifest entry names the coverage area the page was built for.
    BuildPage::factory()->create([
        'site_id' => $site->id, 'source' => BuildSource::Location, 'page_key' => (string) $washingtonA->id,
        'title' => 'Washington', 'content_id' => $page->id,
    ]);

    $plan = app(TownPageGeoAnchor::class)->plan($site);
    expect($plan['ambiguous'])->toBeEmpty()
        ->and($plan['anchorable'])->toHaveCount(1)
        ->and($plan['anchorable'][0]['geo_id'])->toBe('3400111111')
        ->and($plan['anchorable'][0]['via'])->toBe('plan');

    expect(app(TownPageGeoAnchor::class)->execute($site))->toBe(1);
    expect($page->fresh()->geo_id)->toBe('3400111111');
});
