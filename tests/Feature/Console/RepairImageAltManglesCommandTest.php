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
use App\Publishing\Seo\ImageAltMangleRepair;
use Illuminate\Support\Facades\Queue;

/**
 * A Neptune page (site states NJ + PA) whose hero carries two of the three mis-rewrites the pre-#841 pass
 * wrote — a state list read as the town, and a region's last word swapped for the town — plus one clean field.
 *
 * @return array{0: Site, 1: Content, 2: RenderJob}
 */
function mangleFixture(): array
{
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

    $job = RenderJob::factory()->rendered()->create([
        'site_id' => $site->id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'caption' => 'Sump Pump Gurus serves homeowners across Neptune, NJ, and Maryland with honest service.',
        'title' => 'Serving homeowners in Neptune, NJ and the surrounding Lehigh Neptune area.',
        'alt' => 'Technician inspecting a sump pump in a clean Neptune, NJ basement',
    ]);

    return [$site, $page, $job];
}

it('report-only lists each mangled field with its deterministic undo and writes nothing', function () {
    Queue::fake();
    [$site, , $job] = mangleFixture();

    $rows = app(ImageAltMangleRepair::class)->forSite($site->fresh())['rows'];
    expect(array_column($rows, 'field'))->toEqualCanonicalizing(['caption', 'title']);
    $byField = array_column($rows, 'proposed', 'field');
    expect($byField['caption'])->toBe('Sump Pump Gurus serves homeowners across New Jersey, Pennsylvania, and Maryland with honest service.')
        ->and($byField['title'])->toBe('Serving homeowners in Neptune, NJ and the surrounding Lehigh Valley area.');

    $this->artisan('launchpad:repair-image-alt-mangles --site='.$site->id)
        ->assertSuccessful()
        ->expectsOutputToContain('new-brunswick-nj/neptune-nj')
        ->expectsOutputToContain('READ-ONLY');

    expect($job->fresh()->caption)->toContain('Neptune, NJ, and Maryland');
    Queue::assertNothingPushed();
});

it('--execute repairs the fields, leaves the clean one alone, and queues one repush', function () {
    Queue::fake();
    [$site, , $job] = mangleFixture();

    $this->artisan('launchpad:repair-image-alt-mangles --site='.$site->id.' --execute')
        ->assertSuccessful()
        ->expectsOutputToContain('Repaired 2 field(s)');

    $job = $job->fresh();
    expect($job->caption)->toBe('Sump Pump Gurus serves homeowners across New Jersey, Pennsylvania, and Maryland with honest service.')
        ->and($job->title)->toBe('Serving homeowners in Neptune, NJ and the surrounding Lehigh Valley area.')
        ->and($job->alt)->toBe('Technician inspecting a sump pump in a clean Neptune, NJ basement');

    Queue::assertPushed(PublishContent::class, 1);
});

it('undoes a doubled own town, keeps "or" lists, and never guesses an ambiguous state list', function () {
    $repair = app(ImageAltMangleRepair::class);

    expect($repair->repair('A technician checks a sump pump in an Upper Upper Darby home.', 'Upper Darby', 'PA', ['NJ', 'PA']))
        ->toBe('A technician checks a sump pump in an Upper Darby home.')
        ->and($repair->repair('A working sump pump keeping a Whitemarsh, PA, or Maryland basement dry', 'Whitemarsh', 'PA', ['NJ', 'PA']))
        ->toBe('A working sump pump keeping a Pennsylvania, New Jersey, or Maryland basement dry')
        ->and($repair->repair('Serves Spring and the surrounding Lehigh Spring communities.', 'Spring', 'PA', ['NJ', 'PA']))
        ->toBe('Serves Spring and the surrounding Lehigh Valley communities.')
        // Three site states could fill the gap → unresolved, unchanged.
        ->and($repair->repair('Serving Roselle, NJ, and Maryland', 'Roselle', 'NJ', ['NJ', 'PA', 'NY', 'MD']))
        ->toBe('Serving Roselle, NJ, and Maryland')
        ->and($repair->hasStateListMangle('Serving Roselle, NJ, and Maryland', 'Roselle', 'NJ'))->toBeTrue()
        // Correct text is untouched.
        ->and($repair->repair('A technician in a clean Neptune, NJ basement', 'Neptune', 'NJ', ['NJ', 'PA']))
        ->toBe('A technician in a clean Neptune, NJ basement');
});
