<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Locations\Reconcile\LocationNapReconciler;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

it('folds two bare rows for one shop (same phone + name), keeping the row with more pages', function () {
    $site = Site::factory()->create();
    $first = bareLocation($site, ['name' => 'Sump Pump Gurus | Trooper', 'phone' => '(484) 808-2225', 'address' => '1 Ridge Pike, Trooper PA']);
    $second = bareLocation($site, ['name' => 'Sump Pump Gurus | Trooper', 'phone' => '484-808-2225', 'address' => null]);
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $second->id, 'status' => ContentStatus::NeedsReview, 'slug' => 'trooper',
    ]);

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true);

    expect($merges)->toHaveCount(1)
        ->and($merges[0]->matchedOn)->toBe('phone+name')
        ->and($merges[0]->survivorId)->toBe($second->id)   // more pages pinned → survives
        ->and($merges[0]->backfilled)->toContain('address') // and inherits the address the other row had
        ->and($page->fresh()->location_id)->toBe($second->id)
        ->and(Location::withoutGlobalScopes()->find($first->id)->merged_into_id)->toBe($second->id)
        ->and($second->fresh()->address)->toBe('1 Ridge Pike, Trooper PA');
});

it('folds the same Google listing imported twice (identical place_id), keeping the live hub', function () {
    $site = Site::factory()->create();
    $a = gbpLocation($site, ['place_id' => 'ChIJsame']);
    $b = gbpLocation($site, ['place_id' => 'ChIJsame']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $b->id, 'status' => ContentStatus::Published, 'wp_post_id' => 9, 'slug' => 'trooper-pa',
    ]);

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true);

    expect($merges)->toHaveCount(1)
        ->and($merges[0]->matchedOn)->toBe('place_id')
        ->and($merges[0]->survivorId)->toBe($b->id)
        ->and(Location::withoutGlobalScopes()->find($a->id)->merged_into_id)->toBe($b->id);
});

it('never folds two DISTINCT Google listings that share a phone or address, nor a bare trio', function () {
    $site = Site::factory()->create();
    gbpLocation($site, ['place_id' => 'ChIJaaa']);
    gbpLocation($site, ['place_id' => 'ChIJbbb']); // same phone + address, different listing → two listings
    foreach (range(1, 3) as $i) {
        bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'address' => null]); // three → ambiguous
    }

    expect(app(LocationNapReconciler::class)->reconcile($site, apply: true))->toBe([])
        ->and(Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(5);
});

it('never pairs rows across tenants — an identical location on another client is invisible to the fold', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    // The same shop, same listing, same phone, same name — on TWO clients (a franchise, or a copied site).
    $a = gbpLocation($siteA, ['place_id' => 'ChIJshared', 'name' => 'Sump Pump Gurus | Trooper']);
    $b = gbpLocation($siteB, ['place_id' => 'ChIJshared', 'name' => 'Sump Pump Gurus | Trooper']);
    bareLocation($siteA, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'address' => null]);
    bareLocation($siteB, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'address' => null]);

    $reconciler = app(LocationNapReconciler::class);
    expect($reconciler->reconcile($siteA, apply: true))->toBe([])
        ->and($reconciler->reconcile($siteB, apply: true))->toBe([]);

    // Nothing tombstoned on either tenant; every row still belongs to the site it was created on.
    expect(Location::withoutGlobalScopes()->whereNotNull('merged_into_id')->count())->toBe(0)
        ->and($a->fresh()->site_id)->toBe($siteA->id)
        ->and($b->fresh()->site_id)->toBe($siteB->id);
});

// ── The Roslyn case: two identical rows, the operator says which survives, and the history follows ──

it('--keep=newest keeps the newer row of a pair even when the older one owns the live hub and the pages', function () {
    $site = Site::factory()->create();
    $older = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '(862) 289-7867', 'address' => '1405 Old Northern Boulevard, Roslyn NY', 'created_at' => now()->subDays(30)]);
    $newer = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'address' => '1405 Old Northern Blvd, Roslyn NY', 'created_at' => now()->subDay()]);
    $hub = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $older->id, 'status' => ContentStatus::Published, 'wp_post_id' => 77, 'slug' => 'roslyn',
    ]);

    // The ranking alone keeps the older row (live hub).
    $plan = app(LocationNapReconciler::class)->reconcile($site, apply: false);
    expect($plan)->toHaveCount(1)->and($plan[0]->survivorId)->toBe($older->id);

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true, keep: LocationNapReconciler::KEEP_NEWEST);

    expect($merges)->toHaveCount(1)
        ->and($merges[0]->survivorId)->toBe($newer->id)
        ->and($merges[0]->contentRepointed)->toBe(1)
        ->and($hub->fresh()->location_id)->toBe($newer->id)                       // the page follows — the URL is unchanged
        ->and(Location::withoutGlobalScopes()->find($older->id)->merged_into_id)->toBe($newer->id)
        ->and($newer->fresh()->merged_into_id)->toBeNull();

    expect(fn () => app(LocationNapReconciler::class)->reconcile($site, apply: false, keep: 'middle'))->toThrow(InvalidArgumentException::class);
});

