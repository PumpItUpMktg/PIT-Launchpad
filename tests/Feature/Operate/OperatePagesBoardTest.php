<?php

use App\Build\PlanSync;
use App\Enums\AuditAction;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateCorePages;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Jobs\GeneratePage;
use App\Jobs\PublishContent;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use App\Models\WireframeKit;
use App\Operate\PagesBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function pbSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
}

function pbPage(Site $site, PageType $type, ContentStatus $status, string $title, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => $type,
        'status' => $status, 'title' => $title, 'slug' => Str::slug($title),
        'published_at' => $status === ContentStatus::Published ? now()->subDays(2) : null,
        'slot_payload' => $status === ContentStatus::Candidate ? [] : ['hero' => 'x'],
    ], $extra));
}

it('each family board carries ONLY its own pages — work lane + live lane split by status', function () {
    $site = pbSite();
    // Core: one working, one live. Service: one working. Location: one live town.
    pbPage($site, PageType::Utility, ContentStatus::NeedsReview, 'About Us');
    pbPage($site, PageType::Home, ContentStatus::Published, 'Homepage');
    pbPage($site, PageType::Service, ContentStatus::Candidate, 'French Drains');
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);
    pbPage($site, PageType::Location, ContentStatus::Published, 'Norristown', ['parent_location_id' => $trooper->id]);

    $board = app(PagesBoard::class);

    $core = $board->core($site);
    expect(collect($core['work'])->pluck('title')->all())->toBe(['About Us'])
        ->and(collect($core['live'])->pluck('title')->all())->toBe(['Homepage']);

    $services = $board->services($site);
    expect(collect($services['work'])->pluck('title')->all())->toBe(['French Drains'])
        ->and($services['live'])->toBe([]);

    $locations = $board->locations($site);
    expect($locations['work'])->toBe([])                        // the town page is live, not working
        ->and($locations['live']['groups'][0]['towns'][0]['title'])->toBe('Norristown');
});

it('the boards render and the work-lane primary drives the existing paths (approve → publish)', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $draft = pbPage($site, PageType::Utility, ContentStatus::NeedsReview, 'About Us');
    pbPage($site, PageType::Home, ContentStatus::Published, 'Homepage');

    $homepage = Content::query()->where('title', 'Homepage')->first();
    $page = Livewire::test(OperateCorePages::class)
        ->assertOk()
        ->assertSee('About Us')      // work lane
        ->assertSee('Homepage')      // live lane
        // The per-page QA drill-down (Proof Editor) is linked from BOTH lanes — a ready-to-review
        // draft and a live card each carry Review, so the editor isn't stranded behind old Grow.
        ->assertSeeHtml('proof?content='.$draft->id)
        ->assertSeeHtml('proof?content='.$homepage->id)
        ->call('approve', $draft->id);

    expect($draft->fresh()->status)->toBe(ContentStatus::Approved);

    $page->call('publish', $draft->id);
    Queue::assertPushed(PublishContent::class);
});

it('a live card takes down back to the work lane of the SAME board (state-driven membership)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $live = pbPage($site, PageType::Service, ContentStatus::Published, 'Sump Pump Installation', ['wp_post_id' => null]);

    Livewire::test(OperateServicePages::class)->call('takeDown', $live->id);

    // Not on WP → marked approved (ready to republish); it now lives in the work lane.
    $board = app(PagesBoard::class)->services($site->fresh());
    expect($live->fresh()->status)->toBe(ContentStatus::Approved)
        ->and(collect($board['work'])->pluck('title')->all())->toContain('Sump Pump Installation')
        ->and($board['live'])->toBe([]);
});

it('Generate all ready queues every ready-to-generate page in the family and leaves the rest', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    Service::factory()->create(['site_id' => $site->id]); // a service exists → service pages are grounding-ready
    $kit = WireframeKit::query()->where('name', 'service-page')->whereNull('site_id')->firstOrFail();

    // Two ready-to-generate service pages (Candidate ⇒ empty slot_payload; kit pinned ⇒ not held).
    $kitCols = ['wireframe_kit_id' => $kit->id, 'wireframe_kit_version' => $kit->version];
    pbPage($site, PageType::Service, ContentStatus::Candidate, 'French Drains', $kitCols);
    pbPage($site, PageType::Service, ContentStatus::Candidate, 'Sump Pump', $kitCols);
    // An already-drafted page (NeedsReview) — not a generate row, so it's left alone.
    pbPage($site, PageType::Service, ContentStatus::NeedsReview, 'Basement Waterproofing');

    Livewire::test(OperateServicePages::class)->call('generateAllReady');

    // Exactly the two ready pages queued — the drafted one is untouched.
    Queue::assertPushed(GeneratePage::class, 2);
});

