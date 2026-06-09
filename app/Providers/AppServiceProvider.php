<?php

namespace App\Providers;

use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\RelevanceScorer;
use App\Enums\AuditAction;
use App\Enums\DataForSeoMode;
use App\Enums\EmbeddingsProvider as EmbeddingsProviderType;
use App\Enums\NewsProvider as NewsProviderType;
use App\Integrations\Census\CensusProvider;
use App\Integrations\Census\MockCensusProvider;
use App\Integrations\Claude\AnthropicClaudeClient;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\MockConversionProvider;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocalGridProvider;
use App\Integrations\DataForSeo\DataForSeoSerpProvider;
use App\Integrations\DataForSeo\SerpTaskDispatcher;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\OpenAiEmbeddingProvider;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalHttpClient;
use App\Integrations\Gbp\GbpProvider;
use App\Integrations\Gbp\MockGbpProvider;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\News\GdeltNewsProvider;
use App\Integrations\News\GdeltRateLimiter;
use App\Integrations\News\MockOnDemandSourcePull;
use App\Integrations\News\NewsApiProvider;
use App\Integrations\News\NewsProvider;
use App\Integrations\News\OnDemandSourcePull;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Vision\ClaudeVisionClient;
use App\Integrations\Vision\VisionClient;
use App\Integrations\Voice\MockVoiceSynthesizer;
use App\Integrations\Voice\VoiceSynthesizer;
use App\Models\User;
use App\Security\Audit;
use App\Security\Verification\ConnectionVerifier;
use App\Security\Verification\WordpressConnectionVerifier;
use App\Support\CurrentSite;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Auth;
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

        // §7a onboarding adapters are deferred: default to mocks behind the
        // capability-role interfaces (GBP category seeding, Census enrichment,
        // Claude voice synthesis). Real adapters bind here later.
        $this->app->bind(GbpProvider::class, MockGbpProvider::class);
        $this->app->bind(CensusProvider::class, MockCensusProvider::class);
        $this->app->bind(VoiceSynthesizer::class, MockVoiceSynthesizer::class);

        // §5 SERP + local-grid run on the real DataForSEO adapters (Step 2,
        // Adapter 1). They supply NORMALIZED signals only — opportunity scoring
        // and two-lane beatability stay in §5, behind the unchanged contracts.
        // Tests bind a fake adapter / Http::fake (same pattern as Claude/fal), so
        // CI makes no live call and needs no credentials.
        $this->app->singleton(DataForSeoClient::class, fn () => new DataForSeoClient(
            $this->app->make(Http::class),
            (string) config('services.dataforseo.login'),
            (string) config('services.dataforseo.password'),
            (string) config('services.dataforseo.base_url', 'https://api.dataforseo.com'),
            (int) config('services.dataforseo.timeout', 30),
        ));

        $this->app->singleton(SerpProvider::class, fn () => new DataForSeoSerpProvider(
            $this->app->make(DataForSeoClient::class),
            $this->app->make(SerpTaskDispatcher::class),
            $this->app->make(CacheRepository::class),
            $this->dataForSeoMode(),
            (int) config('services.dataforseo.location_code', 2840),
            (string) config('services.dataforseo.language_code', 'en'),
            (int) config('services.dataforseo.serp_depth', 20),
            (int) config('services.dataforseo.related_limit', 20),
            (int) config('services.dataforseo.cache_ttl_hours', 168),
        ));

        $this->app->singleton(LocalGridProvider::class, fn () => new DataForSeoLocalGridProvider(
            $this->app->make(DataForSeoClient::class),
            $this->app->make(SerpTaskDispatcher::class),
            $this->app->make(CacheRepository::class),
            $this->dataForSeoMode(),
            (string) config('services.dataforseo.language_code', 'en'),
            (int) config('services.dataforseo.grid_size', 3),
            (float) config('services.dataforseo.grid_step', 0.018),
            (int) config('services.dataforseo.cache_ttl_hours', 168),
        ));
        $this->app->singleton(OnDemandSourcePull::class, MockOnDemandSourcePull::class);

        // §6a news source runs on a real adapter (Step 2, Adapter 2): GDELT by
        // default (no key), NewsAPI when configured. Behind the unchanged §6a
        // NewsProvider contract — the candidate funnel/scoring is untouched.
        // Tests bind a fake source / Http::fake, so CI makes no live call.
        $this->app->singleton(NewsProvider::class, function () {
            if ($this->newsProviderChoice() === NewsProviderType::NewsApi) {
                return new NewsApiProvider(
                    $this->app->make(Http::class),
                    (string) config('services.news.key'),
                    (string) config('services.news.base_url', 'https://newsapi.org/v2'),
                    (int) config('services.news.recency_days', 90),
                    (int) config('services.news.timeout', 30),
                );
            }

            return new GdeltNewsProvider(
                $this->app->make(Http::class),
                new GdeltRateLimiter(
                    $this->app->make(CacheRepository::class),
                    (int) config('services.news.gdelt_throttle_seconds', 6),
                ),
                (string) config('services.news.gdelt_base_url', 'https://api.gdeltproject.org/api/v2/doc/doc'),
                (int) config('services.news.gdelt_max_records', 250),
                (int) config('services.news.recency_days', 90),
                (int) config('services.news.timeout', 30),
            );
        });

        // §6 near-duplicate embeddings run on the real OpenAI adapter (Step 2,
        // Adapter 3) behind the unchanged EmbeddingProvider contract — vectors
        // only; the similarity/clustering logic stays in §6. Tests bind a fake /
        // Http::fake, and credentials are scrubbed in tests, so CI makes no call.
        $this->app->singleton(EmbeddingProvider::class, function () {
            return match ($this->embeddingsProviderChoice()) {
                default => new OpenAiEmbeddingProvider(
                    $this->app->make(Http::class),
                    $this->app->make(CacheRepository::class),
                    (string) config('services.openai.key'),
                    (string) config('services.openai.base_url', 'https://api.openai.com/v1'),
                    (string) config('services.openai.embedding_model', 'text-embedding-3-small'),
                    (int) config('services.openai.embedding_dimensions', 1536),
                ),
            };
        });

        // §7c conversion ingestion (GA4/GHL → leads) is mock-first; the real
        // pull+normalize adapter binds here later with no dashboard change.
        $this->app->singleton(
            ConversionProvider::class,
            MockConversionProvider::class,
        );

        // §2 publish-path adapters (committed vendors). fal generates images and
        // Claude vision finalizes alt text; both are mocked in tests, no network.
        $this->app->bind(FalClient::class, fn ($app) => new FalHttpClient(
            $app->make(Http::class),
            (string) config('services.fal.key'),
            (string) config('services.fal.base_url', 'https://fal.run'),
            (string) config('services.fal.image_model', 'fal-ai/flux/dev'),
            (int) config('services.fal.timeout', 60),
        ));

        $this->app->bind(VisionClient::class, fn ($app) => new ClaudeVisionClient(
            $app->make(Http::class),
            (string) config('services.anthropic.key'),
            (string) config('services.anthropic.vision_model', 'claude-sonnet-4-6'),
        ));

        // §9 credential rotation verifies the new secret with a live provider
        // call before revoking the old. §2 backs the verifier with the real WP
        // REST client (a live WordPress ping); other providers stay permissive
        // until their adapters land (e.g. GBP, with the GBP integration).
        $this->app->singleton(ConnectionVerifier::class, WordpressConnectionVerifier::class);

        // Relevance scoring runs on the cheaper Haiku model with NO extended
        // thinking — Haiku doesn't support it, and a cheap scoring pass doesn't
        // want a reasoning budget. The call site declares intent (thinking: null).
        $this->app->when(RelevanceScorer::class)
            ->needs(ClaudeClient::class)
            ->give(fn () => new AnthropicClaudeClient(
                (string) config('services.anthropic.key'),
                (string) config('services.anthropic.scoring_model', 'claude-haiku-4-5'),
                (int) config('services.anthropic.max_tokens', 4096),
                thinking: null,
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
     * Resolve the configured DataForSEO request mode, defaulting to standard
     * (task-based) on an unrecognized value.
     */
    private function dataForSeoMode(): DataForSeoMode
    {
        return DataForSeoMode::tryFrom((string) config('services.dataforseo.mode', 'standard'))
            ?? DataForSeoMode::Standard;
    }

    /**
     * Resolve the configured news source, defaulting to GDELT (no key) on an
     * unrecognized value.
     */
    private function newsProviderChoice(): NewsProviderType
    {
        return NewsProviderType::tryFrom((string) config('services.news.provider', 'gdelt'))
            ?? NewsProviderType::Gdelt;
    }

    /**
     * Resolve the configured embeddings backend, defaulting to OpenAI on an
     * unrecognized value.
     */
    private function embeddingsProviderChoice(): EmbeddingsProviderType
    {
        return EmbeddingsProviderType::tryFrom((string) config('services.openai.provider', 'openai'))
            ?? EmbeddingsProviderType::OpenAi;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // §9 audit: record RBAC role changes. (Publish — ContentPublished — is
        // emitted by the §2 publish pipeline; that call site attaches there.)
        User::updated(function (User $user): void {
            if (! $user->wasChanged('role')) {
                return;
            }

            app(Audit::class)->log(AuditAction::RoleChanged, $user, Auth::id(), [
                'from' => $user->getRawOriginal('role'),
                'to' => $user->role->value,
            ]);
        });
    }
}
