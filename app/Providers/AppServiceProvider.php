<?php

namespace App\Providers;

use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\RelevanceScorer;
use App\Integrations\Claude\AnthropicClaudeClient;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\News\MockNewsProvider;
use App\Integrations\News\MockOnDemandSourcePull;
use App\Integrations\News\NewsProvider;
use App\Integrations\News\OnDemandSourcePull;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Support\CurrentSite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentSite::class);

        $this->app->bind(ClaudeClient::class, fn () => new AnthropicClaudeClient(
            (string) config('services.anthropic.key'),
            (string) config('services.anthropic.model', 'claude-opus-4-8'),
            (int) config('services.anthropic.max_tokens', 4096),
        ));

        // Vendors are deferred: default to mock adapters behind the
        // capability-role interfaces. Real adapters bind here later with no
        // change to scoring/beatability/tracking (§5) or the candidate funnel (§6a).
        $this->app->singleton(SerpProvider::class, MockSerpProvider::class);
        $this->app->singleton(LocalGridProvider::class, MockLocalGridProvider::class);
        $this->app->singleton(NewsProvider::class, MockNewsProvider::class);
        $this->app->singleton(EmbeddingProvider::class, MockEmbeddingProvider::class);
        $this->app->singleton(OnDemandSourcePull::class, MockOnDemandSourcePull::class);

        // Relevance scoring runs on the cheaper Haiku model, so route the
        // scorer's ClaudeClient there.
        $this->app->when(RelevanceScorer::class)
            ->needs(ClaudeClient::class)
            ->give(fn () => new AnthropicClaudeClient(
                (string) config('services.anthropic.key'),
                (string) config('services.anthropic.scoring_model', 'claude-haiku-4-5'),
                (int) config('services.anthropic.max_tokens', 4096),
            ));

        // Drafting (§6b) is quality-sensitive and runs on Sonnet — route the
        // Drafter's ClaudeClient to the drafting model.
        $this->app->when(Drafter::class)
            ->needs(ClaudeClient::class)
            ->give(fn () => new AnthropicClaudeClient(
                (string) config('services.anthropic.key'),
                (string) config('services.anthropic.drafting_model', 'claude-sonnet-4-6'),
                (int) config('services.anthropic.max_tokens', 4096),
            ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
