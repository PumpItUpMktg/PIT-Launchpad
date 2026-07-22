<?php

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Keywords\KeywordIdeaProvider;
use App\Integrations\Keywords\MockKeywordIdeaProvider;
use App\Integrations\Serp\KeywordMetrics;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResultSet;
use App\Interview\Arrange\AutoArrangeRunner;
use App\KeywordGenerator\Cluster\ClusteringPipeline;
use App\KeywordGenerator\Cluster\ClusterLabeler;
use App\KeywordGenerator\Corpus\CorpusAccumulator;
use App\KeywordGenerator\Derive\DerivationPipeline;
use App\KeywordGenerator\Derive\ServicePageGuarantee;
use App\KeywordGenerator\KeywordFirstBuilder;
use App\Models\KeywordCluster;
use App\Models\KeywordCorpus;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Tests\Support\FakeClaudeClient;

class BuilderEmb implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        // Every "french drain …" idea lands in one family so the corpus forms a viable cluster.
        return str_contains(mb_strtolower($text), 'french drain') ? [1.0, 0.0, 0.0] : [0.0, 1.0, 0.0];
    }
}

class BuilderSerp implements SerpProvider
{
    public function metrics(string $query): KeywordMetrics
    {
        return new KeywordMetrics($query, 0, 0);
    }

    public function results(string $query): SerpResultSet
    {
        return new SerpResultSet($query, []);
    }
}

it('the keyword-first builder runs accumulate → cluster → derive → arrange and lands blueprint spokes', function () {
    $site = Site::factory()->create();
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'french drain']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain Installation']);

    // Deterministic seams: mock idea expansion, family embeddings, empty-JSON labeler (falls back to
    // geometry clusters), empty SERP. Arrange is proven elsewhere — assert it's invoked, not its internals.
    $this->app->instance(KeywordIdeaProvider::class, new MockKeywordIdeaProvider);
    $this->app->instance(EmbeddingProvider::class, new BuilderEmb);
    $this->app->instance(SerpProvider::class, new BuilderSerp);
    $this->app->when(ClusterLabeler::class)->needs(ClaudeClient::class)->give(fn () => new FakeClaudeClient('{}'));

    $this->app->instance(ClaudeClient::class, new FakeClaudeClient('{}'));

    $builder = new KeywordFirstBuilder(
        app(CorpusAccumulator::class),
        app(ClusteringPipeline::class),
        app(DerivationPipeline::class),
        app(AutoArrangeRunner::class),
        app(ServicePageGuarantee::class),
    );

    $result = $builder->build($site);

    // The whole chain ran: corpus accumulated, clusters formed, structure derived as blueprint spokes.
    expect(KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBeGreaterThan(0)
        ->and(KeywordCluster::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBeGreaterThan(0)
        ->and($result->silos)->toBeGreaterThan(0)
        ->and(Spoke::withoutGlobalScopes()->where('site_id', $site->id)->where('is_pillar', true)->count())->toBeGreaterThan(0);
});

it('skips re-accumulation when the corpus is fresh (only clusters + derives)', function () {
    $site = Site::factory()->create();
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'french drain']);
    // A fresh corpus row already exists.
    (new KeywordCorpus)->forceFill([
        'site_id' => $site->id, 'term' => 'french drain installation', 'canonical' => 'french drain installation',
        'volume' => 5180, 'intent' => 'transactional', 'source' => 'seed', 'last_refreshed_at' => now(),
    ])->save();

    // An idea provider that would THROW if called — proves accumulation is skipped.
    $this->app->instance(KeywordIdeaProvider::class, new class implements KeywordIdeaProvider
    {
        public function ideas(Site $site, string $seed, int $limit): array
        {
            throw new RuntimeException('should not accumulate a fresh corpus');
        }
    });
    $this->app->instance(EmbeddingProvider::class, new BuilderEmb);
    $this->app->instance(SerpProvider::class, new BuilderSerp);
    $this->app->when(ClusterLabeler::class)->needs(ClaudeClient::class)->give(fn () => new FakeClaudeClient('{}'));

    $this->app->instance(ClaudeClient::class, new FakeClaudeClient('{}'));

    $builder = new KeywordFirstBuilder(
        app(CorpusAccumulator::class),
        app(ClusteringPipeline::class),
        app(DerivationPipeline::class),
        app(AutoArrangeRunner::class),
        app(ServicePageGuarantee::class),
    );

    $builder->build($site); // no throw = accumulation skipped

    expect(KeywordCluster::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBeGreaterThan(0);
});