it('Remove completely deletes a taken-down page from the plan and every board (not just parks it)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    // A service page sitting in the work lane after a take-down (Approved, not on WP).
    $page = pbPage($site, PageType::Service, ContentStatus::Approved, 'Sump Pump Installation', ['wp_post_id' => null]);

    // The row offers Remove (not mid-job, not the home page).
    $work = collect(app(PagesBoard::class)->services($site)['work'])->firstWhere('title', 'Sump Pump Installation');
    expect($work['menu'])->toContain('remove');

    Livewire::test(OperateServicePages::class)->call('removePage', $page->id);

    // Gone for good — soft-deleted, off the board (unlike Take down, which leaves it in the work lane).
    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id))->toBeNull()
        ->and(collect(app(PagesBoard::class)->services($site->fresh())['work'])->pluck('title')->all())
        ->not->toContain('Sump Pump Installation');
});

it('Sync plan picks up a month-3 source record as a new not-generated row with a Generate action', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $spoke = fn (string $name) => Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => $name,
        'is_pillar' => false, 'status' => SpokeStatus::Offered,
        'granularity' => SpokeGranularity::OwnPage,
        'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service,
    ]);

    // The STATED services behind the structure — a service page grounds (and so becomes
    // generatable) only against a real §1 Service; structure no longer fabricates them.
    foreach (['Sump Pump Repair', 'Battery Backup Installation'] as $name) {
        Service::withoutGlobalScope(SiteScope::class)
            ->create(['site_id' => $site->id, 'name' => $name]);
    }

    // Initial plan: one offered service page, materialized.
    $spoke('Sump Pump Repair');
    app(PlanSync::class)->sync($site);
    expect(collect(app(PagesBoard::class)->services($site)['work'])->pluck('title')->all())->toBe(['Sump Pump Repair']);

    // Month 3: a new service line lands in the structure — the board is where it gets picked up.
    $spoke('Battery Backup Installation');
    $page = Livewire::test(OperateServicePages::class)
        ->assertSee('Sync plan')
        ->call('syncPlan')
        ->assertNotified();

    $work = collect(app(PagesBoard::class)->services($site->fresh())['work']);
    $new = $work->firstWhere('title', 'Battery Backup Installation');
    expect($new)->not->toBeNull()
        ->and($new['actions'])->toContain('generate'); // not generated yet → Generate is the primary

    // Idempotent: a second sync adds nothing and never duplicates.
    app(PlanSync::class)->sync($site);
    expect(collect(app(PagesBoard::class)->services($site->fresh())['work'])->pluck('title')->filter(fn ($t) => $t === 'Battery Backup Installation'))->toHaveCount(1);
});

it('the locations board keeps the orphan-assignment controls (parent pin only)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);
    $orphan = pbPage($site, PageType::Location, ContentStatus::Published, 'Doylestown');

    Livewire::test(OperateLocationPages::class)
        ->assertOk()
        ->call('assignLocation', $orphan->id, $trooper->id);

    expect($orphan->fresh()->parent_location_id)->toBe($trooper->id)
        ->and($orphan->fresh()->location_id)->toBeNull(); // the composeLocation pin is never touched
});

it('the locations board groups the in-progress lane into a card per physical location (landing first, then towns)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);

    // Two in-progress (Approved, unpublished) location pages under Trooper: its own landing + a town.
    pbPage($site, PageType::Location, ContentStatus::Approved, 'Trooper, PA', ['location_id' => $trooper->id]);
    pbPage($site, PageType::Location, ContentStatus::Approved, 'Norristown', ['parent_location_id' => $trooper->id]);

    // The read model tags each work row with its physical-location grouping key + label.
    $work = collect(app(PagesBoard::class)->locations($site)['work']);
    expect($work->pluck('brick_mortar_id')->unique()->all())->toBe([$trooper->id])
        ->and($work->firstWhere('is_brick_mortar', true)['title'])->toBe('Trooper, PA');

    // The board renders the location card header + both pages beneath it.
    Livewire::test(OperateLocationPages::class)
        ->assertOk()
        ->assertSee('Trooper, PA')       // the group header (location label) + the landing row
        ->assertSee('Norristown')        // the town row, grouped under the same location
        ->assertSee('This location');    // the landing page's own-location marker
});

