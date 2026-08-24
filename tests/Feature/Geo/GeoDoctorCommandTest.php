<?php

use App\Integrations\AiSearch\AiEngineProvider;
use App\Models\GeoPrompt;
use App\Models\Site;

it('passes the GEO smoke check when the schema + bindings are healthy', function () {
    $site = Site::factory()->create();
    GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'best sump pump repair', 'active' => true]);

    $this->artisan('sandhog:geo-doctor')
        ->expectsOutputToContain('GEO smoke check passed')
        ->assertSuccessful();
});

it('reports the engine as not configured when no API key is set', function () {
    config(['services.anthropic.key' => null]);

    // Re-bind so the engine picks up the cleared key.
    app()->forgetInstance(AiEngineProvider::class);

    $this->artisan('sandhog:geo-doctor')
        ->expectsOutputToContain('not configured')
        ->assertSuccessful();
});
