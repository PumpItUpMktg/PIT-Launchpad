<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperatePages;
use App\Models\Content;
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
