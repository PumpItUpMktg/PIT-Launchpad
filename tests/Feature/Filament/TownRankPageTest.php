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
use App\Models\User;
use Filament\Facades\Filament;
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
        ->assertSee('sump pump service')
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
        ->assertDontSeeHtml('wire:key="tr-'.$hack->id.'"');   // the table row is filtered out (the map dot + panel still name it)
});

it('shows an empty state when the site has no town-rank scans', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    Livewire::test(TownRankPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('No town-rank scans for this site yet');
});
