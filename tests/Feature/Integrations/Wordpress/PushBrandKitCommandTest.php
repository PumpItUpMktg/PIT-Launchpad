<?php

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Site;
use App\Models\SiteBranding;
use Illuminate\Support\Facades\Http;

function brandSite(array $branding = ['palette' => ['primary' => '#0F62FE']]): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example', 'brand_name' => 'Acme']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);
    SiteBranding::withoutGlobalScopes()->create(array_merge(['site_id' => $site->id], $branding));

    return $site;
}

it('pushes the brand kit to /brand-kit and reports what was applied', function () {
    Http::fake(['*/wp-json/launchpad/v1/brand-kit' => Http::response(
        ['updated' => true, 'kit_id' => 7, 'colors_set' => 1, 'fonts_set' => 0],
    )]);

    $this->artisan('launchpad:push-brand-kit', ['site' => brandSite()->id])
        ->expectsOutputToContain('Brand applied to Global Kit #7')
        ->assertSuccessful();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/brand-kit')
        && $r->method() === 'POST'
        && $r['colors']['primary'] === '#0F62FE');
});

it('warns and sends nothing when the site has no brand captured', function () {
    Http::fake();
    $site = Site::factory()->create();
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);

    $this->artisan('launchpad:push-brand-kit', ['site' => $site->id])
        ->expectsOutputToContain('No brand captured')
        ->assertSuccessful();
    Http::assertNothingSent();
});

it('surfaces a soft failure when the site has no active Global Kit', function () {
    Http::fake(['*/wp-json/launchpad/v1/brand-kit' => Http::response(
        ['updated' => false, 'kit_id' => 0, 'colors_set' => 0, 'fonts_set' => 0, 'error' => 'No active Elementor Global Kit; brand not applied.'],
        422,
    )]);

    // A 422 is non-2xx → the client throws → the command reports failure cleanly.
    $this->artisan('launchpad:push-brand-kit', ['site' => brandSite()->id])
        ->assertFailed();
});

it('fails cleanly when the site is not found', function () {
    $this->artisan('launchpad:push-brand-kit', ['site' => '01NOTASITE0000000000000000'])
        ->expectsOutputToContain('Site not found')
        ->assertFailed();
});