it('the locations board renders a TAB per physical location and shows one at a time', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'served_towns' => []]);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);
    pbPage($site, PageType::Location, ContentStatus::Approved, 'Verona', ['parent_location_id' => $montclair->id]);
    pbPage($site, PageType::Location, ContentStatus::Approved, 'Norristown', ['parent_location_id' => $trooper->id]);

    // Both locations are tabs; Montclair (A→Z) is active by default → only its town shows.
    Livewire::test(OperateLocationPages::class)
        ->assertOk()
        ->assertSee('Montclair')         // tab
        ->assertSee('Trooper')           // tab
        ->assertSee('Verona')            // Montclair active → its town rendered
        ->assertDontSee('Norristown')    // Trooper's town hidden (other tab)
        ->call('setLocTab', $trooper->id)
        ->assertSee('Norristown')        // now Trooper active
        ->assertDontSee('Verona');       // Montclair hidden
});

it('shows the active location\'s full GBP identity (name / address / phone) at the top', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    // A GBP whose title has no city — the exact case the operator needs the address/phone header for.
    $loc = Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'Sump Pump Gurus',
        'address' => '123 Main St, Bridgewater, NJ 08807',
        'phone' => '(908) 555-0142',
        'primary_category' => 'Plumber',
        'served_towns' => [],
    ]);
    pbPage($site, PageType::Location, ContentStatus::Approved, 'Somerville', ['parent_location_id' => $loc->id]);

    Livewire::test(OperateLocationPages::class)
        ->assertOk()
        ->assertSee('Sump Pump Gurus')                    // GBP name up top
        ->assertSee('123 Main St, Bridgewater, NJ 08807') // full address
        ->assertSee('(908) 555-0142')                     // phone
        ->assertSee('Plumber');                           // primary category
});

it('surfaces the real failure reason on a failed row (not just the generic client line)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    // A publish-failed service page carries the real error on last_publish_error → operator tail.
    $failed = pbPage($site, PageType::Service, ContentStatus::PublishFailed, 'Water Detection & Leaks', [
        'last_publish_error' => 'fal render timed out for slot hero_image after 2 attempts',
    ]);

    Livewire::test(OperateServicePages::class)
        ->assertOk()
        ->assertSee('Water Detection & Leaks')
        ->assertSee('Something went wrong')                                  // the client line stays
        ->assertSee('fal render timed out for slot hero_image after 2 attempts'); // …AND the real reason now shows

    // The read model carries it on the row for the operator audience.
    $work = collect(app(PagesBoard::class)->services($site->fresh())['work'])->firstWhere('title', 'Water Detection & Leaks');
    expect($work['operator_tail'])->toContain('fal render timed out');
});

it('features a live page into the header menu and sets its order from the card', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]); // the board's working tenant
    $svc = pbPage($site, PageType::Service, ContentStatus::Published, 'Sump Pump Repair');

    $page = Livewire::test(OperateServicePages::class)
        ->assertOk()
        ->assertSee('In header menu')        // the checkbox renders on the live card
        ->assertSeeHtml('lv-navorder');      // the order field renders too (disabled until checked)

    // Not featured yet.
    expect($svc->fresh()->nav_featured)->toBeFalse();

    $page->call('toggleNavFeatured', $svc->id);
    expect($svc->fresh()->nav_featured)->toBeTrue();

    $page->call('setNavOrder', $svc->id, '2');
    expect($svc->fresh()->nav_order)->toBe(2);

    // Blank clears the order back to automatic.
    $page->call('setNavOrder', $svc->id, '');
    expect($svc->fresh()->nav_order)->toBeNull();

    // Toggling off removes it from the menu.
    $page->call('toggleNavFeatured', $svc->id);
    expect($svc->fresh()->nav_featured)->toBeFalse();
});

it('navState exposes each page\'s header-menu flags for the cards to render', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $a = pbPage($site, PageType::Service, ContentStatus::Published, 'Alpha', ['nav_featured' => true, 'nav_order' => 3]);
    $b = pbPage($site, PageType::Service, ContentStatus::Published, 'Bravo');

    $state = Livewire::test(OperateServicePages::class)->instance()->getNavStateProperty();

    expect($state[$a->id])->toBe(['featured' => true, 'order' => 3])
        ->and($state[$b->id])->toBe(['featured' => false, 'order' => null]);
});

it('flags a service-page row as needs-enrichment when its §1 service is thin', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);

    $thin = Service::factory()->create([
        'site_id' => $site->id, 'name' => 'Basement Waterproofing',
        'symptoms' => [], 'scope_items' => [], 'process_steps' => [], 'cost_factors' => [],
    ]);
    $rich = Service::factory()->create([
        'site_id' => $site->id, 'name' => 'Sump Pump Installation',
        'symptoms' => ['Water pooling'], 'scope_items' => ['New basin'],
    ]);
    pbPage($site, PageType::Service, ContentStatus::NeedsReview, 'Basement Waterproofing', ['primary_service_id' => $thin->id]);
    pbPage($site, PageType::Service, ContentStatus::NeedsReview, 'Sump Pump Installation', ['primary_service_id' => $rich->id]);

    $work = collect(app(PagesBoard::class)->services($site)['work'])->keyBy('title');

    expect($work['Basement Waterproofing']['needs_enrichment'])->toBeTrue()
        ->and($work['Sump Pump Installation']['needs_enrichment'])->toBeFalse();
});

