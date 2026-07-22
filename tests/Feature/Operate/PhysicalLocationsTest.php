<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperatePhysicalLocations;
use App\Jobs\GeneratePage;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Market;
use App\Models\Site;
use App\Models\User;
use App\Operate\PhysicalLocations;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

/** Montgomery County, PA GEOID = 42091; a cousub GEOID prefixes with it. */
function plArea(Site $site, string $geoId, string $name, array $sourceIds, bool $selected = false): CoverageArea
{
    return CoverageArea::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'geo_id' => $geoId, 'name' => $name, 'type' => 'county_subdivision',
        'state' => 'PA', 'source_location_ids' => $sourceIds, 'page_selected' => $selected, 'source' => 'county',
    ]);
}

it('builds one card per location: territory counts, overlap named per town, home-county soft rule honored', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $trooper = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.1, 'lng' => -75.4,
        'home_county_geoid' => '42091', 'county_geoids' => ['42091', '42029'],
        'place_id' => 'gbp-trooper', 'gbp_url' => 'https://maps.google.com/?cid=1',
    ]);
    // Montclair sits in Essex NJ (34013) but does NOT serve it — the soft rule flags it, advisory only.
    $montclair = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair', 'lat' => 40.8, 'lng' => -74.2,
        'home_county_geoid' => '34013', 'county_geoids' => ['34031'],
    ]);

    plArea($site, '4209153000', 'Norristown', [$trooper->id], selected: true); // home-county town
    plArea($site, '4202912345', 'Phoenixville', [$trooper->id]);
    plArea($site, '3403155555', 'Wayne', [$trooper->id, $montclair->id]);      // OVERLAP — both reach it

    $board = app(PhysicalLocations::class)->build($site);

    expect($board['summary']['locations'])->toBe(2)
        ->and($board['summary']['towns_covered'])->toBe(3)
        ->and($board['summary']['towns_selected'])->toBe(1)
        ->and($board['summary']['overlaps'])->toBe(1);

    $cards = collect($board['cards'])->keyBy('name');
    $t = $cards['Trooper'];
    expect($t['serves_home_county'])->toBeTrue()
        ->and($t['gbp_linked'])->toBeTrue()
        ->and($t['towns_covered'])->toBe(3)
        ->and($t['home_county_towns'])->toBe(1)                    // Norristown prefixes 42091
        ->and($t['overlaps'][0]['town'])->toBe('Wayne, PA')
        ->and($t['overlaps'][0]['with'])->toBe(['Montclair'])       // names the other location
        ->and($t['advisories'])->toBe([]);                          // soft rule satisfied

    $m = $cards['Montclair'];
    expect($m['serves_home_county'])->toBeFalse()
        ->and($m['gbp_linked'])->toBeFalse()
        ->and($m['overlaps'][0]['with'])->toBe(['Trooper'])
        // Advisory, never a wall — the card still renders its territory in full.
        ->and(implode(' ', $m['advisories']))->toContain('home county')
        ->and($m['towns_covered'])->toBe(1);
});

it('renders under Operate with the overlap tile and the soft-rule chips', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);
    $trooper = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.1, 'lng' => -75.4,
        'home_county_geoid' => '42091', 'county_geoids' => ['42091'],
    ]);
    plArea($site, '4209153000', 'Norristown', [$trooper->id]);

    expect(OperatePhysicalLocations::getNavigationGroup())->toBe('Operate');

    Livewire::test(OperatePhysicalLocations::class)
        ->assertOk()
        ->assertSee('Trooper')
        ->assertSee('Norristown')
        ->assertSee('serves home county')
        ->assertSee('overlapping towns');

    // An unlocated location surfaces the locate advisory instead of a false soft-rule flag.
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Ghost office', 'lat' => null, 'lng' => null, 'home_county_geoid' => null]);
    Livewire::test(OperatePhysicalLocations::class)->assertSee('Not located yet');
});

