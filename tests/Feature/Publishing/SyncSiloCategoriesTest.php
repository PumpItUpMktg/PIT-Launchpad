<?php

use App\Jobs\SyncSiloCategories;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\PublishSiloService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

test('the job pushes the site\'s silos to WP categories when a connection is wired', function () {
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'wp_category_id' => null]);

    Http::fake(['*/wp-json/launchpad/v1/silo' => Http::response(['silo_id' => $silo->id, 'wp_category_id' => 7], 200)]);

    (new SyncSiloCategories($site->id))->handle(app(PublishSiloService::class));

    expect($silo->fresh()->wp_category_id)->toBe(7);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/wp-json/launchpad/v1/silo'));
});

test('the job is a no-op (no WP call) until a connection is wired — the launch is the backstop', function () {
    $site = Site::factory()->create(); // no WP connection
    Silo::factory()->create(['site_id' => $site->id, 'wp_category_id' => null]);

    Http::fake();

    (new SyncSiloCategories($site->id))->handle(app(PublishSiloService::class));

    Http::assertNothingSent();
});

test('the backfill command pushes a finalized tenant\'s silos on demand', function () {
    $site = PublishHarness::site(['base_url' => 'https://wp.apex.example']);
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'wp_category_id' => null]);

    Http::fake(['*/wp-json/launchpad/v1/silo' => Http::response(['wp_category_id' => 12], 200)]);

    $this->artisan('launchpad:sync-silo-categories', ['site' => $site->id])
        ->expectsOutputToContain('Pushed 1 silo')
        ->assertSuccessful();
});
