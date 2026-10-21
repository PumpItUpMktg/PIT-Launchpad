<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Places\PlaceReview;
use App\Integrations\Places\PlacesProvider;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\GbpProfile;
use App\Models\GbpReview;
use App\Models\Scopes\SiteScope;
use App\Roadmap\CompetitorProfiler;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/RoadmapFixtures.php';

it('profiles the office and its top map-pack competitors, with the backlink summary for a competitor with a domain', function () {
    ['site' => $site, 'office' => $office] = roadmapWorld();
    $places = scriptedPlaces([
        'PID_US' => roadmapProfile('PID_US', 'Sump Pump Gurus | Hackettstown', 4.7, 42, 'plumber', 6, 40.8540, -74.8290, 'https://sumppumpgurus.com/hackettstown-nj/'),
        'PID_ORIOLE' => roadmapProfile('PID_ORIOLE', 'Oriole Basement Waterproofing', 4.9, 180, 'waterproofing_company', 40, 40.86, -74.80, 'https://www.oriolebw.com'),
        'PID_DL' => roadmapProfile('PID_DL', 'D&L Pump and Well', 4.8, 95, 'well_drilling_contractor', 22, 40.90, -74.90),
        // PID_TSC is not scripted: Places cannot resolve it.
    ]);
    $backlinks = summaryBacklinks(['oriolebw.com' => [140, 31], 'dlpumpandwell.com' => [12, 8]]);

    $r = (new CompetitorProfiler($places, $backlinks))->refresh($site);

    expect($r)->toBe([
        'offices' => 1, 'competitors' => 3, 'profiled' => 3, 'unresolved' => 1, 'reviews' => 0,
        'unresolved_places' => [['place_id' => 'PID_TSC', 'name' => 'Tractor Supply', 'role' => 'competitor', 'beside' => 'Hackettstown']],
    ])
        ->and($places->asked)->toBe(['PID_US', 'PID_ORIOLE', 'PID_DL', 'PID_TSC']); // the office first, then winners by cells

    $rows = GbpProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('place_id');
    expect($rows)->toHaveCount(3)
        ->and($rows['PID_US']->location_id)->toBe((string) $office->id)
        ->and($rows['PID_US']->referring_domains)->toBeNull()   // our own site: no summary call
        ->and($rows['PID_US']->primary_type)->toBe('plumber')
        ->and($rows['PID_ORIOLE']->location_id)->toBeNull()
        ->and($rows['PID_ORIOLE']->domain)->toBe('oriolebw.com')
        ->and($rows['PID_ORIOLE']->referring_domains)->toBe(140)->and($rows['PID_ORIOLE']->authority)->toBe(31)
        ->and($rows['PID_ORIOLE']->ratings_total)->toBe(180)->and($rows['PID_ORIOLE']->photo_count)->toBe(40)
        ->and($rows['PID_DL']->domain)->toBe('dlpumpandwell.com')->and($rows['PID_DL']->referring_domains)->toBe(12);
});

it('caps the competitors per office and keeps a row Places can no longer resolve', function () {
    config(['launchpad.roadmap.competitors_per_office' => 1]);
    ['site' => $site] = roadmapWorld();
    $places = scriptedPlaces(['PID_US' => roadmapProfile('PID_US', 'Us', 4.7, 42, 'plumber', 6, 40.85, -74.83), 'PID_ORIOLE' => roadmapProfile('PID_ORIOLE', 'Oriole', 4.9, 180, 'plumber', 40, 40.86, -74.80)]);
    (new CompetitorProfiler($places, summaryBacklinks([])))->refresh($site);
    expect(GbpProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('place_id')->sort()->values()->all())->toBe(['PID_ORIOLE', 'PID_US']);

    $r = (new CompetitorProfiler(scriptedPlaces(['PID_US' => roadmapProfile('PID_US', 'Us', 4.7, 50, 'plumber', 6, 40.85, -74.83)]), summaryBacklinks([])))->refresh($site);
    expect($r['unresolved'])->toBe(1)
        ->and($r['unresolved_places'][0]['name'])->toBe('Oriole Basement Waterproofing')
        ->and(GbpProfile::withoutGlobalScope(SiteScope::class)->where('place_id', 'PID_ORIOLE')->exists())->toBeTrue()   // kept
        ->and(GbpProfile::withoutGlobalScope(SiteScope::class)->where('place_id', 'PID_US')->value('ratings_total'))->toBe(50); // refreshed
});

