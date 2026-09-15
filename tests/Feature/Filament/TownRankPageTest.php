<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\TownRankPage;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Jobs\RunTownRankKeyword;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('is operator-only', function () {
    expect(TownRankPage::canAccess())->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(TownRankPage::canAccess())->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(TownRankPage::canAccess())->toBeTrue();
});

it('renders the keyword board, switches mode, and shows a town\'s diagnosis when selected', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id], 'geo_id' => '3404128590']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mansfield', 'state' => 'NJ', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => [$loc->id]]);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => '3404128590', 'slug' => 'hackettstown-nj']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 2, 'found_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'sump pump service Hackettstown NJ', 'rank' => 4, 'ranking_url' => 'https://spg.com/hackettstown-nj/', 'collected_at' => now(),
        'top_results' => [['position' => 1, 'url' => 'https://rival.com', 'domain' => 'rival.com'], ['position' => 4, 'url' => 'https://spg.com/hackettstown-nj/', 'domain' => 'spg.com']]]);

    Livewire::test(TownRankPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        // The card wall first: one card per scanned keyword, no board yet.
        ->assertSee('sump pump service')
        ->assertSee('Town search:')
        ->assertDontSee('Pick a town')
        ->call('openKeyword', $kw->id)
        ->assertSet('keywordId', $kw->id)
        ->assertSee('All keywords')
        ->assertSee('Page 1 (4–10)')
        ->assertSee('Hackettstown, NJ')
        ->assertSee('Pick a town')
        ->call('selectTown', $hack->id)
        ->assertSee('Close the gap to the top 3 (#4)')
        ->assertSee('rival.com')
        ->call('setMode', 'local')
        ->assertSee('No searched-from-town scan')
        ->set('filter', 'mans')
        ->assertSeeHtml('wire:key="tr-'.CoverageArea::query()->withoutGlobalScopes()->where('name', 'Mansfield')->value('id').'"')
        ->assertDontSeeHtml('wire:key="tr-'.$hack->id.'"')   // the table row is filtered out (the map dot + panel still name it)
        ->call('closeKeyword')
        ->assertSet('keywordId', null)
        ->assertSet('townId', null)
        ->assertSee('Town search:');   // back on the card wall
});

it('shows an empty state when the site has no town-rank scans', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    Livewire::test(TownRankPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('No keywords tracked for Town Rank yet');
});

it('offers the movement view once a previous scan exists', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id]]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    foreach ([['2026-09-01 10:00:00', 9], ['2026-09-08 10:00:00', 4]] as [$at, $rank]) {
        $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 1, 'found_count' => 1, 'scanned_at' => $at]);
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'q', 'rank' => $rank, 'collected_at' => $at]);
    }

    Livewire::test(TownRankPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('▲1')   // the card already carries the movement counts
        ->call('openKeyword', $kw->id)
        ->assertSee('Movement')
        ->assertSee('vs Sep 1')
        ->call('setView', 'move')
        ->assertSet('colorBy', 'move')
        ->assertSee('moved up')
        ->call('selectTown', $hack->id)
        ->assertSee('was #9');
});

it('adds a keyword from the wall, shows its card, and queues its ranking report from the card', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id]]);

    $page = Livewire::test(TownRankPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('No keywords tracked for Town Rank yet')
        ->set('newKeyword', 'sump pump repair')
        ->call('addKeyword')
        ->assertSet('newKeyword', '')
        ->assertSee('sump pump repair')
        ->assertSee('Not scanned yet')
        ->assertSee('Run ranking report');

    $kw = Keyword::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('query', 'sump pump repair')->first();
    expect($kw)->not->toBeNull()->and($kw->track_town_rank)->toBeTrue();

    $page->call('runKeyword', $kw->id);
    Queue::assertPushed(RunTownRankKeyword::class, fn (RunTownRankKeyword $j): bool => $j->keywordId === (string) $kw->id);

    // While a scan is collecting the card says so and the button is disabled.
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'pending', 'points_count' => 1, 'scanned_at' => now()]);
    Livewire::test(TownRankPage::class)->set('siteId', $site->id)->assertSee('Collecting…');

    // A blank add is refused without creating anything.
    Livewire::test(TownRankPage::class)->set('siteId', $site->id)->set('newKeyword', '  ')->call('addKeyword');
    expect(Keyword::query()->withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(1);
});
