<?php

use App\Integrations\Google\GoogleException;
use App\Integrations\Google\GoogleOAuthClient;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function oauthClient(): GoogleOAuthClient
{
    return new GoogleOAuthClient(
        app(Http::class),
        'client-123',
        'secret-xyz',
        'https://app.test/oauth/google/callback',
        'https://accounts.google.com/o/oauth2/v2/auth',
        'https://oauth2.googleapis.com/token',
        30,
    );
}

it('builds a consent URL with offline access, forced consent and read-only scopes', function () {
    $url = oauthClient()->authorizationUrl('state-abc');

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');
    $query = urldecode(parse_url($url, PHP_URL_QUERY));
    expect($query)->toContain('client_id=client-123')
        ->and($query)->toContain('response_type=code')
        ->and($query)->toContain('access_type=offline')
        ->and($query)->toContain('prompt=consent')
        ->and($query)->toContain('state=state-abc')
        ->and($query)->toContain('webmasters.readonly')
        ->and($query)->toContain('analytics.readonly');
});

it('exchanges an auth code for access + refresh tokens', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
            'token_type' => 'Bearer',
        ]),
    ]);

    $token = oauthClient()->exchangeCode('the-code');

    expect($token->accessToken)->toBe('access-1')
        ->and($token->refreshToken)->toBe('refresh-1')
        ->and($token->scopes)->toContain('https://www.googleapis.com/auth/analytics.readonly')
        ->and($token->expiresAt->getTimestamp())->toBeGreaterThan(time());

    HttpFacade::assertSent(fn ($request) => $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && $request['client_secret'] === 'secret-xyz');
});

it('refreshes an access token and keeps the existing refresh token when omitted', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response([
            'access_token' => 'access-2',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        ]),
    ]);

    $token = oauthClient()->refresh('refresh-1');

    expect($token->accessToken)->toBe('access-2')
        ->and($token->refreshToken)->toBe('refresh-1'); // carried over
});

it('flags a dead refresh token (invalid_grant) as needs-reconnect', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response(['error' => 'invalid_grant', 'error_description' => 'Token revoked'], 400),
    ]);

    try {
        oauthClient()->refresh('refresh-dead');
        $this->fail('expected GoogleException');
    } catch (GoogleException $e) {
        expect($e->needsReconnect)->toBeTrue()
            ->and($e->fatal)->toBeTrue();
    }
});
