<?php

use App\Enums\ConnectionStatus;
use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Integrations\Google\GoogleToken;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http as HttpFacade;

function googleService(): GoogleConnectionService
{
    return app(GoogleConnectionService::class);
}

/**
 * @param  array<string, mixed>  $credentials
 */
function googleAccount(array $credentials, string $status = 'connected'): GoogleAccount
{
    return GoogleAccount::create([
        'credentials' => $credentials,
        'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
        'status' => $status,
    ]);
}

it('stores tokens encrypted at rest on the shared grant with a connected status', function () {
    $account = googleService()->store(new GoogleToken(
        accessToken: 'access-secret-1',
        refreshToken: 'refresh-secret-1',
        expiresAt: new DateTimeImmutable('+1 hour'),
        scopes: ['https://www.googleapis.com/auth/webmasters.readonly'],
    ));

    expect($account->status)->toBe(ConnectionStatus::Connected->value)
        ->and($account->credentials['access_token'])->toBe('access-secret-1');

    // Encrypted at rest — the raw column must not contain the plaintext token (§9).
    $raw = (string) DB::table('google_accounts')->where('id', $account->id)->value('credentials');
    expect($raw)->not->toContain('access-secret-1')
        ->and($raw)->not->toContain('refresh-secret-1');
});

it('is a singleton — a second store updates the one shared grant', function () {
    googleService()->store(new GoogleToken('a1', 'r1', new DateTimeImmutable('+1 hour'), []));
    googleService()->store(new GoogleToken('a2', 'r2', new DateTimeImmutable('+1 hour'), []));

    expect(GoogleAccount::count())->toBe(1)
        ->and(GoogleAccount::current()->credentials['access_token'])->toBe('a2');
});

it('records which GSC + GA4 property a site reads, off the shared grant', function () {
    $site = Site::factory()->create();

    googleService()->setSiteProperties($site, 'sc-domain:example.com', 'properties/123');

    expect($site->fresh()->gsc_property)->toBe('sc-domain:example.com')
        ->and($site->fresh()->ga4_property)->toBe('properties/123');

    // Empty string clears the pointer (the picker's "not connected" choice).
    googleService()->setSiteProperties($site, '', '');
    expect($site->fresh()->gsc_property)->toBeNull()
        ->and($site->fresh()->ga4_property)->toBeNull();
});

it('returns the stored access token while it is still valid', function () {
    HttpFacade::fake(); // no refresh expected
    $account = googleAccount([
        'access_token' => 'still-good',
        'refresh_token' => 'refresh-1',
        'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
    ]);

    expect(googleService()->accessToken($account))->toBe('still-good');
    HttpFacade::assertNothingSent();
});

it('refreshes and persists a new access token when the stored one is expired', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
    ]);
    $account = googleAccount([
        'access_token' => 'stale',
        'refresh_token' => 'refresh-1',
        'expires_at' => (new DateTimeImmutable('-5 minutes'))->format(DATE_ATOM),
    ]);

    expect(googleService()->accessToken($account))->toBe('fresh-token');

    $account->refresh();
    expect($account->credentials['access_token'])->toBe('fresh-token');
});

it('refreshes once and retries on a 401, then succeeds', function () {
    $account = googleAccount([
        'access_token' => 'tok-1',
        'refresh_token' => 'refresh-1',
        'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
    ]);

    $apiCalls = 0;
    HttpFacade::fake([
        '*/token' => HttpFacade::response(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'https://example.test/resource' => function () use (&$apiCalls) {
            $apiCalls++;

            return $apiCalls === 1
                ? HttpFacade::response(['error' => ['message' => 'Invalid Credentials']], 401)
                : HttpFacade::response(['ok' => true]);
        },
    ]);

    $json = googleService()->request($account, 'get', 'https://example.test/resource');

    expect($json)->toBe(['ok' => true]);
    expect($apiCalls)->toBe(2); // 401 then retry
});

it('marks the shared grant needs-reconnect when the refresh token is dead', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response(['error' => 'invalid_grant', 'error_description' => 'revoked'], 400),
    ]);
    $account = googleAccount([
        'access_token' => 'stale',
        'refresh_token' => 'refresh-dead',
        'expires_at' => (new DateTimeImmutable('-5 minutes'))->format(DATE_ATOM),
    ]);

    try {
        googleService()->accessToken($account);
        $this->fail('expected GoogleException');
    } catch (GoogleException $e) {
        expect($e->needsReconnect)->toBeTrue();
    }

    expect($account->refresh()->status)->toBe(ConnectionStatus::NeedsReconnect->value);
});

it('lists GSC sites and GA4 properties for property selection', function () {
    $account = googleAccount([
        'access_token' => 'tok',
        'refresh_token' => 'refresh-1',
        'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
    ]);

    HttpFacade::fake([
        '*/webmasters/v3/sites' => HttpFacade::response(['siteEntry' => [
            ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
        ]]),
        '*/accountSummaries' => HttpFacade::response(['accountSummaries' => [
            ['propertySummaries' => [['property' => 'properties/123', 'displayName' => 'Example GA4']]],
        ]]),
    ]);

    expect(googleService()->listGscSites($account))->toBe(['sc-domain:example.com']);
    expect(googleService()->listGa4Properties($account))->toBe([
        ['property' => 'properties/123', 'displayName' => 'Example GA4'],
    ]);
});
