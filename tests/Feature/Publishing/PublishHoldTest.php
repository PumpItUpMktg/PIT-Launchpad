<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Locations\LocationPublishHold;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\PublishContentService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/** A town page (page_type Location) owned by $location via parent_location_id, in a publishable status. */
function heldTownPage(Site $site, Location $location, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'status' => ContentStatus::Approved,
        'parent_location_id' => $location->id,
        'location_id' => null,
    ], $attrs));
}

// ── The seam: production-path creation comes out held (the Fallston regression) ────────────────────

it('defaults a location created through a production path (Eloquent, not the factory) to held', function () {
    $site = Site::factory()->create();

    // Eloquent create is the seam EVERY production path funnels through (import, Places, manual, form,
    // onboarding). A future creation route that forgets to set the flag still comes out held.
    $loc = Location::create(['site_id' => $site->id, 'name' => 'Fallston']);

    expect($loc->fresh()->publish_held)->toBeTrue()
        // The factory follows production — held by default; nothing publishes a location without a
        // conscious opt-in (so the held path is the default a test must leave, not a fixture-arranged one).
        ->and(Location::factory()->create(['site_id' => $site->id])->publish_held)->toBeTrue();
});

it('lets a caller that has reviewed the location opt out explicitly', function () {
    $site = Site::factory()->create();

    expect(Location::create(['site_id' => $site->id, 'name' => 'Reviewed', 'publish_held' => false])->fresh()->publish_held)->toBeFalse()
        // The ->released() factory state is the conscious opt-in for tests that publish a location's pages.
        ->and(Location::factory()->released()->create(['site_id' => $site->id])->publish_held)->toBeFalse();
});

// ── The predicate ─────────────────────────────────────────────────────────────────────────────────

it('resolves isPublishHeld through a town\'s parent location and a hub\'s own location', function () {
    $site = Site::factory()->create();
    $held = Location::factory()->create(['site_id' => $site->id, 'publish_held' => true]);
    $free = Location::factory()->released()->create(['site_id' => $site->id]);

    $town = heldTownPage($site, $held);
    $hub = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => $held->id, 'parent_location_id' => null]);
    $freeTown = heldTownPage($site, $free);
    $service = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'location_id' => null, 'parent_location_id' => null]);

    expect($town->isPublishHeld())->toBeTrue()
        ->and($hub->isPublishHeld())->toBeTrue()
        ->and($freeTown->isPublishHeld())->toBeFalse()
        ->and($service->isPublishHeld())->toBeFalse(); // a page with no location pin is never held
});

// ── Gate 1: publish() never pushes a held page ─────────────────────────────────────────────────────

it('publish() skips a held location\'s page — no status change, never reaches WordPress', function () {
    Http::fake(); // any WP call would be recorded
    $site = Site::factory()->create();
    $held = Location::factory()->create(['site_id' => $site->id, 'publish_held' => true]);
    $page = heldTownPage($site, $held);

    $result = app(PublishContentService::class)->publish($page);

    expect($result->wasSkipped())->toBeTrue()
        ->and($page->fresh()->status)->toBe(ContentStatus::Approved) // untouched — a later release re-publishes
        ->and($page->fresh()->wp_post_id)->toBeNull();
    Http::assertNothingSent();
});

// ── Gate 3: held URLs are never announced to IndexNow ──────────────────────────────────────────────

// submit() takes raw URLs; it deliberately does NOT reverse-match strings back to Content (a trailing-slash
// mismatch would fail open — announce a held page while looking protected). Held filtering lives where a
// Content/query exists: submitSite() (below) and LinkPlanCommitter (its own test). submit()'s only callers
// are those + the auto on-publish ping, which is Gate-1-covered (a held page never reaches it).

it('IndexNowSubmitter::submitSite excludes a held location\'s live pages', function () {
    Http::fake(['*api.indexnow.org*' => Http::response('', 200)]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'indexnow_key' => 'abcdef1234567890']);

    $held = Location::factory()->create(['site_id' => $site->id, 'publish_held' => true]);
    $free = Location::factory()->released()->create(['site_id' => $site->id]);
    heldTownPage($site, $held, ['slug' => 'held-town', 'status' => ContentStatus::Published, 'wp_post_id' => 1]);
    heldTownPage($site, $free, ['slug' => 'free-town', 'status' => ContentStatus::Published, 'wp_post_id' => 2]);

    $wp = Mockery::mock(WordpressClient::class);
    $wp->shouldReceive('pushIndexNowKey')->andReturn(['stored' => true]); // submitSite redeploys the key
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($wp);
    $submitter = new IndexNowSubmitter(app(Factory::class), $factory, 'https://api.indexnow.org/indexnow', true, 15);

    $submitter->submitSite($site);

    // Canonical trailing-slash form (PublicUrl) — never the slug-only variant that 301-redirects.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.indexnow.org')
        && ! in_array('https://spg.example/held-town/', $r['urlList'], true)  // held live page → excluded
        && in_array('https://spg.example/free-town/', $r['urlList'], true));  // released → announced
});

// ── The service: state + deliberate take-down ─────────────────────────────────────────────────────

it('counts a held location\'s still-live pages and takes them down only on the explicit call', function () {
    Http::fake();
    $site = Site::factory()->create();
    $held = Location::factory()->create(['site_id' => $site->id, 'publish_held' => true]);
    heldTownPage($site, $held, ['status' => ContentStatus::Published, 'wp_post_id' => 1]);
    heldTownPage($site, $held, ['status' => ContentStatus::Published, 'wp_post_id' => 2]);
    heldTownPage($site, $held, ['status' => ContentStatus::Approved]); // not live → not counted

    // Instance the WP mock BEFORE resolving the service, so DeleteFromWordpress (its dependency) is built
    // with the mock factory.
    $wp = Mockery::mock(WordpressClient::class);
    $wp->shouldReceive('deleteContent')->twice()->andReturn(['deleted' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($wp);
    app()->instance(WordpressClientFactory::class, $factory);

    $svc = app(LocationPublishHold::class);
    expect($svc->liveCount($held))->toBe(2)
        ->and($svc->takeDownLivePages($held))->toBe(2); // only the deliberate call removes live pages
});

it('hold and release flip the flag', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->released()->create(['site_id' => $site->id]);
    $svc = app(LocationPublishHold::class);

    expect($svc->hold($loc)->fresh()->publish_held)->toBeTrue()
        ->and($svc->release($loc)->fresh()->publish_held)->toBeFalse();
});
