<?php

namespace App\Providers;

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
