<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Locations\TownStateRepair;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Redirect;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

it('finds a town page titled with the wrong state for its anchored town, then retitles, rewrites the wording, re-nests with a 301, fixes the coverage row and re-pushes — leaving correct pages alone', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    $doylestown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown office']);
    Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => $doylestown->id, 'title' => 'Doylestown, PA', 'slug' => 'doylestown-pa']);

    // Middletown Township, Bucks County, PA (42017…) — the coverage row's state leaked the page's wrong label too.
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4201748680', 'name' => 'Middletown', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$doylestown->id]]);
    $wrong = Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $doylestown->id, 'parent_content_id' => null, 'geo_id' => '4201748680',
        'title' => 'Middletown, NJ', 'slug' => 'doylestown-pa/middletown-nj',
        'slot_payload' => ['hero_headline' => 'Sump Pump Repair, Installation & Basement Waterproofing in Middletown, NJ', 'loc_intro' => 'Homes in Middletown, NJ sit on clay. Middletown, nj basements flood in spring.', 'faq' => [['question' => 'Do you serve Middletown, NJ?', 'answer' => 'Yes.']]],
        'meta' => ['seo' => ['title' => 'Sump Pump Service in Middletown, NJ', 'meta_description' => 'Middletown, NJ sump pumps.']],
    ]);
    // A correctly labelled town, and an un-anchored one — neither is touched.
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4201758536', 'name' => 'Perkasie', 'state' => 'PA', 'type' => MunicipalityType::Place, 'source_location_ids' => [$doylestown->id]]);
    $right = Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $doylestown->id, 'geo_id' => '4201758536', 'title' => 'Perkasie, PA', 'slug' => 'doylestown-pa/perkasie-pa']);
    $unanchored = Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $doylestown->id, 'geo_id' => null, 'title' => 'Mystery, NJ', 'slug' => 'doylestown-pa/mystery-nj']);

    $report = app(TownStateRepair::class)->report($site);
    expect($report)->toHaveCount(1)
        ->and($report[0]['slug'])->toBe('doylestown-pa/middletown-nj')
        ->and($report[0]['stored_state'])->toBe('NJ')->and($report[0]['state'])->toBe('PA')
        ->and($report[0]['new_title'])->toBe('Middletown, PA');

    $this->artisan('launchpad:repair-town-states --site='.$site->id)->expectsOutputToContain('READ-ONLY: 1 town page(s)')->expectsOutputToContain('"Middletown, NJ" → "Middletown, PA"')->assertSuccessful();
    Queue::assertNothingPushed();
    expect($wrong->fresh()->title)->toBe('Middletown, NJ');

    $this->artisan('launchpad:repair-town-states --site='.$site->id.' --execute')->expectsOutputToContain('Repaired 1 town page(s).')->assertSuccessful();

    $wrong->refresh();
    expect($wrong->title)->toBe('Middletown, PA')
        ->and($wrong->slug)->toBe('doylestown-pa/middletown-pa')
        ->and($wrong->slot_payload['hero_headline'])->toBe('Sump Pump Repair, Installation & Basement Waterproofing in Middletown, PA')
        ->and($wrong->slot_payload['loc_intro'])->toBe('Homes in Middletown, PA sit on clay. Middletown, PA basements flood in spring.')
        ->and($wrong->slot_payload['faq'][0]['question'])->toBe('Do you serve Middletown, PA?')
        ->and($wrong->meta['seo']['title'])->toBe('Sump Pump Service in Middletown, PA')
        ->and($wrong->meta['seo']['meta_description'])->toBe('Middletown, PA sump pumps.');
    expect(CoverageArea::withoutGlobalScopes()->where('geo_id', '4201748680')->value('state'))->toBe('PA');
    $redirect = Redirect::withoutGlobalScopes()->where('site_id', $site->id)->where('from_url', '/doylestown-pa/middletown-nj')->first();
    expect($redirect)->not->toBeNull()->and($redirect->to_url)->toBe('/doylestown-pa/middletown-pa')->and($redirect->code)->toBe(301);
    Queue::assertPushed(PublishContent::class, 1);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j) => $j->contentId === $wrong->id);
    expect($right->fresh()->title)->toBe('Perkasie, PA')->and($right->fresh()->slug)->toBe('doylestown-pa/perkasie-pa')
        ->and($unanchored->fresh()->title)->toBe('Mystery, NJ');

    $this->artisan('launchpad:repair-town-states --site='.$site->id)->expectsOutputToContain('every town page names the state its town is in')->assertSuccessful();
});

it('reads the state out of a title only when it is a trailing two-letter suffix', function () {
    expect(TownStateRepair::stateInTitle('Middletown, NJ'))->toBe('NJ')
        ->and(TownStateRepair::stateInTitle('Middletown, nj.'))->toBe('NJ')
        ->and(TownStateRepair::stateInTitle('Middletown, Bucks County'))->toBeNull()
        ->and(TownStateRepair::stateInTitle('Middletown'))->toBeNull();
});
