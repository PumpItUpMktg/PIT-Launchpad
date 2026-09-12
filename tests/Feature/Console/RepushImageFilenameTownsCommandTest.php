<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\RenderJob;
use App\Models\Site;
use App\Publishing\Seo\ImageFilenameTownReport;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * An anchored Neptune page whose hero was rendered under the OLD (Allentown) grounding — its R2 key and
 * responsive variant carry "allentown-pa" — plus a proof image whose key already names Neptune, and a
 * third whose "-pa" token follows a trade word, not a place. Allentown is a known coverage area of the site.
 *
 * @return array{0: Site, 1: Content, 2: RenderJob, 3: RenderJob, 4: RenderJob}
 */
function filenameTownFixture(): array
{
    Storage::fake('r2');

    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hub office']);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => '3402549890', 'name' => 'Neptune', 'state' => 'NJ',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => '4207702000', 'name' => 'Allentown', 'state' => 'PA',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);

    $page = Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3402549890',
        'title' => 'Neptune, NJ', 'slug' => 'new-brunswick-nj/neptune-nj', 'wp_post_id' => 3367,
    ]);

    $prefix = 'sites/'.$site->id;
    Storage::disk('r2')->put($prefix.'/sump-pump-repair-allentown-pa.jpg', 'hero-bytes');
    Storage::disk('r2')->put($prefix.'/sump-pump-repair-allentown-pa-400w.webp', 'variant-bytes');
    Storage::disk('r2')->put($prefix.'/dry-neptune-nj-basement.jpg', 'proof-bytes');
    Storage::disk('r2')->put($prefix.'/sump-pump-service-pa.jpg', 'generic-bytes');

    $stale = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'r2_key' => $prefix.'/sump-pump-repair-allentown-pa.jpg',
        'variants' => ['400' => $prefix.'/sump-pump-repair-allentown-pa-400w.webp'],
        'seo_filename' => 'sump-pump-repair-allentown-pa.jpg', 'width' => 1200, 'height' => 675,
    ]);
    $clean = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'proof_1',
        'r2_key' => $prefix.'/dry-neptune-nj-basement.jpg',
    ]);
    $generic = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'proof_2',
        'r2_key' => $prefix.'/sump-pump-service-pa.jpg',
    ]);

    return [$site, $page, $stale, $clean, $generic];
}

it('report-only lists the wrong-town file (and its variant) as current → proposed and copies nothing', function () {
    Queue::fake();
    [$site, , $stale] = filenameTownFixture();
    $prefix = 'sites/'.$site->id;

    $rows = app(ImageFilenameTownReport::class)->forSite($site->fresh())['rows'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['job_id'])->toBe((string) $stale->id)
        ->and($rows[0]['proposed'])->toBe($prefix.'/sump-pump-repair-neptune-nj.jpg')
        ->and($rows[0]['variants'])->toBe(['400' => [
            $prefix.'/sump-pump-repair-allentown-pa-400w.webp',
            $prefix.'/sump-pump-repair-neptune-nj-400w.webp',
        ]]);

    $this->artisan('launchpad:repush-image-filename-towns --site='.$site->id)
        ->assertSuccessful()
        ->expectsOutputToContain('new-brunswick-nj/neptune-nj')
        ->expectsOutputToContain('READ-ONLY');

    // Nothing copied, nothing repointed, nothing pushed.
    Storage::disk('r2')->assertMissing($prefix.'/sump-pump-repair-neptune-nj.jpg');
    expect($stale->fresh()->r2_key)->toBe($prefix.'/sump-pump-repair-allentown-pa.jpg');
    Queue::assertNothingPushed();
});

