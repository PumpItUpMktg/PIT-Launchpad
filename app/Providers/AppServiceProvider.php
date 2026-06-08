<?php

namespace App\Providers;

use App\Integrations\Census\CensusProvider;
use App\Integrations\Census\MockCensusProvider;
use App\Integrations\Gbp\GbpProvider;
use App\Integrations\Gbp\MockGbpProvider;
use App\Integrations\Voice\MockVoiceSynthesizer;
use App\Integrations\Voice\VoiceSynthesizer;
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

        // §7a onboarding adapters are deferred: default to mocks behind the
        // capability-role interfaces (GBP category seeding, Census enrichment,
        // Claude voice synthesis). Real adapters bind here later.
        $this->app->bind(GbpProvider::class, MockGbpProvider::class);
        $this->app->bind(CensusProvider::class, MockCensusProvider::class);
        $this->app->bind(VoiceSynthesizer::class, MockVoiceSynthesizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
