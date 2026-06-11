<?php

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

function templatesSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example', 'brand_name' => 'Acme']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);

    return $site;
}

it('lists the site Elementor saved templates', function () {
    Http::fake(['*/wp-json/launchpad/v1/templates' => Http::response([
        'templates' => [
            ['id' => 11, 'title' => 'Service Page', 'slug' => 'service-page', 'type' => 'page', 'modified' => '2026-06-01T10:00:00+00:00', 'preview_url' => 'https://apex.example/?p=11', 'thumbnail' => null],
            ['id' => 12, 'title' => 'Blog Single', 'slug' => 'blog-single', 'type' => 'single-post', 'modified' => '2026-06-02T10:00:00+00:00', 'preview_url' => 'https://apex.example/?p=12', 'thumbnail' => null],
        ],
    ])]);

    // The command renders the inventory as a table and hits the templates
    // endpoint (the row-level data contract is covered in WordpressClientTest).
    $this->artisan('launchpad:site-templates', ['site' => templatesSite()->id])
        ->expectsOutputToContain('Service Page')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/wp-json/launchpad/v1/templates')
        && $request->method() === 'GET');
});

it('reports cleanly when the site has no templates', function () {
    Http::fake(['*/wp-json/launchpad/v1/templates' => Http::response(['templates' => []])]);

    $this->artisan('launchpad:site-templates', ['site' => templatesSite()->id])
        ->expectsOutputToContain('No Elementor saved templates')
        ->assertSuccessful();
});

it('fails when the site has no WordPress connection', function () {
    Http::fake();

    $this->artisan('launchpad:site-templates', ['site' => Site::factory()->create()->id])->assertFailed();
    Http::assertNothingSent();
});

it('fails cleanly when the site is not found', function () {
    $this->artisan('launchpad:site-templates', ['site' => '01NOTASITE0000000000000000'])
        ->expectsOutputToContain('Site not found')
        ->assertFailed();
});
