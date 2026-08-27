<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperatePhysicalLocations;
use App\GeoGrid\GeoGridMetrics;
use App\Jobs\PublishContent;
use App\Models\Connection;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operate\PhysicalLocations;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

it('shows a calm "publishing" banner while the queue is draining — not the stalled alarm', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);

    // An ageing backlog, but a worker is actively holding a job (reserved just now) → draining, not down.
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time() - 5, 'available_at' => time() - 600, 'created_at' => time() - 600],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 300, 'created_at' => time() - 300],
    ]);

    Livewire::test(OperatePhysicalLocations::class)
        ->assertOk()
        ->assertSee('Publishing')                          // the calm progress line
        ->assertSee('clearing one at a time')
        ->assertDontSee('background worker looks down')     // no false alarm
        ->assertDontSee('looks stalled');
});

it('shows the failed jobs under the stalled banner and clears them from the button', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\PublishContent']),
        'exception' => "RuntimeException: WP 401 unauthorized\n#0 /app/...", 'failed_at' => now(),
    ]);

    $page = Livewire::test(OperatePhysicalLocations::class)
        ->assertSee('job(s) failed')              // failed-only (worker not necessarily down)
        ->assertSee('PublishContent')             // WHAT failed
        ->assertSee('WP 401 unauthorized')        // WHY (first exception line)
        ->assertSee('Clear 1 failed');            // the button

    $page->call('clearFailedJobs');

    expect(DB::table('failed_jobs')->count())->toBe(0);
    // Banner is gone on the next render — nothing queued, nothing failed.
    $page->assertDontSee('job(s) failed');
});

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

    // No page → Generate only (no content id, no metrics). Drafted/Live → a content id + the tracking block.
    expect($cards['No Page Yet']['page'])->toMatchArray(['state' => 'none', 'content_id' => null, 'metrics' => null, 'can_generate' => true, 'can_review' => false]);
    expect($cards['Drafted']['page'])->toMatchArray(['drafted' => true, 'published' => false, 'can_review' => true])
        ->and($cards['Drafted']['page']['content_id'])->not->toBeNull()
        ->and($cards['Drafted']['page']['metrics'])->not->toBeNull();
    expect($cards['Live']['page'])->toMatchArray(['published' => true, 'can_review' => true])
        ->and($cards['Live']['page']['metrics'])->not->toBeNull();
});

it('Repush approves + pushes a drafted location page; a second Repush re-pushes the live one', function () {
    Queue::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);
    $landing = Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa', 'version' => 1,
        'location_id' => $loc->id, 'slot_payload' => ['hero_headline' => 'We serve Trooper'],
    ]);

    // Content-keyed Repush doubles as the first publish (approve → publish) before the page is live.
    Livewire::test(OperatePhysicalLocations::class)->call('repush', $landing->id);
    expect($landing->refresh()->status)->toBe(ContentStatus::Approved);
    Queue::assertPushed(PublishContent::class);

    // Repush on the now-published page dispatches another idempotent push.
    $landing->forceFill(['status' => ContentStatus::Published])->save();
    Queue::fake();
    Livewire::test(OperatePhysicalLocations::class)->call('repush', $landing->id);
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

    // Content-keyed Take down (matches the shared card action).
    Livewire::test(OperatePhysicalLocations::class)->call('takeDown', $landing->id);

    // Back in the work lane on the same URL — Approved (republishable), no WP post id.
    expect($landing->refresh()->status)->toBe(ContentStatus::Approved)
        ->and($landing->wp_post_id)->toBeNull();
});

it('each location card shows the standard Position / GSC / GA4 tracking block with honest pending reasons', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.1, 'lng' => -75.4, 'home_county_geoid' => '42091', 'county_geoids' => ['42091']]);
    plArea($site, '4209153000', 'Norristown', [$loc->id]);
    // A landing page with no target keyword → the brand-page pending reason; no GSC/GA connection → connect prompts.
    Content::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa', 'version' => 1,
        'location_id' => $loc->id, 'slot_payload' => ['hero_headline' => 'We serve Trooper'],
    ]);

    Livewire::test(OperatePhysicalLocations::class)
        ->assertSee('Position')
        ->assertSee('No target keyword — brand page')
        ->assertSee('GSC · 28d')
        ->assertSee('GA4 sessions');
});