it('--execute copies the object + variant to the town-correct keys, repoints the job, keeps the old objects, and queues one repush', function () {
    Queue::fake();
    [$site, , $stale, $clean, $generic] = filenameTownFixture();
    $prefix = 'sites/'.$site->id;

    $this->artisan('launchpad:repush-image-filename-towns --site='.$site->id.' --execute')
        ->assertSuccessful()
        ->expectsOutputToContain('Renamed 1 image(s)');

    // The bytes now live under the Neptune name (same content) — and the old key is still there for the
    // live page until the repush lands.
    Storage::disk('r2')->assertExists($prefix.'/sump-pump-repair-neptune-nj.jpg');
    Storage::disk('r2')->assertExists($prefix.'/sump-pump-repair-neptune-nj-400w.webp');
    Storage::disk('r2')->assertExists($prefix.'/sump-pump-repair-allentown-pa.jpg');
    expect(Storage::disk('r2')->get($prefix.'/sump-pump-repair-neptune-nj.jpg'))->toBe('hero-bytes');

    $stale = $stale->fresh();
    expect($stale->r2_key)->toBe($prefix.'/sump-pump-repair-neptune-nj.jpg')
        ->and($stale->variants)->toBe(['400' => $prefix.'/sump-pump-repair-neptune-nj-400w.webp'])
        ->and($stale->seo_filename)->toBe('sump-pump-repair-neptune-nj.jpg')
        ->and($stale->toImageObject()['url'])->toContain('sump-pump-repair-neptune-nj.jpg')
        ->and($clean->fresh()->r2_key)->toBe($prefix.'/dry-neptune-nj-basement.jpg')     // own town — untouched
        ->and($generic->fresh()->r2_key)->toBe($prefix.'/sump-pump-service-pa.jpg');     // "service" is not a place

    // One affected page → exactly one PublishContent (idempotent by ULID), not one per file.
    Queue::assertPushed(PublishContent::class, 1);
});

it('rewrites only known foreign places, never a trade word, a state list, the brand, or the own town', function () {
    $report = app(ImageFilenameTownReport::class);
    $known = ['allentown' => true, 'lower-macungie' => true, 'neptune' => true, 'west-orange' => true, 'plainfield' => true, 'north-plainfield' => true];

    expect($report->rewrite('sump-pump-repair-allentown-pa.jpg', $known, 'neptune', 'neptune-nj', 'sump-pump-gurus'))
        ->toBe('sump-pump-repair-neptune-nj.jpg')
        ->and($report->rewrite('allentown-pa-sump-pump-repair-service.jpg', $known, 'neptune', 'neptune-nj', 'sump-pump-gurus'))
        ->toBe('neptune-nj-sump-pump-repair-service.jpg')                  // leading position
        ->and($report->rewrite('lower-macungie-pa-hero-800w.webp', $known, 'neptune', 'neptune-nj', 'sump-pump-gurus'))
        ->toBe('neptune-nj-hero-800w.webp')                                // multi-token town, longest wins
        ->and($report->rewrite('sump-pump-service-pa.jpg', $known, 'neptune', 'neptune-nj', 'sump-pump-gurus'))
        ->toBe('sump-pump-service-pa.jpg')                                 // "service" is not a known place
        ->and($report->rewrite('allentown-pa-nj-md-coverage.jpg', $known, 'neptune', 'neptune-nj', 'sump-pump-gurus'))
        ->toBe('allentown-pa-nj-md-coverage.jpg')                          // state list → untouched
        ->and($report->rewrite('west-orange-nj-basement.jpg', $known, 'orange', 'orange-nj', 'sump-pump-gurus'))
        ->toBe('west-orange-nj-basement.jpg')                              // ends in the own town
        ->and($report->rewrite('neptune-nj-hero.webp', $known, 'neptune', 'neptune-nj', 'sump-pump-gurus'))
        ->toBe('neptune-nj-hero.webp')                                     // own town
        // The own town's TAIL is a known place too (Plainfield inside North Plainfield): the whole run is the
        // own town and stays — never "north-north-plainfield-nj" (the prod report's four false positives).
        ->and($report->rewrite('sump-pump-repair-north-plainfield-nj.jpg', $known, 'north-plainfield', 'north-plainfield-nj', 'sump-pump-gurus'))
        ->toBe('sump-pump-repair-north-plainfield-nj.jpg')
        ->and($report->rewrite('plainfield-nj-hero.jpg', $known, 'north-plainfield', 'north-plainfield-nj', 'sump-pump-gurus'))
        ->toBe('north-plainfield-nj-hero.jpg');                            // bare Plainfield IS foreign here
});
