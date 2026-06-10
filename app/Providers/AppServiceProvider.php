<?php

namespace App\Providers;

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Feeds\FeedFetcher;
use App\ContentEngine\Feeds\FeedHealth;
use App\ContentEngine\Feeds\FeedValidator;
use App\ContentEngine\Feeds\GeneratedFeedReconciler;
use App\ContentEngine\RelevanceScorer;
use App\Enums\AuditAction;
use App\Enums\DataForSeoMode;
use App\Enums\EmbeddingsProvider as EmbeddingsProviderType;
use App\Enums\NewsProvider as NewsProviderType;
use App\Integrations\Census\CensusProvider;
use App\Integrations\Census\MockCensusProvider;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\ClaudeClientFactory;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\ConversionProviders;
use App\Integrations\Conversions\Ga4ConversionProvider;
use App\Integrations\Conversions\KrayinConversionProvider;
use App\Integrations\Conversions\MauticConversionProvider;
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
use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleOAuthClient;
use App\Integrations\Google\GoogleSearchConsoleProvider;
use App\Integrations\Google\SearchConsoleProvider;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\News\GdeltNewsProvider;
use App\Integrations\News\GdeltRateLimiter;
use App\Integrations\News\GoogleNewsRssProvider;
use App\Integrations\News\MockOnDemandSourcePull;
use App\Integrations\News\NewsApiProvider;
use App\Integrations\News\NewsProvider;
use App\Integrations\News\OnDemandSourcePull;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Vision\ClaudeVisionClient;
use App\Integrations\Vision\VisionClient;
use App\Integrations\Voice\MockVoiceSynthesizer;
use App\Integrations\Voice\VoiceSynthesizer;
use App\KeywordGenerator\Pipeline\KeywordPipeline;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\KeywordGenerator\Tracking\PositionTracker;
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

        $this->app->bind(ClaudeClient::class, fn ($app) => $app->make(ClaudeClientFactory::class)->default());

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
            return match ($this->newsProviderChoice()) {
                NewsProviderType::NewsApi => new NewsApiProvider(
                    $this->app->make(Http::class),
                    (string) config('services.news.key'),
                    (string) config('services.news.base_url', 'https://newsapi.org/v2'),
                    (int) config('services.news.recency_days', 90),
                    (int) config('services.news.timeout', 30),
                ),
                NewsProviderType::Gdelt => new GdeltNewsProvider(
                    $this->app->make(Http::class),
                    new GdeltRateLimiter(
                        $this->app->make(CacheRepository::class),
                        (int) config('services.news.gdelt_throttle_seconds', 6),
                    ),
                    (string) config('services.news.gdelt_base_url', 'https://api.gdeltproject.org/api/v2/doc/doc'),
                    (int) config('services.news.gdelt_max_records', 250),
                    (int) config('services.news.recency_days', 90),
                    (int) config('services.news.timeout', 30),
                ),
                default => new GoogleNewsRssProvider(
                    $this->app->make(Http::class),
                    (string) config('services.news.googlenews_base_url', 'https://news.google.com'),
                    (string) config('services.news.googlenews_hl', 'en-US'),
                    (string) config('services.news.googlenews_gl', 'US'),
                    (string) config('services.news.googlenews_ceid', 'US:en'),
                    (int) config('services.news.recency_days', 90),
                    (int) config('services.news.timeout', 30),
                ),
            };
        });

        // §6a Phase 2 feed services. FeedFetcher is the single host-branched fetch
        // path (consent recipe only for news.google.com); the validator, health
        // and reconciler build on it + config. FeedIngestor auto-resolves from
        // FeedFetcher + CandidateFunnel. Tests use Http::fake, so CI makes no call.
        $this->app->singleton(FeedFetcher::class, fn () => new FeedFetcher(
            $this->app->make(Http::class),
            (int) config('launchpad.feeds.fetch_timeout', 30),
            (int) config('launchpad.feeds.fetch_max_items', 100),
        ));
        $this->app->singleton(FeedValidator::class, fn () => new FeedValidator(
            $this->app->make(FeedFetcher::class),
            (int) config('launchpad.feeds.client_soft_cap', 25),
        ));
        $this->app->singleton(FeedHealth::class, fn () => new FeedHealth(
            (int) config('launchpad.feeds.unhealthy_after_days', 21),
        ));
        $this->app->singleton(GeneratedFeedReconciler::class, fn () => new GeneratedFeedReconciler(
            (string) config('launchpad.feeds.generated.base_url', 'https://news.google.com'),
            (string) config('launchpad.feeds.generated.hl', 'en-US'),
            (string) config('launchpad.feeds.generated.gl', 'US'),
            (string) config('launchpad.feeds.generated.ceid', 'US:en'),
        ));

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

        // Google (Step 2, Adapter 4): per-tenant OAuth backing GSC (§5) + GA4
        // (§7c). Platform OAuth app creds are env; per-client tokens live in the
        // §9 vault on the Connection. The connection service owns refresh +
        // lifecycle. Tests bind fakes / Http::fake; creds are scrubbed in tests.
        $this->app->singleton(GoogleOAuthClient::class, fn () => new GoogleOAuthClient(
            $this->app->make(Http::class),
            (string) config('services.google.client_id'),
            (string) config('services.google.client_secret'),
            (string) config('services.google.redirect_uri'),
            (string) config('services.google.auth_uri', 'https://accounts.google.com/o/oauth2/v2/auth'),
            (string) config('services.google.token_uri', 'https://oauth2.googleapis.com/token'),
            (int) config('services.google.timeout', 30),
        ));

        $this->app->singleton(GoogleConnectionService::class, fn () => new GoogleConnectionService(
            $this->app->make(Http::class),
            $this->app->make(GoogleOAuthClient::class),
            (int) config('services.google.timeout', 30),
        ));

        // §7c conversions (Step 2, Adapter 5): GA4 + Krayin + Mautic can all be
        // active for one tenant — the IngestConversions job aggregates the tagged
        // set. Each is dormant (returns nothing) until its source is connected /
        // deployed. ConversionProvider::class stays bound to GA4 for back-compat.
        $this->app->singleton(Ga4ConversionProvider::class, fn () => new Ga4ConversionProvider(
            $this->app->make(GoogleConnectionService::class),
            (string) config('services.google.ga4_data_base_url', 'https://analyticsdata.googleapis.com/v1beta'),
        ));
        $this->app->singleton(KrayinConversionProvider::class, fn () => new KrayinConversionProvider(
            $this->app->make(Http::class),
            (string) config('services.krayin.base_url'),
            (string) config('services.krayin.token'),
            (array) config('services.krayin.won_stages', ['won']),
            (int) config('services.krayin.timeout', 30),
        ));
        $this->app->singleton(MauticConversionProvider::class, fn () => new MauticConversionProvider(
            $this->app->make(Http::class),
            $this->app->make(CacheRepository::class),
            (string) config('services.mautic.base_url'),
            (string) config('services.mautic.client_id'),
            (string) config('services.mautic.client_secret'),
            config('services.mautic.conversion_form_id') !== null ? (string) config('services.mautic.conversion_form_id') : null,
            (int) config('services.mautic.timeout', 30),
        ));
        $this->app->bind(ConversionProvider::class, Ga4ConversionProvider::class);
        $this->app->tag([
            Ga4ConversionProvider::class,
            KrayinConversionProvider::class,
            MauticConversionProvider::class,
        ], 'conversion.providers');
        $this->app->singleton(ConversionProviders::class, fn ($app) => new ConversionProviders(app: $app));

        // §5 pipeline driver — the caller that runs discovery + position tracking
        // per site (cadence read off durable artifacts). §5 internals unchanged.
        $this->app->bind(SitePipelineRefresher::class, fn ($app) => new SitePipelineRefresher(
            $app->make(KeywordPipeline::class),
            $app->make(PositionTracker::class),
            $app->make(SerpProvider::class),
            $app->make(LocalGridProvider::class),
            (int) config('content_engine.pipeline.tracking_cadence_days', 1),
            (int) config('content_engine.pipeline.discovery_cadence_days', 7),
        ));

        // §5 GSC first-party calibration seam (net-new). No §5 consumer wired yet —
        // SiteAuthority calibrates off DataForSEO position history; this supplies
        // the normalized rows for a later §5 change.
        $this->app->singleton(SearchConsoleProvider::class, fn () => new GoogleSearchConsoleProvider(
            $this->app->make(GoogleConnectionService::class),
            (string) config('services.google.gsc_base_url', 'https://www.googleapis.com/webmasters/v3'),
        ));

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
        // thinking; drafting is quality-sensitive and runs on Sonnet with adaptive
        // thinking. Both clients come from the one factory so the probe can build
        // the identical client (see ClaudeClientFactory).
        $this->app->when(RelevanceScorer::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->scoring());

        // The shared drafting MECHANISM (DraftCall) carries the budget-fixed
        // drafting client; every draft sibling (post Drafter, PageDrafter) depends
        // on it, so the model call + parse live in exactly one place.
        $this->app->bind(
            DraftCall::class,
            fn ($app) => new DraftCall($app->make(ClaudeClientFactory::class)->drafting()),
        );
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
        return NewsProviderType::tryFrom((string) config('services.news.provider', 'googlenews'))
            ?? NewsProviderType::GoogleNews;
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
