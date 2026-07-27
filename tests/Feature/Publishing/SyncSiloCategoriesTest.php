<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\SyncSiloCategories;
use App\Models\Connection;
use App\Models\Content;
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

test('--repush-content re-pushes the site\'s live silo posts so the corrected category applies', function () {
    PublishHarness::fakeAdapters();
    // A VERIFIED (rotated, non-compromised) WP connection so PostPublisher's gate passes.
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://wp.apex.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'wp_category_id' => null]);
    // A live blog post in the silo — the plugin had lazily categorized it under a "Silo {ulid}" placeholder.
    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'silo_id' => $silo->id, 'wp_post_id' => 55, 'title' => 'Why your sump pump runs constantly',
        'body' => '<p>Real drafted body.</p>',
    ]);

    Http::fake([
        '*/wp-json/launchpad/v1/silo' => Http::response(['wp_category_id' => 9], 200),
        '*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 55, 'status' => 'publish', 'skipped' => false], 200),
    ]);

    $this->artisan('launchpad:sync-silo-categories', ['site' => $site->id, '--repush-content' => true])
        ->expectsOutputToContain('Re-pushed 1 live post')
        ->assertSuccessful();

    // The silo synced AND the live post was re-pushed (its category re-applied).
    expect($silo->fresh()->wp_category_id)->toBe(9);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/wp-json/launchpad/v1/content'));
});