it('flags a hub-page row as needs-generation when undrafted or spoke-less, but not once it has a spoke', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $siloWithSpoke = Silo::factory()->create(['site_id' => $site->id]);
    $emptySilo = Silo::factory()->create(['site_id' => $site->id]);

    // Undrafted hub (Candidate ⇒ empty slot payload) → flagged.
    pbPage($site, PageType::Hub, ContentStatus::Candidate, 'Water Detection & Leaks', ['silo_id' => $emptySilo->id]);
    // Drafted hub whose silo has a materialized spoke → not flagged.
    pbPage($site, PageType::Hub, ContentStatus::NeedsReview, 'Foundation Repair', ['silo_id' => $siloWithSpoke->id]);
    pbPage($site, PageType::Service, ContentStatus::Published, 'Wall Crack Repair', ['silo_id' => $siloWithSpoke->id]);

    $work = collect(app(PagesBoard::class)->services($site)['work'])->keyBy('title');

    expect($work['Water Detection & Leaks']['needs_generation'])->toBeTrue()
        ->and($work['Foundation Repair']['needs_generation'])->toBeFalse();
});

it('holds a hub publish when its spokes are not live yet — names them, dispatches nothing (report fix 2)', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drains']);
    $hub = pbPage($site, PageType::Hub, ContentStatus::Approved, 'Drainage', ['silo_id' => $silo->id]);
    // Two spokes in the silo, not published → the hub grid would link into dead ends.
    pbPage($site, PageType::Service, ContentStatus::NeedsReview, 'French Drains', ['silo_id' => $silo->id]);
    pbPage($site, PageType::Service, ContentStatus::Approved, 'Sump Pumps', ['silo_id' => $silo->id]);

    $board = Livewire::test(OperateServicePages::class)->call('publish', $hub->id);

    $board->assertSet('confirmingPublish', $hub->id);
    expect($board->get('confirmBlockers'))->toHaveCount(2);
    Queue::assertNotPushed(PublishContent::class);   // never published into dead links silently
});

it('does NOT hold a hub publish when every spoke is already live', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drains']);
    $hub = pbPage($site, PageType::Hub, ContentStatus::Approved, 'Drainage', ['silo_id' => $silo->id]);
    pbPage($site, PageType::Service, ContentStatus::Published, 'French Drains', ['silo_id' => $silo->id, 'wp_post_id' => 5]);

    Livewire::test(OperateServicePages::class)->call('publish', $hub->id)->assertSet('confirmingPublish', null);
    Queue::assertPushed(PublishContent::class);
});

it('push-spokes-first publishes the spokes then the hub', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drains']);
    $hub = pbPage($site, PageType::Hub, ContentStatus::Approved, 'Drainage', ['silo_id' => $silo->id]);
    pbPage($site, PageType::Service, ContentStatus::Approved, 'French Drains', ['silo_id' => $silo->id]);

    Livewire::test(OperateServicePages::class)
        ->call('publish', $hub->id)
        ->call('pushSpokesFirst')
        ->assertSet('confirmingPublish', null);

    // Spoke + hub both queued (idempotent-by-ULID PublishContent).
    Queue::assertPushed(PublishContent::class, 2);
});

it('publish-anyway overrides the guard and writes an audit row', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drains']);
    $hub = pbPage($site, PageType::Hub, ContentStatus::Approved, 'Drainage', ['silo_id' => $silo->id]);
    pbPage($site, PageType::Service, ContentStatus::NeedsReview, 'French Drains', ['silo_id' => $silo->id]);

    Livewire::test(OperateServicePages::class)
        ->call('publish', $hub->id)
        ->call('publishAnyway')
        ->assertSet('confirmingPublish', null);

    Queue::assertPushed(PublishContent::class, 1); // just the hub (override)
    expect(AuditLog::query()->where('action', AuditAction::DeadLinkOverride->value)->count())->toBe(1);
});

it('holds a town publish when its parent GBP hub page is unpublished (report fix 2)', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $hub = pbPage($site, PageType::Location, ContentStatus::NeedsReview, 'Montclair, NJ', ['location_id' => null]);
    $town = pbPage($site, PageType::Location, ContentStatus::Approved, 'Verona, NJ', ['parent_content_id' => $hub->id]);

    Livewire::test(OperateLocationPages::class)->call('publish', $town->id)->assertSet('confirmingPublish', $town->id);
    Queue::assertNotPushed(PublishContent::class);
});
