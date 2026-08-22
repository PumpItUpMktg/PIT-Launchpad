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
use App\Gathering\IntakeExtractor;
use App\Gathering\InterviewEngine;
use App\Integrations\Analytics\Ga4PageTraffic;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Integrations\BingWebmaster\BingWebmaster;
use App\Integrations\BingWebmaster\BingWebmasterProvider;
use App\Integrations\BingWebmaster\NullBingWebmaster;
use App\Integrations\Census\CensusGeocoder;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\CensusProvider;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GoogleGeocoder;
use App\Integrations\Census\MockCensusProvider;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Census\TigerwebGazetteer;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\ClaudeClientFactory;
use App\Integrations\Cloudflare\CloudflareClient;
use App\Integrations\Cloudflare\HttpCloudflareClient;
use App\Integrations\Cloudflare\MockCloudflareClient;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\ConversionProviders;
use App\Integrations\Conversions\Ga4ConversionProvider;
use App\Integrations\Conversions\KrayinConversionProvider;
use App\Integrations\Conversions\MauticConversionProvider;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocalGridProvider;
use App\Integrations\DataForSeo\DataForSeoLocations;
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
use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Integrations\Keywords\DataForSeoKeywordIdeaProvider;
use App\Integrations\Keywords\KeywordIdeaProvider;
use App\Integrations\Local\LocalSignalProvider;
use App\Integrations\Local\MockLocalSignalProvider;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\News\GdeltNewsProvider;
use App\Integrations\News\GdeltRateLimiter;
use App\Integrations\News\GoogleNewsRssProvider;
use App\Integrations\News\MockOnDemandSourcePull;
use App\Integrations\News\NewsApiProvider;
use App\Integrations\News\NewsProvider;
use App\Integrations\News\OnDemandSourcePull;
use App\Integrations\Places\GooglePlacesClient;
use App\Integrations\Places\PlacesProvider;
use App\Integrations\SearchConsole\GoogleSearchConsole;
use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\UrlInspection\GoogleIndexInspector;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\Vision\ClaudeVisionClient;
use App\Integrations\Vision\VisionClient;
use App\Integrations\Voice\MockVoiceSynthesizer;
use App\Integrations\Voice\VoiceSynthesizer;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Interview\Arrange\CrossSiloDedup;
use App\Interview\Arrange\FoldTargetAssigner;
use App\Interview\Arrange\KeywordAssigner;
use App\Interview\Arrange\SubClusterDetector;
use App\Interview\Expansion\SiloExpander;
use App\Interview\Volume\VolumeGrounder;
use App\JobCapture\Enhancement\JobEnhancer;
use App\KeywordGenerator\Cadence\CadenceScheduler;
use App\KeywordGenerator\Cadence\Tiering;
use App\KeywordGenerator\Cluster\ClusterLabeler;
use App\KeywordGenerator\Discovery\SiloKeywordGenerator;
use App\KeywordGenerator\Pipeline\KeywordPipeline;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\KeywordGenerator\Tracking\PositionTracker;
use App\Listeners\SyncWireframeKitsOnMigrate;
use App\Local\Proof\LocalJobProvider;
use App\Local\Proof\LocalReviewProvider;
use App\Local\Proof\NullLocalJobs;
use App\Local\Proof\NullLocalReviews;
use App\Local\Proof\NullServiceJobs;
use App\Local\Proof\NullServiceReviews;
use App\Local\Proof\ServiceJobProvider;
use App\Local\Proof\ServiceReviewProvider;
use App\Locations\Dma\MetroResolver;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Providers\GscMetricProvider;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\User;
use App\Onboarding\MissionPolisher;
use App\Operator\Controls\BudgetControl;
use App\Publishing\Seo\HeadlineKeywordFixer;
use App\Security\Audit;
use App\Security\Verification\ConnectionVerifier;
use App\Security\Verification\WordpressConnectionVerifier;
use App\Support\CurrentSite;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentSite::class);

        // Client-dashboard metric spine (§ Client Dashboard v1): the provider registry is a singleton with
        // its providers registered here. GSC (PR 2) rolls up from gsc_url_daily; DataForSEO (PR 4) will join
        // it. SyncSiteMetrics resolves providers from it by key.
        $this->app->singleton(MetricProviderRegistry::class, function ($app): MetricProviderRegistry {
            $registry = new MetricProviderRegistry;
            $registry->register(new GscMetricProvider);
            $registry->register($app->make(IndexMetricProvider::class));

            return $registry;
        });

        $this->app->bind(ClaudeClient::class, fn ($app) => $app->make(ClaudeClientFactory::class)->default());

        // §7a onboarding adapters are deferred: default to mocks behind the
        // capability-role interfaces (GBP category seeding, Census enrichment,
        // Claude voice synthesis). Real adapters bind here later.
        $this->app->bind(GbpProvider::class, MockGbpProvider::class);
        $this->app->bind(CensusProvider::class, MockCensusProvider::class);

        // Locations layer — service-area enumeration runs on the real Census TIGERweb
        // adapter (no key needed); tests bind a Mock / Http::fake so CI makes no call.
        $this->app->bind(MunicipalityGazetteer::class, fn () => new TigerwebGazetteer(
            $this->app->make(Http::class),
            (string) config('services.census.tigerweb_url'),
            (int) config('services.census.tigerweb_places_layer'),
            (int) config('services.census.tigerweb_cousub_layer'),
            (int) config('services.census.tigerweb_timeout', 30),
            (int) config('services.census.tigerweb_counties_layer', 82),
        ));
        // ACS population for the county-coverage town grouping (keyed + cached; degrades
        // to ungrouped without CENSUS_API_KEY). Tests Http::fake the ACS array.
        $this->app->bind(CensusPopulation::class, fn () => new CensusPopulation(
            $this->app->make(Http::class),
            $this->app->make(CacheRepository::class),
            (string) config('services.census.key', ''),
            (string) config('services.census.acs_year', '2022'),
        ));
        // Locations base geocoding: Google Geocoding API (resolves unincorporated /
        // edge addresses Census misses) with the keyless Census geocoder as a no-key
        // fallback. Tests bind a Mock so CI makes no call.
        $this->app->bind(Geocoder::class, function () {
            $census = new CensusGeocoder(
                $this->app->make(Http::class),
                (string) config('services.census.geocoder_url'),
                (string) config('services.census.geocoder_benchmark', 'Public_AR_Current'),
                (int) config('services.census.geocoder_timeout', 15),
            );

            $key = (string) config('services.google.maps_api_key', '');
            if ($key === '') {
                return $census;
            }

            return new GoogleGeocoder(
                $this->app->make(Http::class),
                $key,
                (string) config('services.google.geocoder_url', 'https://maps.googleapis.com/maps/api/geocode/json'),
                $census,
                (int) config('services.census.geocoder_timeout', 15),
            );
        });
        $this->app->bind(VoiceSynthesizer::class, MockVoiceSynthesizer::class);

        // Phase 3 — silo volume grounding (paid DataForSEO, explicit trigger). Reuses
        // the real DataForSEO client; tests construct the grounder directly / Http::fake.
        $this->app->bind(VolumeGrounder::class, fn ($app) => new VolumeGrounder(
            $app->make(MetroResolver::class),
            $app->make(DataForSeoClient::class),
            $app->make(DataForSeoLocations::class),
            (string) config('launchpad.silo_volume.language', 'en'),
        ));

        // The Google Ads locations catalog used to resolve covered metros to a numeric
        // location_code (cached). Tests Http::fake the locations endpoint.
        $this->app->singleton(DataForSeoLocations::class, fn ($app) => new DataForSeoLocations(
            $app->make(DataForSeoClient::class),
            $app->make(CacheRepository::class),
            (string) config('services.dataforseo.locations_country', 'US'),
            (int) config('services.dataforseo.locations_cache_days', 30),
        ));

        // §7 onboarding — the Google Places import (location enrichment) runs on
        // the real adapter; tests bind MockPlacesProvider so no key/network.
        $this->app->bind(PlacesProvider::class, fn () => new GooglePlacesClient(
            $this->app->make(Factory::class),
            (string) config('services.google.maps_api_key', ''),
            (int) config('services.google.timeout', 15),
        ));

        // Per-business local signals (the location-page drip). Mock is the default —
        // it produces deterministic-but-per-site-distinct numbers so no two businesses
        // share local data. Real adapters (Places competitor density, GBP reviews,
        // trade-specific Air Quality / Pollen, job-capture counts) bind here as keys
        // come online, with no change to the relevance scoring that consumes them.
        $this->app->singleton(LocalSignalProvider::class, MockLocalSignalProvider::class);

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

        // Cloudflare edge auto-config seam (agency-wide token). Real adapter when a token is set; the
        // no-network mock otherwise (the connect UI then reports "not configured").
        $this->app->singleton(CloudflareClient::class, function () {
            $token = (string) config('services.cloudflare.api_token', '');

            return $token !== ''
                ? new HttpCloudflareClient($token, (int) config('services.cloudflare.timeout', 20))
                : new MockCloudflareClient;
        });

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
        // Keyword-first corpus expansion (Part 1) — DataForSEO related_keywords with metrics, geo-
        // localized per tenant. Tests bind MockKeywordIdeaProvider; no live call in CI.
        $this->app->singleton(KeywordIdeaProvider::class, fn () => new DataForSeoKeywordIdeaProvider(
            $this->app->make(DataForSeoClient::class),
            $this->app->make(DataForSeoLocations::class),
            (int) config('services.dataforseo.location_code', 2840),
            (string) config('services.dataforseo.language_code', 'en'),
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

        // auto-arrange structural passes — config-driven cosine thresholds (per-site
        // overridable later). Every relatedness call rides on the EmbeddingProvider above.
        $this->app->bind(CrossSiloDedup::class, fn () => new CrossSiloDedup(
            (float) config('launchpad.auto_arrange.dedup_cosine', 0.85),
            (float) config('launchpad.auto_arrange.dedup_ambiguity_margin', 0.15),
        ));
        $this->app->bind(FoldTargetAssigner::class, fn () => new FoldTargetAssigner(
            (float) config('launchpad.auto_arrange.nest_floor', 0.70),
            (float) config('launchpad.auto_arrange.reflip_margin', 0.05),
        ));
        $this->app->bind(SubClusterDetector::class, fn () => new SubClusterDetector(
            (float) config('launchpad.auto_arrange.sub_hub_overlap', 0.60),
        ));
        $this->app->bind(KeywordAssigner::class, fn () => new KeywordAssigner(
            (float) config('launchpad.auto_arrange.collision_cosine', 0.90),
        ));

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
            $app->make(SiloKeywordGenerator::class),
            $app->make(Tiering::class),
            $app->make(CadenceScheduler::class),
            $app->make(BudgetControl::class),
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

        // Location-page gated sections — contract-first: the review-sync and field job-capture
        // systems aren't deployed, so the null providers bind (sections omit). Real providers
        // replace these bindings with no composer changes.
        $this->app->bind(LocalReviewProvider::class, NullLocalReviews::class);
        $this->app->bind(LocalJobProvider::class, NullLocalJobs::class);
        $this->app->bind(ServiceReviewProvider::class, NullServiceReviews::class);
        $this->app->bind(ServiceJobProvider::class, NullServiceJobs::class);
        // Card-facing GSC: the real bridge onto the shared Google grant (PR-A). It reports "connected"
        // only once the grant is live AND the Site has a GSC property picked; until then connected() is
        // false and the cards show the honest connect/collecting prompt (same as the old Null default).
        $this->app->bind(\App\Integrations\SearchConsole\SearchConsoleProvider::class, fn () => new GoogleSearchConsole(
            $this->app->make(GoogleConnectionService::class),
            $this->app->make(CacheRepository::class),
            (string) config('services.google.gsc_base_url', 'https://www.googleapis.com/webmasters/v3'),
            (int) config('services.google.gsc_cache_ttl', 21600),
        ));
        // URL Inspection — the authoritative per-URL index-coverage seam (coverageState). Bound to the
        // real Google adapter like the GSC seam; it self-gates at runtime via connected() (no grant / no
        // property → not connected → null), so no key check is needed here.
        $this->app->bind(IndexInspector::class, fn () => new GoogleIndexInspector(
            $this->app->make(GoogleConnectionService::class),
            $this->app->make(CacheRepository::class),
            (string) config('services.google.gsc_inspection_base_url', 'https://searchconsole.googleapis.com/v1'),
            (int) config('services.google.url_inspection_cache_ttl', 259200),
            (int) config('services.google.url_inspection_daily_cap', 1800),
        ));

        // Bing Webmaster Tools — the Bing analog of the GSC seam. Mock-first: the real adapter binds
        // only when an agency API key is configured (a Site is "connected" once it ALSO has a
        // bing_site_url); without a key it stays the Null adapter and cards show "Submitted to Bing" only.
        $this->app->bind(BingWebmasterProvider::class, function () {
            $key = (string) config('services.bing.api_key', '');
            if (trim($key) === '') {
                return new NullBingWebmaster;
            }

            return new BingWebmaster(
                $this->app->make(Http::class),
                $this->app->make(CacheRepository::class),
                $key,
                (string) config('services.bing.base_url', 'https://ssl.bing.com/webmaster/api.svc/json'),
                (int) config('services.bing.timeout', 15),
                (int) config('services.bing.cache_ttl', 21600),
            );
        });
        // Sitemap → Search Console submission (indexing), on the same shared grant + gsc_property.
        $this->app->bind(SitemapSubmitter::class, fn () => new SitemapSubmitter(
            $this->app->make(GoogleConnectionService::class),
            (string) config('services.google.gsc_base_url', 'https://www.googleapis.com/webmasters/v3'),
            (string) config('services.google.sitemap_path', '/sitemap.xml'),
        ));
        // IndexNow — instant crawl ping to Bing/Yandex/Seznam/Naver; deploys its key via the plugin.
        $this->app->bind(IndexNowSubmitter::class, fn () => new IndexNowSubmitter(
            $this->app->make(Http::class),
            $this->app->make(WordpressClientFactory::class),
            (string) config('services.indexnow.endpoint', 'https://api.indexnow.org/indexnow'),
            (bool) config('services.indexnow.enabled', true),
            (int) config('services.indexnow.timeout', 15),
        ));
        // Card-facing GA4: the real bridge onto the shared Google grant (PR-A), sibling of the GSC
        // one. connected() is true only once the grant is live AND the Site has a GA4 property picked;
        // until then the cards show the honest "Connect GA4" / collecting prompt (old Null behavior).
        $this->app->bind(PageTrafficProvider::class, fn () => new Ga4PageTraffic(
            $this->app->make(GoogleConnectionService::class),
            $this->app->make(CacheRepository::class),
            (string) config('services.google.ga4_data_base_url', 'https://analyticsdata.googleapis.com/v1beta'),
            (int) config('services.google.ga4_cache_ttl', 21600),
        ));

        // Relevance scoring runs on the cheaper Haiku model with NO extended
        // thinking; drafting is quality-sensitive and runs on Sonnet with adaptive
        // thinking. Both clients come from the one factory so the probe can build
        // the identical client (see ClaudeClientFactory).
        $this->app->when(RelevanceScorer::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->scoring());

        // Mission polish is an opt-in one-sentence cleanup on the Brand intake — the cheap scoring
        // model is plenty; the honesty constraints live in the MissionPolisher prompt.
        $this->app->when(MissionPolisher::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->scoring());

        // The service-headline now-fixer reworks three short SEO fields to lead with the target keyword —
        // a cheap Haiku pass with a deterministic keyword-guaranteed fallback.
        $this->app->when(HeadlineKeywordFixer::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->scoring());

        // Keyword-first cluster labeling (merge/split/drop) is a cheap Haiku pass over term lists.
        $this->app->when(ClusterLabeler::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->scoring());

        // The Phase-2 silo expander emits a large JSON tree: a generous token budget
        // + an assistant "{" prefill (raw JSON, no fences) from the one factory.
        $this->app->when(SiloExpander::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->expander());

        // The shared drafting MECHANISM (DraftCall) carries the budget-fixed
        // drafting client; every draft sibling (post Drafter, PageDrafter) depends
        // on it, so the model call + parse live in exactly one place.
        $this->app->bind(
            DraftCall::class,
            fn ($app) => new DraftCall($app->make(ClaudeClientFactory::class)->drafting()),
        );

        // The gathering interview + extraction run on the Sonnet drafting lane —
        // conversation quality matters, turns stay cheap, no tools in the loop.
        // Tests bind fakes on ClaudeClient as usual.
        $this->app->when([InterviewEngine::class, IntakeExtractor::class])
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->drafting());

        // Job Capture enhancement (§7) — the SEO write-up runs on the Sonnet drafting lane.
        // Tests construct JobEnhancer with a FakeClaudeClient directly.
        $this->app->when(JobEnhancer::class)
            ->needs(ClaudeClient::class)
            ->give(fn ($app) => $app->make(ClaudeClientFactory::class)->drafting());
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
        // Keep the library wireframe kits in step with their JSON on every deploy: a deploy runs
        // `migrate --force`, which fires MigrationsEnded (had work) or NoPendingMigrations (none) —
        // binding both re-seeds the kits regardless. NOT bound under tests: the suite runs migrations
        // constantly, and its fixtures seed kits themselves; the handler is unit-tested directly.
        if (! $this->app->runningUnitTests()) {
            Event::listen(
                [MigrationsEnded::class, NoPendingMigrations::class],
                SyncWireframeKitsOnMigrate::class,
            );
        }

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