it('the refresh command names every place Places could not resolve, with its role and the office beside it', function () {
    ['site' => $site, 'office' => $office] = roadmapWorld();
    app()->instance(PlacesProvider::class, scriptedPlaces([
        'PID_ORIOLE' => roadmapProfile('PID_ORIOLE', 'Oriole', 4.9, 180, 'plumber', 40, 40.86, -74.80),
        // Neither our office (PID_US) nor Tractor Supply / D&L resolve.
    ]));
    app()->instance(BacklinkProvider::class, summaryBacklinks([]));

    $this->artisan('launchpad:refresh-gbp-profiles', ['--site' => $site->id])
        ->expectsOutputToContain('1 office(s), 3 map-pack competitor(s): 1 profile(s) refreshed, 0 Google review(s) stored, 3 Places could not resolve.')
        ->expectsOutputToContain('could not resolve: Hackettstown (office) — place PID_US')
        ->expectsOutputToContain('could not resolve: D&L Pump and Well (competitor, beside Hackettstown) — place PID_DL')
        ->expectsOutputToContain('could not resolve: Tractor Supply (competitor, beside Hackettstown) — place PID_TSC')
        ->assertSuccessful();
});

it('stores the office\'s own Google reviews, replaced whole each refresh — never a competitor\'s', function () {
    ['site' => $site, 'office' => $office] = roadmapWorld();
    $review = fn (string $who, int $stars, string $text, int $time) => new PlaceReview($who, null, $stars, $text, 'a month ago', $time);
    $places = scriptedPlaces([
        'PID_US' => roadmapProfile('PID_US', 'Us', 4.7, 42, 'plumber', 6, 40.85, -74.83, null, [$review('Dana', 5, 'Same-day fix.', 1760000000), $review('Pat', 4, 'Solid work.', 1750000000)]),
        'PID_ORIOLE' => roadmapProfile('PID_ORIOLE', 'Oriole', 4.9, 180, 'plumber', 40, 40.86, -74.80, null, [$review('Sam', 5, 'Competitor praise.', 1760000000)]),
    ]);
    $r = (new CompetitorProfiler($places, summaryBacklinks([])))->refresh($site);

    $rows = GbpReview::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderByDesc('reviewed_at')->get();
    expect($r['reviews'])->toBe(2)
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('author_name')->all())->toBe(['Dana', 'Pat'])
        ->and($rows[0]->location_id)->toBe((string) $office->id)->and($rows[0]->place_id)->toBe('PID_US')
        ->and($rows[0]->text)->toBe('Same-day fix.')->and($rows[0]->rating)->toBe(5)->and($rows[0]->relative_time)->toBe('a month ago')
        ->and($rows->pluck('text')->all())->not->toContain('Competitor praise.');

    // Next refresh: Google returns a different five — the old set is replaced, not accumulated.
    (new CompetitorProfiler(scriptedPlaces([
        'PID_US' => roadmapProfile('PID_US', 'Us', 4.7, 43, 'plumber', 6, 40.85, -74.83, null, [$review('Jo', 5, 'New one.', 1761000000)]),
    ]), summaryBacklinks([])))->refresh($site);
    expect(GbpReview::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('author_name')->all())->toBe(['Jo']);
});

it('the refresh command queues a re-push of the live location pages (hub + towns), unless --no-push', function () {
    Queue::fake();
    ['site' => $site, 'office' => $office] = roadmapWorld();
    app()->instance(PlacesProvider::class, scriptedPlaces([]));
    app()->instance(BacklinkProvider::class, summaryBacklinks([]));
    $hub = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'location_id' => $office->id, 'title' => 'Hackettstown', 'slug' => 'hackettstown-nj']);
    $town = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'parent_location_id' => $office->id, 'title' => 'Budd Lake', 'slug' => 'hackettstown-nj/budd-lake-nj']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Approved, 'parent_location_id' => $office->id, 'title' => 'Not live', 'slug' => 'x']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'title' => 'A service', 'slug' => 'svc']);

    $this->artisan('launchpad:refresh-gbp-profiles', ['--site' => $site->id])
        ->expectsOutputToContain('Queued a re-push of 2 live location page(s)')
        ->assertSuccessful();
    Queue::assertPushed(PublishContent::class, 2);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $hub->id);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $town->id);

    Queue::fake();
    $this->artisan('launchpad:refresh-gbp-profiles', ['--site' => $site->id, '--no-push' => true])->assertSuccessful();
    Queue::assertNothingPushed();
});