it('carries the duplicate\'s history to the survivor: grid scans, the scan plan it lacked, channels, and coverage-area sources', function () {
    $site = Site::factory()->create();
    $older = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '(862) 289-7867', 'created_at' => now()->subDays(30)]);
    $newer = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'created_at' => now()->subDay()]);
    $other = bareLocation($site, ['name' => 'Sump Pump Gurus | Montclair', 'phone' => '862-292-0566']);
    $now = now();

    DB::table('geo_grid_scans')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'location_id' => $older->id, 'keyword_id' => (string) Str::ulid(),
        'grid_size' => 7, 'spacing_miles' => 1, 'center_lat' => 40.8, 'center_lng' => -73.65, 'zoom' => 13, 'depth_cap' => 20,
        'status' => 'done', 'scanned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('coverage_scan_plans')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'location_id' => $older->id, 'keyword_ids' => '[]', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('social_channels')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'location_id' => $older->id, 'network' => 'gbp', 'provider_channel_id' => 'gbp-roslyn', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $areaId = (string) Str::ulid();
    DB::table('coverage_areas')->insert([
        'id' => $areaId, 'site_id' => $site->id, 'geo_id' => '3661000', 'name' => 'Roslyn', 'source_location_ids' => json_encode([$other->id, $older->id]), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $untouched = (string) Str::ulid();
    DB::table('coverage_areas')->insert([
        'id' => $untouched, 'site_id' => $site->id, 'geo_id' => '3447500', 'name' => 'Montclair', 'source_location_ids' => json_encode([$other->id]), 'created_at' => $now, 'updated_at' => $now,
    ]);

    $plan = app(LocationNapReconciler::class)->reconcile($site, apply: false, keep: LocationNapReconciler::KEEP_NEWEST);
    expect($plan[0]->historyRepointed)->toBe(4)   // scan + plan + channel + one coverage area
        ->and(DB::table('geo_grid_scans')->where('location_id', $older->id)->count())->toBe(1);   // dry-run wrote nothing

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true, keep: LocationNapReconciler::KEEP_NEWEST);

    expect($merges[0]->historyRepointed)->toBe(4)
        ->and(DB::table('geo_grid_scans')->where('location_id', $newer->id)->count())->toBe(1)
        ->and(DB::table('coverage_scan_plans')->where('location_id', $newer->id)->count())->toBe(1)
        ->and(DB::table('social_channels')->where('location_id', $newer->id)->count())->toBe(1)
        ->and(json_decode(DB::table('coverage_areas')->where('id', $areaId)->value('source_location_ids'), true))->toBe([$other->id, $newer->id])
        ->and(json_decode(DB::table('coverage_areas')->where('id', $untouched)->value('source_location_ids'), true))->toBe([$other->id])
        ->and(DB::table('geo_grid_scans')->where('location_id', $older->id)->count())->toBe(0)
        ->and($merges[0]->summary())->toContain('4 history row(s) carried over');
});

it('leaves the survivor\'s own scan plan alone when both rows had one (one plan per location)', function () {
    $site = Site::factory()->create();
    $older = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '(862) 289-7867', 'created_at' => now()->subDays(30)]);
    $newer = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'created_at' => now()->subDay()]);
    $now = now();
    DB::table('coverage_scan_plans')->insert([
        ['id' => (string) Str::ulid(), 'site_id' => $site->id, 'location_id' => $older->id, 'keyword_ids' => '["a"]', 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::ulid(), 'site_id' => $site->id, 'location_id' => $newer->id, 'keyword_ids' => '["b"]', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $merges = app(LocationNapReconciler::class)->reconcile($site, apply: true, keep: LocationNapReconciler::KEEP_NEWEST);

    expect($merges[0]->historyRepointed)->toBe(0)
        ->and(DB::table('coverage_scan_plans')->where('location_id', $newer->id)->value('keyword_ids'))->toBe('["b"]')
        ->and(DB::table('coverage_scan_plans')->where('location_id', $older->id)->count())->toBe(1);   // stays on the tombstone, never collides
});

it('the command takes --keep=newest|oldest and refuses anything else', function () {
    $site = Site::factory()->create();
    bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '(862) 289-7867', 'created_at' => now()->subDays(30)]);
    $newer = bareLocation($site, ['name' => 'Sump Pump Gurus | Roslyn', 'phone' => '862-289-7867', 'created_at' => now()->subDay()]);

    $this->artisan('launchpad:reconcile-locations', ['--site' => $site->id, '--keep' => 'middle'])
        ->expectsOutputToContain("--keep must be 'newest' or 'oldest'.")
        ->assertFailed();

    $this->artisan('launchpad:reconcile-locations', ['--site' => $site->id, '--keep' => 'newest'])
        ->expectsOutputToContain('Survivor override: the newest row of every pair is kept.')
        ->expectsOutputToContain('would fold "Sump Pump Gurus | Roslyn" ('.$newer->id.')')
        ->assertSuccessful();

    expect(Location::withoutGlobalScopes()->whereNotNull('merged_into_id')->count())->toBe(0);   // dry-run

    $this->artisan('launchpad:reconcile-locations', ['--site' => $site->id, '--keep' => 'newest', '--force' => true])->assertSuccessful();
    expect($newer->fresh()->merged_into_id)->toBeNull()
        ->and(Location::withoutGlobalScopes()->whereNotNull('merged_into_id')->count())->toBe(1);
});
