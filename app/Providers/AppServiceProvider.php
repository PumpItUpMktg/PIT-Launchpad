<?php

namespace App\Providers;

use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\RelevanceScorer;
use App\Enums\AuditAction;
use App\Integrations\Census\CensusProvider;
use App\Integrations\Census\MockCensusProvider;
use App\Integrations\Claude\AnthropicClaudeClient;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\MockConversionProvider;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalHttpClient;
use App\Integrations\Gbp\GbpProvider;
use App\Integrations\Gbp\MockGbpProvider;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\News\MockNewsProvider;
use App\Integrations\News\MockOnDemandSourcePull;
use App\Integrations\News\NewsProvider;
use App\Integrations\News\OnDemandSourcePull;
use App\Integrations\Serp\MockSerpProvider;
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

        // Vendors are deferred: default to mock adapters behind the
        // capability-role interfaces. Real adapters bind here later with no
        // change to scoring/beatability/tracking (§5) or the candidate funnel (§6a).
        $this->app->singleton(SerpProvider::class, MockSerpProvider::class);
        $this->app->singleton(LocalGridProvider::class, MockLocalGridProvider::class);
        $this->app->singleton(NewsProvider::class, MockNewsProvider::class);
        $this->app->singleton(EmbeddingProvider::class, MockEmbeddingProvider::class);
        $this->app->singleton(OnDemandSourcePull::class, MockOnDemandSourcePull::class);

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
