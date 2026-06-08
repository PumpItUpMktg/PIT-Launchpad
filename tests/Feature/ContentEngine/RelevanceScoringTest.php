<?php

use App\ContentEngine\RelevanceScorer;
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
