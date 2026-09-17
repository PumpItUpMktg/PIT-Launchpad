<?php

use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\ServiceAreasPage;
use App\Filament\Pages\TownRankPage;
use App\Jobs\GeneratePage;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Models\User;
use App\TownRank\TownRankPoints;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/** A scanned keyword over one town that has no page — the case the map's diagnosis says to build. */
function buildPageSite(): array
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.com', 'brand_name' => 'SPG']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Warrington', 'state' => 'PA', 'geo_id' => '4201781720',
        'population' => 25597, 'lat' => 40.25, 'lng' => -75.15, 'page_selected' => false, 'source_location_ids' => [$loc->id]]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete',
        'points_count' => 1, 'found_count' => 0, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $town->id, 'geo_id' => $town->geo_id,
        'label' => 'Warrington', 'state' => 'PA', 'lat' => 40.25, 'lng' => -75.15, 'query' => 'q', 'rank' => null, 'collected_at' => now()]);

    return ['site' => $site, 'keyword' => $kw, 'town' => $town];
}

it('offers the build button on a town with no page, and not on one that has a page', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = buildPageSite();

    Livewire::test(TownRankPage::class)->set('siteId', $f['site']->id)
        ->call('openKeyword', (string) $f['keyword']->id)
        ->call('selectTown', (string) $f['town']->id)
        ->assertSee('Build a town page')
        ->assertSeeHtml('wire:click="buildTownPage"');

    // Give the town a page: the suggestion — and its button — go away. (The town list is memoised per
    // request; a second Livewire test in the same process shares that instance, so drop it.)
    Content::factory()->page()->published()->create(['site_id' => $f['site']->id, 'page_type' => PageType::Location,
        'geo_id' => '4201781720', 'slug' => 'warrington-pa', 'title' => 'Warrington']);
    app(TownRankPoints::class)->forget();

    Livewire::test(TownRankPage::class)->set('siteId', $f['site']->id)
        ->call('openKeyword', (string) $f['keyword']->id)
        ->call('selectTown', (string) $f['town']->id)
        ->assertDontSee('Build a town page')
        ->assertDontSeeHtml('wire:click="buildTownPage"');
});

it('builds the page from the map: selects the town, creates the page, queues the draft', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = buildPageSite();

    expect($f['town']->page_selected)->toBeFalse()
        ->and(Content::withoutGlobalScopes()->where('title', 'like', 'Warrington%')->count())->toBe(0);

    Livewire::test(TownRankPage::class)->set('siteId', $f['site']->id)
        ->call('openKeyword', (string) $f['keyword']->id)
        ->call('selectTown', (string) $f['town']->id)
        ->call('buildTownPage')
        ->assertOk();

    // Asking to build it IS selecting it, and the page made is THIS town's.
    $page = Content::withoutGlobalScopes()->where('title', 'like', 'Warrington%')->sole();
    expect($f['town']->fresh()->page_selected)->toBeTrue()
        ->and($page->page_type)->toBe(PageType::Location)
        ->and($page->slug)->toContain('warrington');
    Queue::assertPushed(GeneratePage::class, fn (GeneratePage $j): bool => $j->contentId === (string) $page->id);
});

it('offers the same build on the Service Areas polygon map, and builds from there', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = buildPageSite();
    $location = Location::withoutGlobalScopes()->where('site_id', $f['site']->id)->sole();

    Livewire::test(ServiceAreasPage::class)->set('siteId', $f['site']->id)
        ->call('openArea', (string) $location->id)
        ->call('selectTown', (string) $f['keyword']->id, (string) $f['town']->id)
        ->assertSee('Build a town page')
        ->assertSeeHtml('wire:click="buildTownPage"')
        ->call('buildTownPage')
        ->assertOk();

    $page = Content::withoutGlobalScopes()->where('title', 'like', 'Warrington%')->sole();
    expect($f['town']->fresh()->page_selected)->toBeTrue();
    Queue::assertPushed(GeneratePage::class, fn (GeneratePage $j): bool => $j->contentId === (string) $page->id);
});
