<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperatePages;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operate\PagesBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('opens the Town tab, with the location strip and its town pages', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'parent_location_id' => $loc->id, 'slug' => 'chalfont-pa', 'title' => 'Chalfont',
    ]);

    // Before locTab lived on the shared parent this threw "Property [$locTab] not found" as it rendered,
    // so the tab did nothing at all when clicked.
    Livewire::test(OperatePages::class)
        ->set('siteId', $site->id)
        ->set('tab', 'town')
        ->assertOk()
        ->assertSee('Doylestown')          // the location tab strip
        ->assertSee('Chalfont')            // its published town page
        ->call('setLocTab', (string) $loc->id)
        ->assertSet('locTab', (string) $loc->id)
        ->assertOk();
});

it('builds cards for the location being viewed and leaves the rest of the tab strip cheap', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $shown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);
    $other = Location::factory()->create(['site_id' => $site->id, 'name' => 'Newtown', 'lat' => 40.22, 'lng' => -74.93]);
    foreach ([[$shown, 'chalfont-pa', 'Chalfont'], [$other, 'yardley-pa', 'Yardley']] as [$loc, $slug, $title]) {
        Content::factory()->page()->published()->create([
            'site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
            'parent_location_id' => $loc->id, 'slug' => $slug, 'title' => $title,
        ]);
    }

    $board = app(PagesBoard::class)->locations($site, (string) $shown->id);
    $groups = collect($board['live']['groups'])->keyBy(fn (array $g): string => $g['location']['name']);

    // Both locations appear, so the tab strip is whole …
    expect($groups->keys()->sort()->values()->all())->toBe(['Doylestown', 'Newtown'])
        // … but only the one being viewed pays for its cards.
        ->and($groups['Doylestown']['towns'])->toHaveCount(1)
        ->and($groups['Doylestown']['deferred'])->toBeFalse()
        ->and($groups['Newtown']['towns'])->toBe([])
        ->and($groups['Newtown']['deferred'])->toBeTrue()
        ->and($groups['Newtown']['location']['name'])->toBe('Newtown');
});

it('reads Search Console and Bing from cache only on the render path', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com', 'gsc_property' => 'sc-domain:spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'parent_location_id' => $loc->id, 'slug' => 'chalfont-pa', 'title' => 'Chalfont',
    ]);
    Http::preventStrayRequests();

    // A cold render must not reach out: no warmed cache, no outbound call, an honest pending instead.
    $board = app(PagesBoard::class)->locations($site, (string) $loc->id);

    expect($board['live']['groups'][0]['towns'])->toHaveCount(1);
    Http::assertNothingSent();
});

it('builds only the tab it is about to show, never every location, on the first open', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    // Alphabetically first is Ambler; the view lands on it when nothing is selected, so the board must too.
    foreach (['Montclair', 'Ambler', 'Trenton'] as $name) {
        $loc = Location::factory()->create(['site_id' => $site->id, 'name' => $name, 'lat' => 40.31, 'lng' => -75.13]);
        Content::factory()->page()->published()->create([
            'site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
            'parent_location_id' => $loc->id, 'slug' => strtolower($name).'-town-pa', 'title' => $name.' Town',
        ]);
    }

    $board = Livewire::test(OperatePages::class)->set('siteId', $site->id)->set('tab', 'town')->get('board');
    $built = collect($board['live']['groups'])->reject(fn (array $g): bool => $g['deferred']);

    // One location's cards built, not fifteen — and it is the one the view will display.
    expect($built)->toHaveCount(1)
        ->and($built->first()['location']['name'])->toBe('Ambler')
        ->and($built->first()['towns'])->toHaveCount(1)
        // Every location still appears, so the tab strip is whole.
        ->and($board['live']['groups'])->toHaveCount(3);
});

it('lists the towns selected for this location that have no page yet, each with its own build button', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);

    // Chalfont is selected AND built; Warrington and Perkasie are selected with nothing made yet.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Chalfont', 'state' => 'PA', 'geo_id' => '4201712408',
        'population' => 4259, 'lat' => 40.28, 'lng' => -75.20, 'page_selected' => true, 'source_location_ids' => [$loc->id]]);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'parent_location_id' => $loc->id, 'geo_id' => '4201712408',
        'slug' => 'chalfont-pa', 'title' => 'Chalfont']);
    $warrington = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Warrington', 'state' => 'PA', 'geo_id' => '4201781720',
        'population' => 25597, 'lat' => 40.25, 'lng' => -75.15, 'page_selected' => true, 'source_location_ids' => [$loc->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Perkasie', 'state' => 'PA', 'geo_id' => '4201759032',
        'population' => 9130, 'lat' => 40.37, 'lng' => -75.29, 'page_selected' => true, 'source_location_ids' => [$loc->id]]);
    // Not selected: not a candidate, so it must not appear.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Riegelsville', 'state' => 'PA', 'geo_id' => '4201765488',
        'population' => 792, 'lat' => 40.59, 'lng' => -75.19, 'page_selected' => false, 'source_location_ids' => [$loc->id]]);

    Livewire::test(OperatePages::class)->set('siteId', $site->id)->set('tab', 'town')
        ->assertOk()
        ->assertSee('town(s) selected here with no page yet')
        ->assertSeeHtml('<b>2</b> town(s) selected here with no page yet')
        ->assertSee('Warrington, PA')       // biggest first — the one worth building next
        ->assertSee('Perkasie, PA')
        ->assertDontSee('Chalfont, PA')     // already built, so it is a live card, not a candidate
        ->assertDontSee('Riegelsville')     // never selected
        ->assertSeeHtml('wire:click="generateTown(\''.$warrington->id.'\')"');
});

it('shows the tab whose cards it built — the storefront-named location no longer lands on a placeholder', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);

    // Created FIRST but named late in the alphabet: the two orderings (creation vs label) disagree here,
    // which is exactly when the visible tab used to be a deferred placeholder reporting nothing.
    $storefront = Location::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Gurus', 'lat' => 40.31, 'lng' => -75.13]);
    $doylestown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);

    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'parent_location_id' => $doylestown->id, 'slug' => 'chalfont-pa', 'title' => 'Chalfont',
    ]);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'parent_location_id' => $storefront->id, 'slug' => 'warrington-pa', 'title' => 'Warrington',
    ]);

    // Both read one list: the first tab the view renders is the one the page built.
    $tabs = app(PagesBoard::class)->locationTabs($site);
    expect(array_column($tabs, 'label'))->toBe(['Doylestown', 'Sump Pump Gurus']);

    // Landing with no tab chosen shows Doylestown's cards, not an empty placeholder.
    Livewire::test(OperatePages::class)
        ->set('siteId', $site->id)
        ->set('tab', 'town')
        ->assertOk()
        ->assertSee('Chalfont')
        ->assertDontSee('Warrington');
});

it('a deferred location reports nothing rather than zero', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $shown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown']);
    $deferred = Location::factory()->create(['site_id' => $site->id, 'name' => 'Newtown']);

    $groups = app(PagesBoard::class)->locations($site, (string) $shown->id)['live']['groups'];
    $byId = collect($groups)->keyBy(fn (array $g): string => (string) $g['location']['id']);

    // "0 town pages live" on a group nobody counted is how a selection bug reads as data loss.
    expect($byId[(string) $deferred->id]['rollup'])->toBeNull()
        ->and($byId[(string) $deferred->id]['deferred'])->toBeTrue()
        ->and($byId[(string) $shown->id]['rollup'])->toBeArray()
        ->and($byId[(string) $shown->id]['deferred'])->toBeFalse();
});
