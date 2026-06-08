<?php

use App\Enums\BeatabilityLane;
use App\Enums\IntentLevel;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Beatability\BeatabilityResult;
use App\KeywordGenerator\Gap\GapAnalyzer;
use App\KeywordGenerator\Pipeline\ScoredKeyword;
use App\KeywordGenerator\Scoring\ScoreResult;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\ProofItem;
use App\Models\Service;
use App\Models\ServiceProblem;
use App\Models\Silo;
use App\Models\Site;

function scoredKeyword(Keyword $keyword, bool $parked = false): ScoredKeyword
{
    return new ScoredKeyword(
        keyword: $keyword,
        score: new ScoreResult(opportunity: 0.5, quickWin: 0.4, demand: 0.6, intentWeight: 0.7, businessValue: 0.8, beatability: 0.7),
        beatability: new BeatabilityResult(0.7, BeatabilityLane::Organic, 'organic rationale', parked: $parked),
        intent: IntentLevel::Commercial,
        serp: new SerpResultSet($keyword->query, [new SerpResult(1, 'https://rival.com/x', 'rival.com')]),
        relatedTerms: ['tankless water heater', 'water heater cost'],
    );
}

test('gap analysis emits a prescriptive brief with all fields', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->servicePillar()->create(['site_id' => $site->id, 'name' => 'Plumbing']);
    $sibling = Silo::factory()->create(['site_id' => $site->id, 'parent_silo_id' => $silo->parent_silo_id]);

    $service = Service::factory()->create(['site_id' => $site->id, 'is_most_profitable' => true]);
    $silo->services()->attach($service->id);
    ServiceProblem::factory()->create(['service_id' => $service->id, 'phrase' => 'water heater leaking']);

    $proof = ProofItem::factory()->create(['site_id' => $site->id, 'is_substantiated' => true]);
    $proof->services()->attach($service->id);

    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'water heater repair cost']);

    $queue = app(GapAnalyzer::class)->analyze($site, [scoredKeyword($keyword)]);

    expect($queue->count())->toBe(1);
    $brief = $queue->first();

    expect($brief->targetKeyword)->toBe('water heater repair cost')
        ->and($brief->lane)->toBe(BeatabilityLane::Organic)
        ->and($brief->intent)->toBe('commercial')
        ->and($brief->siloName)->toBe('Plumbing')
        ->and($brief->kit)->toBe('service-page')
        ->and($brief->problemFraming)->toContain('water heater leaking')
        ->and($brief->proofHooks)->not->toBeEmpty()
        ->and($brief->coverageRequirements)->toContain('tankless water heater')
        ->and($brief->internalLinks['sibling_silo_ids'])->toContain($sibling->id)
        ->and($brief->differentiationAngle)->toContain('Plumbing')
        ->and($brief->ctaIntent)->not->toBeEmpty()
        ->and($brief->seoTargets)->toHaveKey('target_keyword');
});

test('covered and parked keywords produce no brief', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $covered = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id]);
    Content::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'target_keyword_id' => $covered->id]);

    $parked = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id]);

    $queue = app(GapAnalyzer::class)->analyze($site, [
        scoredKeyword($covered),
        scoredKeyword($parked, parked: true),
    ]);

    expect($queue->count())->toBe(0);
});
