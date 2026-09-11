<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\LocationBodyReport;

it('flags anchored location pages whose drafted H1 names a foreign town — the regenerate set', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hub office']);

    $coverage = function (string $geo, string $name) use ($site, $parent): void {
        CoverageArea::factory()->create([
            'site_id' => $site->id, 'geo_id' => $geo, 'name' => $name, 'state' => 'NJ',
            'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
        ]);
    };
    $coverage('3401700001', 'Neptune');
    $coverage('3401700002', 'Weehawken');
    $coverage('3401700003', 'Clark');

    $page = function (string $geo, string $slug, string $title, array $slots) use ($site, $parent): void {
        Content::factory()->published()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
            'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => $geo,
            'title' => $title, 'slug' => $slug, 'slot_payload' => $slots,
        ]);
    };

    // Hallucinated: anchored to Neptune, but the drafted H1 + FAQ name Allentown.
    $page('3401700001', 'neptune-nj', 'Neptune, NJ', [
        'hero_headline' => 'Sump Pump Repair, Installation & Basement Waterproofing Services in Allentown, PA',
        'faq' => [['question' => 'Do you serve Allentown, PA?', 'answer' => 'Yes — Allentown, PA homeowners rely on us.']],
    ]);
    // Correct: the H1 leads with the authoritative town.
    $page('3401700002', 'weehawken-nj', 'Weehawken, NJ', [
        'hero_headline' => 'Sump Pump Repair in Weehawken, NJ',
    ]);
    // Town-blind: the H1 names the region, not the town — surfaced as weak, NOT foreign.
    $page('3401700003', 'clark-nj', 'Clark, NJ', [
        'hero_headline' => 'Sump Pump & Basement Water Services in NJ, PA & MD',
    ]);

    $entry = app(LocationBodyReport::class)->forSite($site->fresh());

    expect($entry['anchored'])->toBe(3)
        ->and($entry['foreign_town'])->toBe(1)   // only the Allentown page
        ->and($entry['ok'])->toBe(1)             // Weehawken
        ->and($entry['weak'])->toBe(1);          // the region-blind Clark H1

    $neptune = collect($entry['pages'])->firstWhere('slug', 'neptune-nj');
    expect($neptune['status'])->toBe('foreign_town')
        ->and($neptune['foreign'])->toContain('Allentown');

    // A correct page is never mis-flagged, even when a verb glues to the town ("Serving Neptune").
    $weehawken = collect($entry['pages'])->firstWhere('slug', 'weehawken-nj');
    expect($weehawken['status'])->toBe('ok')
        ->and($weehawken['foreign'])->toBe([]);

    $this->artisan('launchpad:report-location-body --site='.$site->id)
        ->assertSuccessful()
        ->expectsOutputToContain('READ-ONLY');
});