it('bands a location\'s towns Larger/Mid/Smaller with page status + a selectable count', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'New Brunswick']);

    // Three towns across the bands, all page_selected. One already has a published page (not selectable).
    $big = CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '34023a', 'name' => 'Edison', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'large', 'population' => 100000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '34023b', 'name' => 'Metuchen', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'medium', 'population' => 15000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '34023c', 'name' => 'Milltown', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'small', 'population' => 7000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    // Edison already published → excluded from the selectable batch.
    Content::withoutGlobalScopes()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'title' => 'Edison, NJ', 'slug' => 'edison', 'version' => 1, 'parent_location_id' => $loc->id]);

    $card = collect(app(PhysicalLocations::class)->build($site)['cards'])->firstWhere('id', $loc->id);

    expect(collect($card['town_bands']['larger'])->pluck('name'))->toContain('Edison')
        ->and(collect($card['town_bands']['mid'])->pluck('name'))->toContain('Metuchen')
        ->and(collect($card['town_bands']['smaller'])->pluck('name'))->toContain('Milltown')
        ->and(collect($card['town_bands']['larger'])->firstWhere('name', 'Edison')['status'])->toBe('published')
        // Selectable = page_selected & not published/generating → Metuchen + Milltown (Edison is live).
        ->and($card['selectable'])->toBe(2);
});

it('the pipeline counts distinct TOWNS, not pages — duplicate town rows never inflate published past selected', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bedminster']);
    // Two selected towns…
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => 'a', 'name' => 'Bridgewater', 'type' => 'place', 'state' => 'NJ', 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => 'b', 'name' => 'Hillsborough', 'type' => 'place', 'state' => 'NJ', 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    // …but THREE published town pages, because Bridgewater has a duplicate row (bridgewater-nj-4).
    foreach (['bridgewater-nj', 'bridgewater-nj-4', 'hillsborough-nj'] as $i => $slug) {
        Content::withoutGlobalScopes()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
            'status' => ContentStatus::Published, 'title' => str_contains($slug, 'bridgewater') ? 'Bridgewater, NJ' : 'Hillsborough, NJ',
            'slug' => $slug, 'version' => 1, 'parent_location_id' => $loc->id,
        ]);
    }

    $pipeline = app(PhysicalLocations::class)->build($site)['pipeline'];

    // 2 distinct towns published (not 3 pages) against 2 selected — no more "3 / 2".
    expect($pipeline['selected'])->toBe(2)
        ->and($pipeline['published'])->toBe(2);
});

it('toggleTown and selectBand write the page_selected flag', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'NB']);
    $a = CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '1', 'name' => 'Edison', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'large', 'population' => 100000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => false]);
    $b = CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '2', 'name' => 'Metuchen', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'large', 'population' => 90000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => false]);

    $page = Livewire::test(OperatePhysicalLocations::class);
    $page->call('toggleTown', $a->id);
    expect($a->fresh()->page_selected)->toBeTrue();

    $page->call('selectBand', $loc->id, 'larger', true);
    expect($a->fresh()->page_selected)->toBeTrue()->and($b->fresh()->page_selected)->toBeTrue();

    $page->call('selectBand', $loc->id, 'larger', false);
    expect($a->fresh()->page_selected)->toBeFalse()->and($b->fresh()->page_selected)->toBeFalse();
});

it('the review gate surfaces drafted selected towns with a per-town Publish + a "Publish reviewed" batch', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'NB']);
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '1', 'name' => 'Edison', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'large', 'population' => 100000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    // A DRAFTED town page (slot_payload present) → shows in the review list, reviewable = 1.
    Content::withoutGlobalScopes()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::NeedsReview, 'title' => 'Edison, NJ', 'slug' => 'edison', 'version' => 1, 'parent_location_id' => $loc->id, 'slot_payload' => ['hero' => ['heading' => 'We serve Edison']]]);

    $card = collect(app(PhysicalLocations::class)->build($site)['cards'])->firstWhere('id', $loc->id);
    expect($card['reviewable'])->toBe(1);

    Livewire::test(OperatePhysicalLocations::class)
        ->assertSee('Publish reviewed')
        ->assertSee('review, then publish');
});

