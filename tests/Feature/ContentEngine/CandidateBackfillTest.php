<?php

use App\ContentEngine\CandidateBackfill;
use App\ContentEngine\RelevanceScorer;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\ScriptedClaudeClient;

function backfillSetup(): array
{
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Water Heaters',
        'rule_set' => ['include_patterns' => ['water heater', 'tankless'], 'exclude_patterns' => []],
    ]);

    $mk = fn (array $attrs) => Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id,
    ], $attrs));

    return [$site, $silo, $mk];
}

function bindBackfillScorer(): void
{
    $claude = (new ScriptedClaudeClient)
        ->on('Cold snap advisory', json_encode([
            'relevance' => 0.8, 'matched_silo' => 'Water Heaters', 'brand_safe' => true, 'classification' => 'time_sensitive',
        ]))
        ->on('Rival Plumbing', json_encode([
            'relevance' => 0.9, 'matched_silo' => 'Water Heaters', 'brand_safe' => true, 'competitor_promo' => true,
        ]));
    app()->instance(RelevanceScorer::class, new RelevanceScorer($claude));
}

it('classifies existing candidates and reports competitor announcements without dropping by default', function () {
    [$site, , $mk] = backfillSetup();
    $a = $mk(['title' => 'Cold snap advisory for water heaters']);
    $b = $mk(['title' => 'Rival Plumbing announces tankless expansion']);
    $drafted = $mk(['title' => 'Already drafted piece', 'body' => 'This candidate already has a body.']);
    bindBackfillScorer();

    $r = app(CandidateBackfill::class)->backfill($site);

    expect($r['scanned'])->toBe(2)              // the drafted row is skipped
        ->and($r['classified'])->toBe(2)
        ->and($r['competitors'])->toBe(1)
        ->and($r['dropped'])->toBe(0)
        ->and($a->fresh()->meta['classification'])->toBe('time_sensitive')
        ->and($b->fresh()->status)->toBe(ContentStatus::Candidate)   // competitor kept, not dropped
        ->and($b->fresh()->meta['classification'])->toBe('evergreen') // derived (no explicit class in the response)
        ->and($drafted->fresh()->meta)->not->toHaveKey('classification'); // untouched
});

it('drops competitor announcements when --drop-competitors is passed', function () {
    [$site, , $mk] = backfillSetup();
    $a = $mk(['title' => 'Cold snap advisory for water heaters']);
    $b = $mk(['title' => 'Rival Plumbing announces tankless expansion']);
    bindBackfillScorer();

    $r = app(CandidateBackfill::class)->backfill($site, dropCompetitors: true);

    expect($r['classified'])->toBe(1)
        ->and($r['competitors'])->toBe(1)
        ->and($r['dropped'])->toBe(1)
        ->and($a->fresh()->meta['classification'])->toBe('time_sensitive')
        ->and($b->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($b->fresh()->reject_reason)->toBe('competitor_promo');
});

it('is idempotent — a re-run leaves the same classification, no duplicates', function () {
    [$site, , $mk] = backfillSetup();
    $a = $mk(['title' => 'Cold snap advisory for water heaters']);
    bindBackfillScorer();

    app(CandidateBackfill::class)->backfill($site);
    app(CandidateBackfill::class)->backfill($site);

    expect($a->fresh()->meta['classification'])->toBe('time_sensitive')
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('the classify-candidates command runs for a site', function () {
    [$site, , $mk] = backfillSetup();
    $mk(['title' => 'Cold snap advisory for water heaters']);
    bindBackfillScorer();

    $this->artisan('launchpad:classify-candidates', ['site' => $site->id])
        ->expectsOutputToContain('1 classified')
        ->assertSuccessful();
});
