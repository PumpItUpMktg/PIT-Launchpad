<?php

use App\Models\Redirect;
use App\Models\Silo;
use App\Publishing\PublishRedirectsService;
use App\Publishing\PublishSiloService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

test('publishing a silo pushes the structure and stores the returned wp_category_id', function () {
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'wp_category_id' => null]);

    Http::fake([
        '*/wp-json/launchpad/v1/silo' => Http::response(['silo_id' => $silo->id, 'wp_category_id' => 34], 200),
    ]);

    app(PublishSiloService::class)->publish($silo);

    expect($silo->fresh()->wp_category_id)->toBe(34);

    Http::assertSent(fn ($r) => $r['silo_id'] === $silo->id
        && $r['name'] === 'Plumbing'
        && array_key_exists('parent_silo_id', $r->data()));
});

test('publishing redirects sends the active redirects in contract shape', function () {
    $site = PublishHarness::site();
    Redirect::factory()->create([
        'site_id' => $site->id, 'from_url' => '/old-page', 'to_url' => '/new-page', 'code' => 301, 'status' => 'active',
    ]);

    Http::fake(['*/wp-json/launchpad/v1/redirects' => Http::response(['count' => 1], 200)]);

    app(PublishRedirectsService::class)->publish($site);

    Http::assertSent(function ($r) {
        return str_contains($r->url(), '/wp-json/launchpad/v1/redirects')
            && $r['redirects'][0]['from_url'] === '/old-page'
            && $r['redirects'][0]['to_url'] === '/new-page'
            && $r['redirects'][0]['code'] === 301;
    });
});
