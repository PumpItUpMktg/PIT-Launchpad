<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use App\Publishing\Seo\LocationTitleReport;
use Illuminate\Support\Facades\Cache;
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

it('a place-only "{Town}, {ST}" stored title reads as the correct town, not missing', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => '3401700009', 'name' => 'Bayonne', 'state' => 'NJ',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);

    // The deterministic place-only form (no trade) — stored title leads with the town, no "in".
    Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401700009',
        'title' => 'Bayonne, NJ', 'slug' => 'bayonne-nj',
        'meta' => ['seo' => ['title' => 'Bayonne, NJ', 'meta_description' => 'x']],
    ]);

    $entry = app(LocationTitleReport::class)->forSite($site->fresh());
    $bayonne = collect($entry['pages'])->firstWhere('slug', 'bayonne-nj');

    // The town IS named (as the title lead), so it's correct — not a false "missing-town".
    expect($bayonne['status'])->toBe('correct')
        ->and($entry['missing_town'])->toBe(0)
        ->and($entry['wrong_town'])->toBe(0);
});

it('a county-qualified stored title is the correct town, and an already-correct page is not re-pushed; a wrong-town H1 is', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services', 'silo_role' => ServiceSiloRole::Pillar]);
    $doylestown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown office']);
    $downingtown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Downingtown office']);
    // Two real Newtowns in PA → county-qualified labels; Chalfont stays plain.
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4201753424', 'name' => 'Newtown', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$doylestown->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4202953424', 'name' => 'Newtown', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$downingtown->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4201718000', 'name' => 'Chalfont', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$doylestown->id]]);
    Cache::flush();
    $gaz = Mockery::mock(MunicipalityGazetteer::class)->shouldIgnoreMissing([]);
    $gaz->shouldReceive('countiesInState')->with('42')->andReturn([
        new County('42017', 'Bucks', '42', '017'),
        new County('42029', 'Chester County', '42', '029'),
    ]);
    app()->instance(MunicipalityGazetteer::class, $gaz);

    $page = fn (Location $parent, string $geo, string $slug, string $storedTitle, array $slots = []): Content => Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'wp_post_id' => random_int(100, 999),
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => $geo, 'title' => 'Newtown, PA', 'slug' => $slug,
        'meta' => ['seo' => ['title' => $storedTitle, 'meta_description' => 'x']], 'slot_payload' => $slots,
    ]);
    // Already backfilled: the stored title IS the county-qualified deterministic one (what SPG's 28 rows looked like).
    $bucks = $page($doylestown, '4201753424', 'doylestown-pa/newtown-pa', 'Sump Pump Services in Newtown, Bucks County, PA');
    // Correct plain title, but a drafted H1 naming another town → re-pushed for the H1.
    $chalfont = $page($doylestown, '4201718000', 'doylestown-pa/chalfont-pa', 'Sump Pump Services in Chalfont, PA', ['hero_headline' => 'Sump Pump Services in Allentown, PA']);
    // Genuinely wrong stored title → re-pushed.
    $chester = $page($downingtown, '4202953424', 'downingtown-pa/newtown-pa', 'Sump Pump Services in Allentown, PA');

    $census = app(LocationTitleReport::class)->forSite($site);
    $bySlug = collect($census['pages'])->keyBy('slug');
    expect($census['wrong_town'])->toBe(1)
        ->and($census['h1_mismatch'])->toBe(1)
        ->and($census['needs_push'])->toBe(2)
        ->and($bySlug['doylestown-pa/newtown-pa']['status'])->toBe('correct')   // the county qualifier is the right town, not a wrong one
        ->and($bySlug['doylestown-pa/newtown-pa']['needs_push'])->toBeFalse()
        ->and($bySlug['doylestown-pa/chalfont-pa']['status'])->toBe('correct')
        ->and($bySlug['doylestown-pa/chalfont-pa']['needs_push'])->toBeTrue()
        ->and($bySlug['downingtown-pa/newtown-pa']['status'])->toBe('wrong_town');

    $this->artisan('launchpad:repush-location-titles --site='.$site->id)
        ->expectsOutputToContain('1 wrong-town · 0 missing-town · 1 H1-mismatch · 2 render <title> over 60 · 2 would be re-pushed')
        ->assertSuccessful();

    $this->artisan('launchpad:repush-location-titles --site='.$site->id.' --execute')
        ->expectsOutputToContain('queued 2 PublishContent job(s)')
        ->assertSuccessful();
    Queue::assertPushed(PublishContent::class, 2);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $chester->id);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $chalfont->id);
    expect($chester->fresh()->meta['seo']['title'])->toBe('Sump Pump Services in Newtown, Chester County, PA')
        ->and($bucks->fresh()->meta['seo']['title'])->toBe('Sump Pump Services in Newtown, Bucks County, PA');
});
