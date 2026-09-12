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
use App\Publishing\Seo\ImageAltTownReport;
use Illuminate\Support\Facades\Queue;

/**
 * An anchored Neptune page whose hero image kept the alt/caption the vision pass wrote under the OLD
 * (Allentown) grounding, plus a second image whose alt already names the right town.
 *
 * @return array{0: Site, 1: Content, 2: RenderJob, 3: RenderJob}
 */
function altTownFixture(): array
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hub office']);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => '3402549890', 'name' => 'Neptune', 'state' => 'NJ',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);

    $page = Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3402549890',
        'title' => 'Neptune, NJ', 'slug' => 'new-brunswick-nj/neptune-nj', 'wp_post_id' => 3367,
    ]);

    // The comma form in alt and the bare form in caption — both shapes the vision pass emits.
    $stale = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'alt' => 'Technician inspecting a sump pump pit in a clean Allentown, PA basement',
        'caption' => 'Crew on site, Allentown PA.',
    ]);
    $clean = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'proof_1',
        'alt' => 'A dry Neptune, NJ basement after waterproofing', 'caption' => null,
    ]);

    return [$site, $page, $stale, $clean];
}

it('report-only lists each wrong-town field as current → proposed and writes nothing', function () {
    Queue::fake();
    [$site, , $stale, $clean] = altTownFixture();

    $this->artisan('launchpad:repush-image-alt-towns --site='.$site->id)
        ->assertSuccessful()
        ->expectsOutputToContain('new-brunswick-nj/neptune-nj')
        ->expectsOutputToContain('Neptune, NJ')
        ->expectsOutputToContain('READ-ONLY');

    // Nothing rewritten, nothing pushed.
    expect($stale->fresh()->alt)->toContain('Allentown, PA')
        ->and($stale->fresh()->caption)->toContain('Allentown PA')
        ->and($clean->fresh()->alt)->toBe('A dry Neptune, NJ basement after waterproofing');
    Queue::assertNothingPushed();
});

it('--execute rewrites both alt shapes to the authoritative town, leaves the correct image alone, and queues one repush', function () {
    Queue::fake();
    [$site, , $stale, $clean] = altTownFixture();

    $this->artisan('launchpad:repush-image-alt-towns --site='.$site->id.' --execute')
        ->assertSuccessful()
        ->expectsOutputToContain('Rewrote 2 field(s)');

    expect($stale->fresh()->alt)->toBe('Technician inspecting a sump pump pit in a clean Neptune, NJ basement')
        ->and($stale->fresh()->caption)->toBe('Crew on site, Neptune, NJ.')
        ->and($clean->fresh()->alt)->toBe('A dry Neptune, NJ basement after waterproofing'); // untouched

    // One affected page → exactly one PublishContent (idempotent by ULID), not one per field.
    Queue::assertPushed(PublishContent::class, 1);
});

it('never treats the brand name, a bare state code, or the page\'s own town as a foreign town', function () {
    $report = app(ImageAltTownReport::class);

    // Brand words + own town + a bare "NJ" → untouched; only the genuinely foreign place is swapped.
    expect($report->rewrite('Sump Pump Gurus NJ crew in Neptune, NJ near Allentown, PA', 'neptune', 'Neptune, NJ', 'Sump Pump Gurus'))
        ->toBe('Sump Pump Gurus NJ crew in Neptune, NJ near Neptune, NJ')
        ->and($report->rewrite('Serving Neptune, NJ homeowners', 'neptune', 'Neptune, NJ', 'Sump Pump Gurus'))
        ->toBe('Serving Neptune, NJ homeowners')                                  // own town, nothing to do
        ->and($report->rewrite('A technician in a basement', 'neptune', 'Neptune, NJ', 'Sump Pump Gurus'))
        ->toBe('A technician in a basement');                                     // no place at all
});
