<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

it('reports the wrong-town census read-only, then backfills + re-pushes only anchored pages on --execute', function () {
    Queue::fake();

    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services', 'silo_role' => ServiceSiloRole::Pillar]);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);

    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => '3401799999', 'name' => 'Neptune', 'state' => 'NJ',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);

    // Anchored town with a WRONG stored title (names Allentown) → a wrong-town correction.
    $wrong = Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401799999',
        'title' => 'Allentown, PA', 'slug' => 'neptune-nj',
        'meta' => ['seo' => ['title' => 'Sump Pump & Basement Water Services in Allentown, PA', 'meta_description' => 'x']],
    ]);

    // Un-anchored town (no geo_id) → skipped by --execute, never laundered.
    $unanchored = Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => null,
        'title' => 'Mystery, NJ', 'slug' => 'mystery-nj',
        'meta' => ['seo' => ['title' => 'Sump Pump Repair in Mystery, NJ', 'meta_description' => 'x']],
    ]);

    // Report-only: reads out, writes nothing, queues nothing.
    $this->artisan('launchpad:repush-location-titles --site='.$site->id)
        ->assertSuccessful()
        ->expectsOutputToContain('READ-ONLY');
    Queue::assertNothingPushed();
    expect($wrong->fresh()->meta['seo']['title'])->toBe('Sump Pump & Basement Water Services in Allentown, PA');

    // --execute: the anchored page's stored title is corrected (page portion, brand-free) + re-pushed;
    // the un-anchored page is left exactly as it was.
    $this->artisan('launchpad:repush-location-titles --site='.$site->id.' --execute')->assertSuccessful();

    expect($wrong->fresh()->meta['seo']['title'])->toBe('Sump Pump Services in Neptune, NJ')
        ->and($unanchored->fresh()->meta['seo']['title'])->toBe('Sump Pump Repair in Mystery, NJ');

    // Exactly one re-push — the anchored page. The un-anchored page is never queued.
    Queue::assertPushed(PublishContent::class, 1);
});
