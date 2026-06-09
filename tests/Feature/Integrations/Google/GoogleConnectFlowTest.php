<?php

use App\Enums\ConnectionProvider;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

it('redirects to Google consent and stashes the OAuth state', function () {
    $site = Site::factory()->create();

    $response = $this->get("/connections/google/{$site->id}/authorize");

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com/o/oauth2/v2/auth')
        ->and($response->headers->get('Location'))->toContain('access_type=offline');

    $stashed = session('google_oauth');
    expect($stashed['site_id'])->toBe($site->id)
        ->and($stashed['state'])->not->toBeEmpty();
});

it('completes the callback: exchanges the code, vaults tokens, auto-selects properties', function () {
    $site = Site::factory()->create();

    HttpFacade::fake([
        '*/token' => HttpFacade::response([
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        ]),
        '*/webmasters/v3/sites' => HttpFacade::response(['siteEntry' => [['siteUrl' => 'sc-domain:example.com']]]),
        '*/accountSummaries' => HttpFacade::response(['accountSummaries' => [
            ['propertySummaries' => [['property' => 'properties/123', 'displayName' => 'GA4']]],
        ]]),
    ]);

    $response = $this
        ->withSession(['google_oauth' => ['state' => 'state-xyz', 'site_id' => $site->id]])
        ->get('/oauth/google/callback?state=state-xyz&code=auth-code');

    $response->assertRedirect('/');
    $response->assertSessionHas('google_connect_ok');

    $connection = Connection::where('site_id', $site->id)->where('provider', ConnectionProvider::Google->value)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->status)->toBe(ConnectionStatus::Connected->value)
        ->and($connection->credentials['gsc_property'])->toBe('sc-domain:example.com')
        ->and($connection->credentials['ga4_property'])->toBe('properties/123');
});

it('rejects a callback whose state does not match the session', function () {
    $site = Site::factory()->create();
    HttpFacade::fake();

    $response = $this
        ->withSession(['google_oauth' => ['state' => 'real-state', 'site_id' => $site->id]])
        ->get('/oauth/google/callback?state=forged&code=auth-code');

    $response->assertRedirect('/');
    $response->assertSessionHas('google_connect_error');
    expect(Connection::where('provider', ConnectionProvider::Google->value)->count())->toBe(0);
    HttpFacade::assertNothingSent();
});