it('publishReviewedSelected approves the reviewed drafts (the approve half of the gate) synchronously', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'NB']);
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '1', 'name' => 'Edison', 'type' => 'place', 'state' => 'NJ', 'size_tier' => 'large', 'population' => 100000, 'source' => 'county', 'source_location_ids' => [$loc->id], 'page_selected' => true]);
    $town = Content::withoutGlobalScopes()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::NeedsReview, 'title' => 'Edison, NJ', 'slug' => 'edison', 'version' => 1, 'parent_location_id' => $loc->id, 'slot_payload' => ['hero' => ['heading' => 'We serve Edison']]]);

    // No verified WP connection in the test env, so PostPublisher won't push — but approve() runs first,
    // flipping the reviewed draft to approved. That proves the review→approve gate executed inline.
    Livewire::test(OperatePhysicalLocations::class)->call('publishReviewedSelected', $loc->id);

    expect($town->fresh()->status)->toBe(ContentStatus::Approved);
});

it('surfaces a worker-down banner with the drain hint when the queue is backed up and nothing is processing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);
    // A job that has sat 10 minutes with NO worker holding it → the worker looks down.
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => time() - 600, 'created_at' => time() - 600,
    ]);

    Livewire::test(OperatePhysicalLocations::class)
        ->assertSee('worker looks down')
        ->assertSee('launchpad:drain-publish');
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

    // Repush + Take down (this surface publishes + tracks; drafting/regeneration moved to Location pages).
    Livewire::test(OperatePhysicalLocations::class)
        ->assertSee('Review')
        ->assertSee('Repush')
        ->assertSee('Take down')
        ->assertDontSee('Regenerate');            // generation is not on this surface anymore
});

it('renders a TAB per physical location and shows one at a time', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.1, 'lng' => -75.4, 'home_county_geoid' => '42091', 'county_geoids' => ['42091']]);
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'lat' => 40.8, 'lng' => -74.2, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);
    plArea($site, '4209153000', 'Norristown', [$trooper->id]);   // Trooper's town
    plArea($site, '3401355000', 'Verona', [$montclair->id]);     // Montclair's town

    // Both locations are tabs; the active one renders full-width, one at a time (order-agnostic).
    $page = Livewire::test(OperatePhysicalLocations::class)
        ->assertOk()
        ->assertSee('Trooper')        // tab
        ->assertSee('Montclair');     // tab

    $page->call('setLocTab', $trooper->id)
        ->assertSee('Norristown')     // Trooper active → its town shows
        ->assertDontSee('Verona');    // Montclair's town hidden

    $page->call('setLocTab', $montclair->id)
        ->assertSee('Verona')         // now Montclair active
        ->assertDontSee('Norristown');
});

it('shows the coverage area-score pill and a View coverage link on the location card', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);
    $loc = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Downingtown', 'gbp_url' => 'https://g/?cid=1', 'place_id' => 'p',
        'lat' => 40.0, 'lng' => -75.7, 'home_county_geoid' => '42029', 'county_geoids' => ['42029'],
    ]);
    $town = plArea($site, '4202919000', 'Downingtown', [$loc->id]);
    $town->forceFill(['lat' => 40.0, 'lng' => -75.7, 'population' => 8000])->save();

    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => (string) Str::ulid(), 'provider' => 'dataforseo',
        'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.0, 'center_lng' => -75.7,
        'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => 0, 'coverage_area_id' => $town->id, 'label' => 'Downingtown', 'lat' => 40.0, 'lng' => -75.7, 'rank' => 1]);
    app(GeoGridMetrics::class)->recompute($scan);

    Livewire::test(OperatePhysicalLocations::class)
        ->assertOk()
        ->assertSee('Area score')          // the score pill (rank 1 → 100)
        ->assertSee('View coverage');      // the link to Coverage Progress
});
