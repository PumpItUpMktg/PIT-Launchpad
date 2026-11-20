<?php

use App\ContentEngine\Geography\Footprint;
use App\ContentEngine\RelevanceScorer;
use App\Enums\CandidateClassification;
use App\Enums\CandidateScope;
use App\Enums\PlaceVerdict;
use App\Enums\RelevanceBand;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\News;
use Tests\Support\ScriptedClaudeClient;

function siloFor(Site $site): Silo
{
    return Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Water Heaters',
        'rule_set' => ['include_patterns' => ['water heater', 'tankless'], 'exclude_patterns' => []],
    ]);
}

test('a relevant item is scored, routed and given an angle', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    $claude = (new ScriptedClaudeClient)->on('tankless', json_encode([
        'relevance' => 0.82, 'matched_silo' => 'Water Heaters', 'angle' => 'How the rebate saves homeowners money',
        'advisory_value' => 0.8, 'timeliness' => 0.7, 'local_relevance' => true, 'brand_safe' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('New tankless water heater rebate announced'), $silos);

    expect($result->band)->toBe(RelevanceBand::DraftReady)
        ->and($result->matchedSiloId)->toBe($silos->first()->id)
        ->and($result->angleHint)->toBe('How the rebate saves homeowners money')
        ->and($result->localRelevance)->toBeTrue();
});

test('the silo-match gate drops a no-match item', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.9, 'matched_silo' => null, 'brand_safe' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('Local football team wins championship'), $silos);

    expect($result->band)->toBe(RelevanceBand::Dropped)
        ->and($result->matchedSiloId)->toBeNull();
});

test('the brand-safety gate rejects a tragedy-exploitative item', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.7, 'matched_silo' => 'Water Heaters', 'brand_safe' => false,
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('Family dies in house fire — water heater blamed'), $silos);

    expect($result->band)->toBe(RelevanceBand::Dropped)
        ->and($result->brandSafe)->toBeFalse();
});

test('a borderline score is parked', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.45, 'matched_silo' => 'Water Heaters', 'brand_safe' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('Mild advisory about tankless water heater myths'), $silos);

    expect($result->band)->toBe(RelevanceBand::Borderline);
});

test('a competitor\'s own announcement is dropped even when otherwise relevant', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    // High relevance, brand-safe, silo match — but it is a rival's press release.
    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.9, 'matched_silo' => 'Water Heaters', 'brand_safe' => true, 'competitor_promo' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('Rival Plumbing announces new tankless install service'), $silos);

    expect($result->band)->toBe(RelevanceBand::Dropped)
        ->and($result->competitorPromo)->toBeTrue();
});

test('it carries the model\'s explicit timeliness classification', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.8, 'matched_silo' => 'Water Heaters', 'brand_safe' => true, 'classification' => 'time_sensitive',
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('Cold snap this weekend threatens pipes'), $silos);

    expect($result->classification)->toBe(CandidateClassification::TimeSensitive);
});

test('it derives a classification from numeric signals when the model omits one', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);

    // No `classification` key, but local_relevance true → derives Local (footprint wins).
    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.8, 'matched_silo' => 'Water Heaters', 'brand_safe' => true,
        'timeliness' => 0.9, 'local_relevance' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(News::item('Township basement flooding after storm'), $silos);

    expect($result->classification)->toBe(CandidateClassification::Local);
});

test('with a footprint the scorer names the service area, and the extracted place decides local relevance in code', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);
    $footprint = new Footprint(['NJ', 'PA'], ['DE', 'MD', 'NY', 'OH', 'WV'], ['Montclair NJ', 'Trooper PA']);
    $json = fn (?string $place, ?string $state, bool $local) => json_encode([
        'relevance' => 0.8, 'matched_silo' => 'Water Heaters', 'angle' => 'x', 'advisory_value' => 0.7, 'timeliness' => 0.6,
        'local_relevance' => $local, 'brand_safe' => true, 'story_place' => $place, 'story_state' => $state,
    ]);
    $claude = (new ScriptedClaudeClient)
        ->on('Omaha', $json('Omaha, NE', 'NE', true))          // the model's "local" opinion is overruled by the place
        ->on('Camden', $json('Camden, NJ', 'NJ', false))        // and the other way round
        ->on('Buffalo', $json('Buffalo, NY', 'NY', true))
        ->on('five-minute check', $json(null, null, true));    // placeless: the model's flag stands

    $scorer = new RelevanceScorer($claude);
    $omaha = $scorer->score(News::item('Omaha homeowners report sewer backups'), $silos, null, $footprint);
    $camden = $scorer->score(News::item('Camden crews clear storm drains'), $silos, null, $footprint);
    $buffalo = $scorer->score(News::item('Buffalo basements flood after lake-effect storm'), $silos, null, $footprint);
    $howto = $scorer->score(News::item('This five-minute check saves your basement'), $silos, null, $footprint);

    expect($omaha->placeVerdict)->toBe(PlaceVerdict::Outside)->and($omaha->storyPlace)->toBe('Omaha, NE')
        ->and($omaha->localRelevance)->toBeFalse()->and($omaha->scope)->toBe(CandidateScope::General)
        ->and($camden->placeVerdict)->toBe(PlaceVerdict::Footprint)->and($camden->localRelevance)->toBeTrue()
        ->and($camden->scope)->toBe(CandidateScope::Local)
        ->and($buffalo->placeVerdict)->toBe(PlaceVerdict::Region)->and($buffalo->localRelevance)->toBeFalse()
        ->and($howto->placeVerdict)->toBe(PlaceVerdict::Unknown)->and($howto->storyPlace)->toBeNull()->and($howto->localRelevance)->toBeTrue();

    expect($claude->prompts[0])->toContain('Service area (states): New Jersey (NJ), Pennsylvania (PA).')
        ->toContain('Montclair NJ; Trooper PA')
        ->toContain('"story_place"')
        ->toContain('never infer a place from the outlet alone');
});

test('without a footprint the prompt and the result are unchanged', function () {
    $site = Site::factory()->create();
    $silos = collect([siloFor($site)]);
    $claude = (new ScriptedClaudeClient)->fallback(json_encode(['relevance' => 0.8, 'matched_silo' => 'Water Heaters', 'local_relevance' => true, 'brand_safe' => true]));

    $result = (new RelevanceScorer($claude))->score(News::item('Tankless rebate'), $silos);

    expect($result->placeVerdict)->toBe(PlaceVerdict::Unknown)->and($result->storyPlace)->toBeNull()->and($result->localRelevance)->toBeTrue()
        ->and($claude->prompts[0])->not->toContain('Service area')->not->toContain('story_place');
});
