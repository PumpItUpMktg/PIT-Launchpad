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

it('reports a legacy kit with a null page_type without crashing', function () {
    // a pre-existing library kit whose slot_schema carries no page_type → KitSchema::pageType is null
    WireframeKit::query()->create([
        'site_id' => null,
        'name' => 'legacy-kit',
        'page_type' => null,
        'version' => 1,
        'slot_schema' => ['name' => 'legacy-kit', 'version' => 1, 'slots' => []],
    ]);

    $this->artisan('launchpad:sync-kits')
        ->expectsOutputToContain('page_type=none')
        ->assertSuccessful();
});

it('is idempotent — re-running does not duplicate', function () {
    $this->artisan('launchpad:sync-kits')->assertSuccessful();
    $count = WireframeKit::query()->whereNull('site_id')->count();

    $this->artisan('launchpad:sync-kits')->assertSuccessful();

    expect(WireframeKit::query()->whereNull('site_id')->count())->toBe($count);
});
