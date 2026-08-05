<?php

use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Models\Content;
use App\Models\Location;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/** A pinned parent LANDING page for a physical location (the 301 target). */
function collapseParent(Site $site, string $slug): array
{
    $location = Location::factory()->create(['site_id' => $site->id]);
    $page = Content::factory()->create([
        'site_id' => $site->id,
        'page_type' => PageType::Location,
        'location_id' => $location->id,
        'parent_location_id' => null,
        'slug' => $slug,
        'title' => ucfirst(str_replace('-', ' ', $slug)),
    ]);

    return [$location, $page];
}

/** A nested TOWN child page under a parent location (parent_location_id set, no own pin). */
function collapseChild(Site $site, Location $parent, string $slug, string $title, ?int $wpPostId = 501): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'page_type' => PageType::Location,
        'location_id' => null,
        'parent_location_id' => $parent->id,
        'slug' => $slug,
        'title' => $title,
        'wp_post_id' => $wpPostId,
    ]);
}

it('dry-run reports the plan and writes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    [$loc] = collapseParent($site, 'doylestown-pa');
    collapseChild($site, $loc, 'doylestown-pa/middletown-nj', 'Middletown, NJ');

    $this->artisan('launchpad:collapse-town-children', ['--site' => 'Sump Pump Gurus'])
        ->assertSuccessful();

    expect(Redirect::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and(Content::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNull('location_id')->whereNotNull('parent_location_id')->count())->toBe(1); // still there
});

it('apply 301s each child to its parent and soft-deletes the thin child', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    [$loc, $parentPage] = collapseParent($site, 'doylestown-pa');
    $child = collapseChild($site, $loc, 'doylestown-pa/buckingham-pa', 'Buckingham, PA');

    $this->artisan('launchpad:collapse-town-children', ['--site' => 'Sump Pump Gurus', '--apply' => true])
        ->assertSuccessful();

    // 301 written: child path → parent path.
    $redirect = Redirect::query()->where('site_id', $site->id)->where('from_url', '/doylestown-pa/buckingham-pa')->first();
    expect($redirect)->not->toBeNull()
        ->and($redirect->to_url)->toBe('/doylestown-pa')
        ->and($redirect->code)->toBe(301)
        ->and($redirect->source)->toBe(RedirectSource::Migration);

    // Child soft-deleted; parent landing untouched.
    expect($child->fresh()->trashed())->toBeTrue()
        ->and($parentPage->fresh()->trashed())->toBeFalse();
});

it('leaves an orphan (no parent landing page) untouched — never deleted without a target', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    // A child whose parent_location_id points to a Location that has NO landing page.
    $orphanLocation = Location::factory()->create(['site_id' => $site->id]);
    $orphan = collapseChild($site, $orphanLocation, 'ghost-town-nj', 'Ghost Town, NJ');

    $this->artisan('launchpad:collapse-town-children', ['--site' => 'Sump Pump Gurus', '--apply' => true])
        ->assertSuccessful();

    expect(Redirect::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and($orphan->fresh()->trashed())->toBeFalse();
});

it('never touches a pinned landing page or a non-location page', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    [$loc, $landing] = collapseParent($site, 'trooper-pa');
    collapseChild($site, $loc, 'trooper-pa/norristown-pa', 'Norristown, PA');
    $service = Content::factory()->create([
        'site_id' => $site->id, 'page_type' => PageType::Service, 'slug' => 'basement-waterproofing', 'title' => 'Basement Waterproofing',
    ]);

    $this->artisan('launchpad:collapse-town-children', ['--site' => 'Sump Pump Gurus', '--apply' => true])
        ->assertSuccessful();

    expect($landing->fresh()->trashed())->toBeFalse()      // the pinned parent stays
        ->and($service->fresh()->trashed())->toBeFalse()    // a service page is out of scope
        ->and(Redirect::query()->where('site_id', $site->id)->count())->toBe(1); // only the one town child
});

it('errors when the site is unknown', function () {
    $this->artisan('launchpad:collapse-town-children', ['--site' => 'Nope Inc'])->assertFailed();
});
