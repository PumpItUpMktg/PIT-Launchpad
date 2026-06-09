<?php

use App\Enums\ConnectionProvider;
use App\Enums\ConnectionStatus;
use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Integrations\Google\GoogleToken;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http as HttpFacade;

function googleService(): GoogleConnectionService
{
    return app(GoogleConnectionService::class);
}

function googleConnection(Site $site, array $credentials, string $status = 'connected'): Connection
{
    return Connection::create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::Google,
        'credentials' => $credentials,
        'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
        'status' => $status,
    ]);
}

it('stores tokens encrypted at rest with a connected status', function () {
    $site = Site::factory()->create();

    $connection = googleService()->store($site, new GoogleToken(
        accessToken: 'access-secret-1',
        refreshToken: 'refresh-secret-1',
        expiresAt: new DateTimeImmutable('+1 hour'),
        scopes: ['https://www.googleapis.com/auth/webmasters.readonly'],
    ));

    expect($connection->provider)->toBe(ConnectionProvider::Google)
        ->and($connection->status)->toBe(ConnectionStatus::Connected->value)
        ->and($connection->credentials['access_token'])->toBe('access-secret-1');

    // Encrypted at rest — the raw column must not contain the plaintext token (§9).
    $raw = (string) DB::table('connections')->where('id', $connection->id)->value('credentials');
    expect($raw)->not->toContain('access-secret-1')
        ->and($raw)->not->toContain('refresh-secret-1');
});

it('returns the stored access token while it is still valid', function () {
    HttpFacade::fake(); // no refresh expected
    $site = Site::factory()->create();
    $connection = googleConnection($site, [
        'access_token' => 'still-good',
        'refresh_token' => 'refresh-1',
        'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
    ]);

    expect(googleService()->accessToken($connection))->toBe('still-good');
    HttpFacade::assertNothingSent();
});

it('refreshes and persists a new access token when the stored one is expired', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
    ]);
    $site = Site::factory()->create();
    $connection = googleConnection($site, [
        'access_token' => 'stale',
        'refresh_token' => 'refresh-1',
        'expires_at' => (new DateTimeImmutable('-5 minutes'))->format(DATE_ATOM),
    ]);

    expect(googleService()->accessToken($connection))->toBe('fresh-token');

    $connection->refresh();
    expect($connection->credentials['access_token'])->toBe('fresh-token');
});

it('refreshes once and retries on a 401, then succeeds', function () {
    $site = Site::factory()->create();
    $connection = googleConnection($site, [
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

    $json = googleService()->request($connection, 'get', 'https://example.test/resource');

    expect($json)->toBe(['ok' => true]);
    expect($apiCalls)->toBe(2); // 401 then retry
});

it('marks the connection needs-reconnect when the refresh token is dead', function () {
    HttpFacade::fake([
        '*/token' => HttpFacade::response(['error' => 'invalid_grant', 'error_description' => 'revoked'], 400),
    ]);
    $site = Site::factory()->create();
    $connection = googleConnection($site, [
        'access_token' => 'stale',
        'refresh_token' => 'refresh-dead',
        'expires_at' => (new DateTimeImmutable('-5 minutes'))->format(DATE_ATOM),
    ]);

    try {
        googleService()->accessToken($connection);
        $this->fail('expected GoogleException');
    } catch (GoogleException $e) {
        expect($e->needsReconnect)->toBeTrue();
    }

    expect($connection->refresh()->status)->toBe(ConnectionStatus::NeedsReconnect->value);
});

it('lists GSC sites and GA4 properties for property selection', function () {
    $site = Site::factory()->create();
    $connection = googleConnection($site, [
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

    expect(googleService()->listGscSites($connection))->toBe(['sc-domain:example.com']);
    expect(googleService()->listGa4Properties($connection))->toBe([
        ['property' => 'properties/123', 'displayName' => 'Example GA4'],
    ]);
});
