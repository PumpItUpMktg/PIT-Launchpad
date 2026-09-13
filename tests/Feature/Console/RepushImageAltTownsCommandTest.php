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
    // Allentown is a place the site knows — what makes a BARE "Allentown" (no state) recognisable as a town.
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => '4207702000', 'name' => 'Allentown', 'state' => 'PA',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);

    $page = Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3402549890',
        'title' => 'Neptune, NJ', 'slug' => 'new-brunswick-nj/neptune-nj', 'wp_post_id' => 3367,
    ]);

    // The comma form in alt, the bare-state form in caption, and a stateless town in title — the three
    // shapes the vision pass emits.
    $stale = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'alt' => 'Technician inspecting a sump pump pit in a clean Allentown, PA basement',
        'caption' => 'Crew on site, Allentown PA.',
        'title' => 'Keeping Allentown basements dry',
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

    // The census: both stale fields, each proposing the authoritative town. Asserted on the service rows
    // rather than the console table — the narrow non-TTY test console word-wraps the long alt cells, which
    // can split "Neptune, NJ" across a line break and defeat a substring assertion on the rendered table.
    $rows = app(ImageAltTownReport::class)->forSite($site->fresh())['rows'];
    expect(array_column($rows, 'field'))->toEqualCanonicalizing(['alt', 'caption', 'title']);
    foreach ($rows as $row) {
        expect($row['proposed'])->toContain('Neptune');
    }

    $this->artisan('launchpad:repush-image-alt-towns --site='.$site->id)
        ->assertSuccessful()
        ->expectsOutputToContain('new-brunswick-nj/neptune-nj')
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
        ->expectsOutputToContain('Rewrote 3 field(s)');

    expect($stale->fresh()->alt)->toBe('Technician inspecting a sump pump pit in a clean Neptune, NJ basement')
        ->and($stale->fresh()->caption)->toBe('Crew on site, Neptune, NJ.')
        ->and($stale->fresh()->title)->toBe('Keeping Neptune basements dry')   // stateless town, bare replacement
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

it('leaves a multi-state list alone and re-agrees a leading a/an with the replacement town', function () {
    $report = app(ImageAltTownReport::class);

    // "Service Across PA, NJ, and MD" is a STATE LIST — the words before "PA" are prose, not a town (the prod
    // report proposed "Sump Whitemarsh, PA, NJ, and MD" for it). Every list shape the vision pass emits:
    expect($report->rewrite('Sump Pump Gurus — Basement Sump Pump Service Across PA, NJ, and MD', 'whitemarsh', 'Whitemarsh, PA', 'Sump Pump Gurus'))
        ->toBe('Sump Pump Gurus — Basement Sump Pump Service Across PA, NJ, and MD')
        ->and($report->rewrite('Sump Pump Gurus — Basement Water Protection Across NJ, PA & MD', 'linden', 'Linden, NJ', 'Sump Pump Gurus'))
        ->toBe('Sump Pump Gurus — Basement Water Protection Across NJ, PA & MD')
        ->and($report->rewrite('Sump Pump Gurus — Serving NJ, PA & MD', 'secaucus', 'Secaucus, NJ', 'Sump Pump Gurus'))
        ->toBe('Sump Pump Gurus — Serving NJ, PA & MD');

    // The article agrees with the NEW town: "an Allentown" → "a Cumru" / "an Ocean"; sentence-case kept.
    expect($report->rewrite('A technician checks a sump pump in an Allentown, PA basement', 'cumru', 'Cumru, PA', 'Sump Pump Gurus'))
        ->toBe('A technician checks a sump pump in a Cumru, PA basement')
        ->and($report->rewrite('A technician checks a sump pit in an Allentown, PA home.', 'ocean', 'Ocean, NJ', 'Sump Pump Gurus'))
        ->toBe('A technician checks a sump pit in an Ocean, NJ home.')
        ->and($report->rewrite('An Allentown, PA basement.', 'wall', 'Wall, NJ', 'Sump Pump Gurus'))
        ->toBe('A Wall, NJ basement.');
});

it('rewrites a stateless known town only in a place context, a full state name, and drops a foreign region clause', function () {
    $report = app(ImageAltTownReport::class);
    $known = ['Upper Southampton', 'New Brunswick', 'Union City', 'Allentown', 'Bayonne', 'Neptune', 'Kearny', 'Wall', 'Ocean', 'Spring', 'Reading'];
    $b = 'Sump Pump Gurus';

    // Bare town in a place context → bare authoritative town, article re-agreed (the 20 prod captions).
    expect($report->rewrite('A technician checking a sump pump in an Allentown home', 'kearny', 'Kearny, NJ', $b, $known, 'NJ'))
        ->toBe('A technician checking a sump pump in a Kearny home')
        ->and($report->rewrite('Keeping Allentown basements dry, one pump at a time.', 'exeter', 'Exeter, PA', $b, $known, 'PA'))
        ->toBe('Keeping Exeter basements dry, one pump at a time.')
        ->and($report->rewrite('A sump pit in an Allentown-area basement', 'upper southampton', 'Upper Southampton, PA', $b, $known, 'PA'))
        ->toBe('A sump pit in an Upper Southampton-area basement')
        ->and($report->rewrite('This keeps this Allentown home\'s basement dry', 'neptune', 'Neptune, NJ', $b, $known, 'NJ'))
        ->toBe('This keeps this Neptune home\'s basement dry');

    // Full state name → "{Auth}, {ST}".
    expect($report->rewrite('A technician in an Allentown, Pennsylvania home basement.', 'marlboro', 'Marlboro, NJ', $b, $known, 'NJ'))
        ->toBe('A technician in a Marlboro, NJ home basement.')
        ->and($report->rewrite('An inspection in the New Brunswick, New Jersey area', 'whitpain', 'Whitpain, PA', $b, $known, 'PA'))
        ->toBe('An inspection in the Whitpain, PA area');

    // A foreign region clause is dropped on a page outside its state — and kept inside it.
    expect($report->rewrite('Serves homeowners in Howell, NJ and the surrounding Lehigh Valley area.', 'howell', 'Howell, NJ', $b, $known, 'NJ'))
        ->toBe('Serves homeowners in Howell, NJ and the surrounding area.')
        ->and($report->rewrite('Serving Allentown and the Lehigh Valley with professional service', 'sayreville', 'Sayreville, NJ', $b, $known, 'NJ'))
        ->toBe('Serving Sayreville with professional service')
        ->and($report->rewrite('Fan unit on a basement wall, Lehigh Valley home installation.', 'south whitehall', 'South Whitehall, PA', $b, $known, 'PA'))
        ->toBe('Fan unit on a basement wall, Lehigh Valley home installation.');

    // A known place that is also an ordinary word is NOT a town without a place context: Title-Case "Wall",
    // "Spring Maintenance", "Ocean County", "a Reading of the gauge" all stay.
    expect($report->rewrite('Basement Wall Crack Repair in Neptune, NJ | Spring Maintenance', 'neptune', 'Neptune, NJ', $b, $known, 'NJ'))
        ->toBe('Basement Wall Crack Repair in Neptune, NJ | Spring Maintenance')
        ->and($report->rewrite('Serving Ocean County homeowners in Neptune, NJ', 'neptune', 'Neptune, NJ', $b, $known, 'NJ'))
        ->toBe('Serving Ocean County homeowners in Neptune, NJ')
        ->and($report->rewrite('A Spring-loaded float and a Reading of the gauge', 'neptune', 'Neptune, NJ', $b, $known, 'NJ'))
        ->toBe('A Spring-loaded float and a Reading of the gauge');
});
