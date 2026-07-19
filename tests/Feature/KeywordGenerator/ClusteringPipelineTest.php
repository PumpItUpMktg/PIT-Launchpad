<?php

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Serp\KeywordMetrics;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Cluster\ClusterEngine;
use App\KeywordGenerator\Cluster\ClusteringPipeline;
use App\KeywordGenerator\Cluster\ClusterLabeler;
use App\KeywordGenerator\Cluster\CorpusEmbeddings;
use App\KeywordGenerator\Cluster\HeadTermSelector;
use App\KeywordGenerator\Cluster\SerpOverlapValidator;
use App\KeywordGenerator\Corpus\KeywordNormalizer;
use App\KeywordGenerator\Scoring\IntentClassifier;
use App\Models\KeywordCluster;
use App\Models\KeywordCorpus;
use App\Models\Site;
use Tests\Support\FakeClaudeClient;

class KfFamilyEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'french drain') => [1.0, 0.0, 0.0],
            str_contains($t, 'sump pump') => [0.0, 1.0, 0.0],
            default => [0.0, 0.0, 1.0],
        };
    }
}

/** Same first word → shared domains → high SERP overlap. */
class KfFakeSerp implements SerpProvider
{
    public function metrics(string $query): KeywordMetrics
    {
        return new KeywordMetrics($query, 0, 0);
    }

    public function results(string $query): SerpResultSet
    {
        $head = explode(' ', mb_strtolower($query))[0];

        return new SerpResultSet($query, [
            new SerpResult(1, "https://{$head}-a.com", "{$head}-a.com"),
            new SerpResult(2, 'https://common.com', 'common.com'),
        ]);
    }
}

function kfPipeline(string $claudeJson): ClusteringPipeline
{
    return new ClusteringPipeline(
        new ClusterEngine(new CorpusEmbeddings(new KfFamilyEmbeddings)),
        new ClusterLabeler(new FakeClaudeClient($claudeJson), new KeywordNormalizer),
        new HeadTermSelector(new IntentClassifier),
        new SerpOverlapValidator(new KfFakeSerp),
        new IntentClassifier,
    );
}

function kfCorpus(Site $site): void
{
    $rows = [
        ['French Drain Installation', 5180, 'transactional'],
        ['French Drain Cost', 2400, 'commercial'],
        ['Sump Pump Repair', 2720, 'transactional'],
        ['random junk term', 40, 'informational'],
    ];
    foreach ($rows as [$term, $volume, $intent]) {
        (new KeywordCorpus)->forceFill([
            'site_id' => $site->id, 'term' => $term, 'canonical' => mb_strtolower($term),
            'volume' => $volume, 'intent' => $intent, 'source' => 'expansion',
        ])->save();
    }
}

it('clusters, labels/merges, drops off-trade, heads by demand, and SERP-validates head candidates', function () {
    $site = Site::factory()->create();
    kfCorpus($site);

    // Claude merges the two french-drain terms, keeps sump alone, flags junk off-trade.
    $json = json_encode(['clusters' => [
        ['label' => 'French Drains', 'terms' => ['French Drain Installation', 'French Drain Cost'], 'off_trade' => false],
        ['label' => 'Sump Pump Repair', 'terms' => ['Sump Pump Repair'], 'off_trade' => false],
        ['label' => 'Junk', 'terms' => ['random junk term'], 'off_trade' => true],
    ]]);

    $result = kfPipeline($json)->cluster($site);

    expect($result->clusters)->toBe(2)
        ->and($result->dropped)->toBe(1)
        ->and($result->serpCalls)->toBe(2); // only the 2-candidate french cluster spends SERP

    $clusters = KeywordCluster::withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('label');

    // The drainage silo heads on the highest-demand transactional term — not a low-volume hub name.
    expect($clusters['French Drains']->head_term)->toBe('French Drain Installation')
        ->and($clusters['French Drains']->member_count)->toBe(2)
        ->and($clusters['French Drains']->serp_status)->toBe('confirmed');       // shared domains
    expect($clusters['Sump Pump Repair']->serp_status)->toBe('skipped');          // single candidate

    // Members are stamped with their cluster; the dropped junk term is left unclustered.
    $installation = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->firstWhere('canonical', 'french drain installation');
    expect($installation->cluster_id)->toBe($clusters['French Drains']->id);
    $junk = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->firstWhere('canonical', 'random junk term');
    expect($junk->cluster_id)->toBeNull();
});

it('is re-runnable — clearing prior clusters, never duplicating', function () {
    $site = Site::factory()->create();
    kfCorpus($site);
    $json = json_encode(['clusters' => [
        ['label' => 'French Drains', 'terms' => ['French Drain Installation', 'French Drain Cost'], 'off_trade' => false],
        ['label' => 'Sump Pump Repair', 'terms' => ['Sump Pump Repair'], 'off_trade' => false],
    ]]);

    kfPipeline($json)->cluster($site);
    kfPipeline($json)->cluster($site);

    expect(KeywordCluster::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(3); // 2 labeled + junk residual, not doubled
});

it('excludes operator-dismissed terms from clustering', function () {
    $site = Site::factory()->create();
    kfCorpus($site);
    KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('canonical', 'french drain cost')->update(['disposition' => 'dismissed']);

    $json = json_encode(['clusters' => [
        ['label' => 'French Drains', 'terms' => ['French Drain Installation'], 'off_trade' => false],
    ]]);
    kfPipeline($json)->cluster($site);

    $dismissed = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->firstWhere('canonical', 'french drain cost');
    expect($dismissed->cluster_id)->toBeNull()          // never clustered
        ->and($dismissed->disposition)->toBe('dismissed'); // untouched
});

it('the cluster-corpus command reports clusters + SERP call count', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    kfCorpus($site);
    $this->app->instance(EmbeddingProvider::class, new KfFamilyEmbeddings);
    $this->app->instance(SerpProvider::class, new KfFakeSerp);
    $this->app->when(ClusterLabeler::class)->needs(ClaudeClient::class)
        ->give(fn () => new FakeClaudeClient(json_encode(['clusters' => [
            ['label' => 'French Drains', 'terms' => ['French Drain Installation', 'French Drain Cost'], 'off_trade' => false],
        ]])));

    $this->artisan('launchpad:cluster-corpus', ['--site' => $site->id])
        ->expectsOutputToContain('Clustered SPG')
        ->expectsOutputToContain('SERP calls:')
        ->assertSuccessful();
});
