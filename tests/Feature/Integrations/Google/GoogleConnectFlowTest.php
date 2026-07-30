<?php

use App\Enums\ConnectionStatus;
use App\Models\GoogleAccount;
use Illuminate\Support\Facades\Http as HttpFacade;

it('redirects to Google consent and stashes the OAuth state', function () {
    $response = $this->get('/connections/google/authorize');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com/o/oauth2/v2/auth')
        ->and($response->headers->get('Location'))->toContain('access_type=offline');

    $stashed = session('google_oauth');
    expect($stashed['state'])->not->toBeEmpty();
});

it('completes the callback: exchanges the code, vaults tokens on the shared grant, verifies properties', function () {
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
        ->withSession(['google_oauth' => ['state' => 'state-xyz']])
        ->get('/oauth/google/callback?state=state-xyz&code=auth-code');

    $response->assertRedirect('/');
    $response->assertSessionHas('google_connect_ok');

    // One shared grant, tokens vaulted, connected. Properties are picked per-site later — NOT here.
    expect(GoogleAccount::count())->toBe(1);
    $account = GoogleAccount::current();
    expect($account->status)->toBe(ConnectionStatus::Connected->value)
        ->and($account->credentials['access_token'])->toBe('access-1')
        ->and($account->credentials)->not->toHaveKey('gsc_property')
        ->and($account->credentials)->not->toHaveKey('ga4_property');
});

it('rejects a callback whose state does not match the session', function () {
    HttpFacade::fake();

    $response = $this
        ->withSession(['google_oauth' => ['state' => 'real-state']])
        ->get('/oauth/google/callback?state=forged&code=auth-code');

    $response->assertRedirect('/');
    $response->assertSessionHas('google_connect_error');
    expect(GoogleAccount::count())->toBe(0);
    HttpFacade::assertNothingSent();
});