it('surfaces each location card page state — none, drafted, published', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    // No page yet.
    $bare = Location::factory()->create(['site_id' => $site->id, 'name' => 'No Page Yet']);
    // A drafted (but unpublished) landing page.
    $drafted = Location::factory()->create(['site_id' => $site->id, 'name' => 'Drafted']);
    Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'title' => 'Drafted, ST', 'slug' => 'drafted-st', 'version' => 1,
        'location_id' => $drafted->id, 'slot_payload' => ['hero_headline' => 'We serve Drafted'],
    ]);
    // A published landing page.
    $live = Location::factory()->create(['site_id' => $site->id, 'name' => 'Live']);
    Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => 'Live, ST', 'slug' => 'live-st', 'version' => 1,
        'location_id' => $live->id, 'slot_payload' => ['hero_headline' => 'We serve Live'],
    ]);

    $cards = collect(app(PhysicalLocations::class)->build($site)['cards'])->keyBy('name');

    expect($cards['No Page Yet']['page'])->toMatchArray(['state' => 'none', 'can_generate' => true, 'can_review' => false, 'can_publish' => false, 'can_repush' => false, 'can_takedown' => false]);
    expect($cards['Drafted']['page'])->toMatchArray(['drafted' => true, 'published' => false, 'can_review' => true, 'can_publish' => true, 'can_repush' => false, 'can_takedown' => false]);
    expect($cards['Live']['page'])->toMatchArray(['published' => true, 'can_review' => true, 'can_publish' => false, 'can_repush' => true, 'can_takedown' => true]);
});

it('Generate creates the location landing page and queues the drafter', function () {
    Queue::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    Market::factory()->create(['site_id' => $site->id]); // grounding: a §1 Market makes a location page ready
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);

    Livewire::test(OperatePhysicalLocations::class)->call('generatePage', $loc->id);

    $landing = Content::withoutGlobalScopes()
        ->where('page_type', PageType::Location->value)->where('location_id', $loc->id)->first();
    expect($landing)->not->toBeNull()
        ->and($landing->meta['generating_at'] ?? null)->not->toBeNull();
    Queue::assertPushed(GeneratePage::class);
});

it('Publish approves + pushes a drafted location page; Repush re-pushes a live one', function () {
    Queue::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);
    $landing = Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa', 'version' => 1,
        'location_id' => $loc->id, 'slot_payload' => ['hero_headline' => 'We serve Trooper'],
    ]);

    Livewire::test(OperatePhysicalLocations::class)->call('publishPage', $loc->id);
    expect($landing->refresh()->status)->toBe(ContentStatus::Approved);
    Queue::assertPushed(PublishContent::class);

    // Repush on the now-published page dispatches another idempotent push.
    $landing->forceFill(['status' => ContentStatus::Published])->save();
    Queue::fake();
    Livewire::test(OperatePhysicalLocations::class)->call('repushPage', $loc->id);
    Queue::assertPushed(PublishContent::class);
});

it('Take down removes a live location page from WordPress and flips it back to republishable', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);
    $landing = Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa', 'version' => 1,
        'location_id' => $loc->id, 'slot_payload' => ['hero_headline' => 'We serve Trooper'],
    ]);

    Livewire::test(OperatePhysicalLocations::class)->call('takeDown', $loc->id);

    // Back in the work lane on the same URL — Approved (republishable), no WP post id.
    expect($landing->refresh()->status)->toBe(ContentStatus::Approved)
        ->and($landing->wp_post_id)->toBeNull();
});

it('the card footer offers Review and Take down alongside Repush on a live page', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.1, 'lng' => -75.4, 'home_county_geoid' => '42091', 'county_geoids' => ['42091']]);
    plArea($site, '4209153000', 'Norristown', [$loc->id]);
    Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa', 'version' => 1,
        'location_id' => $loc->id, 'slot_payload' => ['hero_headline' => 'We serve Trooper'],
    ]);

    Livewire::test(OperatePhysicalLocations::class)
        ->assertSee('Review')
        ->assertSee('Repush')
        ->assertSee('Regenerate')
        ->assertSee('Take down');
});

it('Publish is a no-op with a helpful notice when the location has no page yet', function () {
    Queue::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);

    Livewire::test(OperatePhysicalLocations::class)->call('publishPage', $loc->id);

    Queue::assertNothingPushed();
});
