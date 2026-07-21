<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Locations\Reconcile\LocationNapReconciler;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function bareLocation(Site $site, array $o = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Sump Pump Gurus', 'address' => '12 Main St, Trooper PA',
        'phone' => '(610) 555-0142', 'is_storefront' => true,
        'place_id' => null, 'address_components' => null, 'lat' => null, 'lng' => null, 'hours' => null,
    ], $o));
}

function gbpLocation(Site $site, array $o = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Sump Pump Gurus', 'address' => '12 Main Street, Trooper, PA 19403',
        'phone' => '610-555-0142', 'place_id' => 'ChIJabc123',
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Trooper', 'short_name' => 'Trooper'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'Pennsylvania', 'short_name' => 'PA'],
        ],
        'lat' => 40.1345, 'lng' => -75.3401, 'gbp_url' => 'https://maps.google.com/?cid=1',
        'hours' => ['monday' => '8-5'],
    ], $o));
}

it('folds a bare row into its GBP sibling matched by phone, back-filling the missing NAP', function () {
    $site = Site::factory()->create();
    $bare = bareLocation($site);
    $gbp = gbpLocation($site);

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true);

    expect($merges)->toHaveCount(1)
        ->and($merges[0]->matchedOn)->toBe('phone');

    // No live hub anywhere → the enriched row survives; the bare row is tombstoned (hidden).
    $survivor = $gbp->fresh();
    expect($survivor->merged_into_id)->toBeNull();
    expect(Location::withoutGlobalScope(SiteScope::class)->find($bare->id))->toBeNull(); // hidden by scope
    $tombstoned = Location::withoutGlobalScopes()->find($bare->id);
    expect($tombstoned->merged_into_id)->toBe($gbp->id)
        ->and($tombstoned->place_id)->toBeNull();
});

it('keeps the row with a LIVE hub page as the survivor so the URL never changes', function () {
    $site = Site::factory()->create();
    $bare = bareLocation($site);
    $gbp = gbpLocation($site);
    // The live location page is pinned to the BARE row.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $bare->id, 'status' => ContentStatus::Published, 'wp_post_id' => 55, 'slug' => 'trooper-pa',
    ]);

    app(LocationNapReconciler::class)->reconcile($site, apply: true);

    // Survivor = the bare row (its URL is live); it inherited the GBP geo; the enriched row is tombstoned.
    $survivor = Location::withoutGlobalScope(SiteScope::class)->find($bare->id);
    expect($survivor)->not->toBeNull()
        ->and($survivor->place_id)->toBe('ChIJabc123')
        ->and((float) $survivor->lat)->toBe(40.1345)
        ->and($survivor->address_components)->not->toBeNull();
    expect(Location::withoutGlobalScopes()->find($gbp->id)->merged_into_id)->toBe($bare->id);
});

it('re-points every Content pin from the duplicate to the survivor', function () {
    $site = Site::factory()->create();
    $bare = bareLocation($site);
    $gbp = gbpLocation($site);
    // A town page parented to the (to-be-tombstoned) bare row.
    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'parent_location_id' => $bare->id, 'location_id' => null, 'primary_service_id' => null, 'slug' => 'audubon',
    ]);

    app(LocationNapReconciler::class)->reconcile($site, apply: true);

    // bare tombstoned (no live hub) → town re-parents onto the survivor (gbp).
    expect($town->fresh()->parent_location_id)->toBe($gbp->id);
});

it('never overwrites operator-entered values on the survivor', function () {
    $site = Site::factory()->create();
    // The survivor (gbp) already has its own phone; a different bare phone must NOT clobber it.
    $bare = bareLocation($site, ['phone' => '610-555-0142', 'email' => 'ops@spg.example']);
    $gbp = gbpLocation($site, ['phone' => '610-555-0142', 'email' => 'gbp@spg.example']);

    app(LocationNapReconciler::class)->reconcile($site, apply: true);

    expect($gbp->fresh()->email)->toBe('gbp@spg.example'); // survivor's own value kept
});

it('leaves ambiguous matches (two GBP candidates on the same phone) untouched', function () {
    $site = Site::factory()->create();
    bareLocation($site);
    gbpLocation($site, ['place_id' => 'ChIJaaa']);
    gbpLocation($site, ['place_id' => 'ChIJbbb']); // same phone → ambiguous

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true);

    expect($merges)->toBe([]);
    expect(Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(3);
});

it('dry-run reports the fold without mutating anything', function () {
    $site = Site::factory()->create();
    $bare = bareLocation($site);
    gbpLocation($site);

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: false);

    expect($merges)->toHaveCount(1);
    expect($bare->fresh()->merged_into_id)->toBeNull(); // nothing written
    expect(Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});
