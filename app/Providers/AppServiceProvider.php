<?php

namespace App\Providers;

use App\Integrations\Claude\AnthropicClaudeClient;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
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

        // Vendors are deferred (§5): default to mock providers behind the
        // capability-role interfaces. Real adapters bind here later with no
        // change to scoring/beatability/tracking.
        $this->app->singleton(SerpProvider::class, MockSerpProvider::class);
        $this->app->singleton(LocalGridProvider::class, MockLocalGridProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
