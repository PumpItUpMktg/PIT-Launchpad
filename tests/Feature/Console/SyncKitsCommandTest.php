<?php

use App\Models\WireframeKit;

it('reports the library wireframe kits, including the standard-page kits', function () {
    $this->artisan('launchpad:sync-kits')
        ->expectsOutputToContain('Synced')
        ->expectsOutputToContain('about-page')
        ->assertSuccessful();

    // the reseed migration already seeds them; the command keeps them in sync
    expect(WireframeKit::whereIn('name', ['home-page', 'about-page', 'why-choose-us-page', 'faq-page'])->count())->toBe(4)
        ->and(WireframeKit::whereIn('name', ['service-page', 'location-page'])->count())->toBe(2);
});

it('is idempotent — re-running does not duplicate', function () {
    $this->artisan('launchpad:sync-kits')->assertSuccessful();
    $count = WireframeKit::query()->whereNull('site_id')->count();

    $this->artisan('launchpad:sync-kits')->assertSuccessful();

    expect(WireframeKit::query()->whereNull('site_id')->count())->toBe($count);
});
