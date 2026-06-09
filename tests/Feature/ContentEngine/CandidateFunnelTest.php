<?php

use App\ContentEngine\CandidateFunnel;
use App\ContentEngine\RelevanceScorer;
use App\Enums\AlertType;
use App\Enums\ContentStatus;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Integrations\News\MockNewsProvider;
use App\Integrations\News\NewsProvider;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\News;
use Tests\Support\ScriptedClaudeClient;

function relevanceJson(float $score, ?string $silo, bool $brandSafe = true, bool $local = false): string
{
    return json_encode([
        'relevance' => $score,
        'matched_silo' => $silo,
        'angle' => 'A homeowner takeaway',
        'advisory_value' => 0.7,
        'timeliness' => 0.6,
        'local_relevance' => $local,
        'brand_safe' => $brandSafe,
    ]);
}

test('the funnel routes a mixed batch into candidates, parks, drops and refreshes', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Water Heaters',
        'rule_set' => ['include_patterns' => ['water heater', 'tankless'], 'exclude_patterns' => []],
    ]);

    // An existing live page for the near-dup / refresh path.
    Content::factory()->post()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'title' => 'Tankless water heater rebate explained',
        'slug' => 'tankless-water-heater-rebate-explained',
        'status' => ContentStatus::Published,
        'body' => null,
    ]);

    $claude = (new ScriptedClaudeClient)
        ->on('Cold snap maintenance tips', relevanceJson(0.82, 'Water Heaters', local: true))
        ->on('City council passes', relevanceJson(0.9, null))
        ->on('water heater explosion', relevanceJson(0.7, 'Water Heaters', brandSafe: false))
        ->on('water heater trivia', relevanceJson(0.45, 'Water Heaters'))
        ->on('Tankless water heater rebate explained', relevanceJson(0.85, 'Water Heaters'));

    $this->app->instance(RelevanceScorer::class, new RelevanceScorer($claude));
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);

    $items = [
        News::item('Cold snap maintenance tips for homeowners', summary: 'Protect your plumbing this winter.'),
        News::item('City council passes new downtown budget'),
        News::item('Tragic water heater explosion claims a life'),
        News::item('Fun water heater trivia roundup'),
        News::item('Tankless water heater rebate explained', summary: 'Tankless water heater rebate explained'),
    ];

    $result = app(CandidateFunnel::class)->process($site, $items);

    // Draft-ready candidate created (the cold-snap tips), with relevance fields.
    expect($result->created)->toHaveCount(1)
        ->and($result->created[0]->status)->toBe(ContentStatus::Candidate)
        ->and($result->created[0]->silo_id)->toBe($silo->id)
        ->and($result->created[0]->source_name)->toBe('Local Tribune')
        ->and((bool) $result->created[0]->local_relevance)->toBeTrue()
        ->and($result->created[0]->angle_hint)->not->toBeNull();

    // Borderline parked into review.
    expect($result->parked)->toHaveCount(1)
        ->and($result->parked[0]->status)->toBe(ContentStatus::InReview);

    // No-silo-match and brand-safety drops.
    $dropReasons = array_column($result->dropped, 'reason');
    expect($dropReasons)->toContain('no_silo_match')
        ->and($dropReasons)->toContain('brand_safety');

    // Near-dup of the live page → refresh mark, not a duplicate.
    expect($result->refreshMarked)->toHaveCount(1);

    // Alerts cover brand-safety, borderline and refresh.
    $alertTypes = array_map(fn ($a) => $a->type, $result->alerts);
    expect($alertTypes)->toContain(AlertType::BrandSafetyRejected)
        ->and($alertTypes)->toContain(AlertType::BorderlineRelevance)
        ->and($alertTypes)->toContain(AlertType::RefreshSuggested);

    // Exactly one post-candidate persisted beyond the seeded live page (cold snap);
    // the borderline one is in_review, the refresh one was not duplicated.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', 'post')->count())
        ->toBe(3); // live page + draft-ready + borderline
});

test('first-run backfill yields a discovery corpus for old items and drafts only recent ones', function () {
    $site = Site::factory()->create();
    Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Water Heaters',
        'rule_set' => ['include_patterns' => ['water heater'], 'exclude_patterns' => []],
    ]);

    $claude = (new ScriptedClaudeClient)->fallback(relevanceJson(0.8, 'Water Heaters'));
    $this->app->instance(RelevanceScorer::class, new RelevanceScorer($claude));
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);

    $news = app(MockNewsProvider::class)->withItems([
        News::item('Recent water heater advisory', ageDays: 10, topic: 'recent'),
        News::item('Old water heater seasonal note', ageDays: 200, topic: 'old-a'),
        News::item('Another old water heater trend', ageDays: 300, topic: 'old-b'),
    ]);
    $this->app->instance(NewsProvider::class, $news);

    $result = app(CandidateFunnel::class)->backfill($site, [], cutoffDays: 90);

    // >cutoff → corpus (not drafted); ≤cutoff → one candidate.
    expect($result->corpus)->toHaveCount(2)
        ->and($result->recent->created)->toHaveCount(1);

    // No Content was created from the archive items.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});
