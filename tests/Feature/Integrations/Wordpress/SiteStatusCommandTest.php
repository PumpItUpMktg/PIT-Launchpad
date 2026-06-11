<?php

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

function statusSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example', 'brand_name' => 'Acme']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);

    return $site;
}

it('reads and prints the WordPress environment status', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response([
        'wp_version' => '6.7.1',
        'php_version' => '8.2.0',
        'elementor_version' => '4.1.3',
        'elementor_pro_version' => '4.1.1',
        'active_theme' => ['name' => 'Hello Elementor', 'version' => '3.1'],
        'companion_version' => '0.3.0',
    ])]);

    $this->artisan('launchpad:site-status', ['site' => statusSite()->id])
        ->expectsOutputToContain('6.7.1')
        ->expectsOutputToContain('4.1.3')          // Elementor
        ->expectsOutputToContain('4.1.1')          // Elementor Pro
        ->expectsOutputToContain('Hello Elementor')
        ->expectsOutputToContain('0.3.0')          // companion
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/wp-json/launchpad/v1/status')
        && $request->method() === 'GET');
});

it('fails when the site has no WordPress connection', function () {
    Http::fake();

    $this->artisan('launchpad:site-status', ['site' => Site::factory()->create()->id])->assertFailed();
    Http::assertNothingSent();
});

it('fails cleanly when the site is not found', function () {
    $this->artisan('launchpad:site-status', ['site' => '01NOTASITE0000000000000000'])
        ->expectsOutputToContain('Site not found')
        ->assertFailed();
});
