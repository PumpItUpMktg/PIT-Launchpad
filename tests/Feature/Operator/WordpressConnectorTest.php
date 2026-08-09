<?php

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Controls\WordpressConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function connections()
{
    return Connection::withoutGlobalScope(SiteScope::class);
}

it('verifies against live WordPress, then stores a clean wp_app_password connection', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response(['id' => 1, 'name' => 'Launchpad Sync'], 200)]);
    $site = Site::factory()->create();

    $connection = app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://eric-site.com/',
        'username' => ' launchpad-sync ',
        'app_password' => 'abcd efgh ijkl mnop',
    ]);

    expect($connection->provider)->toBe(ConnectionProvider::WpAppPassword)
        ->and($connection->credentials['base_url'])->toBe('https://eric-site.com')   // trailing slash trimmed
        ->and($connection->credentials['username'])->toBe('launchpad-sync')          // trimmed
        ->and($connection->credentials['app_password'])->toBe('abcdefghijklmnop')    // spaces stripped
        ->and($connection->compromised)->toBeFalse()
        ->and($connection->needsRotation())->toBeFalse();                            // passes the §9 launch gate

    Http::assertSent(fn ($request) => str_contains($request->url(), '/wp-json/launchpad/v1/status')
        && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Basic '));
});

it('refuses to store a credential that fails verification', function () {
    Http::fake(['*' => Http::response('', 401)]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://eric-site.com',
        'username' => 'launchpad-sync',
        'app_password' => 'wrongpass1234',
    ]))->toThrow(WordpressException::class);

    expect(connections()->count())->toBe(0);
});

it('stores the POST-safe canonical base URL when the entered URL redirects', function () {
    // pingResult follows the redirect → 200; canonicalBaseUrl (no-follow) then sees the 301.
    Http::fakeSequence('*/wp-json/launchpad/v1/status')
        ->push(['ok' => true], 200)
        ->push('', 301, ['Location' => 'https://www.eric-site.com/wp-json/launchpad/v1/status']);
    $site = Site::factory()->create();

    $connection = app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://eric-site.com', 'username' => 'launchpad-sync', 'app_password' => 'goodpass12345',
    ]);

    expect($connection->credentials['base_url'])->toBe('https://www.eric-site.com'); // canonical stored, not the entered non-www
});

it('keeps the entered base URL when there is no redirect', function () {
    // Both the ping and the no-follow probe return 200 → no redirect → base unchanged.
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response(['ok' => true], 200)]);
    $site = Site::factory()->create();

    $connection = app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://eric-site.com', 'username' => 'launchpad-sync', 'app_password' => 'goodpass12345',
    ]);

    expect($connection->credentials['base_url'])->toBe('https://eric-site.com');
});

it('explains a 404 as the plugin not answering at that URL', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response('', 404)]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'whatever12345',
    ]))->toThrow(WordpressException::class, "companion plugin isn't answering");

    expect(connections()->count())->toBe(0);
});

it('a 401 with the Authorization header STRIPPED in transit reads as an edge/host problem, not a bad password', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check*' => Http::response(['authorization_received' => false, 'scheme' => 'none'], 200),
        '*/wp-json/launchpad/v1/status' => Http::response(['data' => ['status' => 401]], 401),
    ]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'anything12345',
    ]))->toThrow(WordpressException::class, 'STRIPPED in transit');
});

it('a 401 where the header arrived reads as a rejected Application Password (and names the user)', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check*' => Http::response([
            'authorization_received' => true, 'scheme' => 'basic', 'username' => 'launchpad-sync',
            'application_passwords_available' => true,
        ], 200),
        '*/wp-json/launchpad/v1/status' => Http::response('', 401),
    ]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'badpass123456',
    ]))->toThrow(WordpressException::class, 'Application Password was rejected');

    try {
        app(WordpressConnector::class)->connect($site->id, [
            'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'badpass123456',
        ]);
    } catch (WordpressException $e) {
        expect($e->getMessage())->toContain('launchpad-sync');
    }
});

it('a 401 with Application Passwords disabled points at HTTPS / a security plugin', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check*' => Http::response([
            'authorization_received' => true, 'application_passwords_available' => false, 'is_ssl' => false,
        ], 200),
        '*/wp-json/launchpad/v1/status' => Http::response('', 401),
    ]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'goodpass12345',
    ]))->toThrow(WordpressException::class, 'Application Passwords are DISABLED');
});

it('a 401 with no diagnostic (older companion plugin) falls back and suggests updating', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check*' => Http::response('', 404), // pre-0.9.32 plugin: route absent
        '*/wp-json/launchpad/v1/status' => Http::response('', 401),
    ]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'whatever12345',
    ]))->toThrow(WordpressException::class, 'Update the companion plugin');
});

it('explains a 403 (authenticated, forbidden) as a missing Launchpad capability', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response(['code' => 'rest_forbidden', 'data' => ['status' => 403]], 403)]);
    $site = Site::factory()->create();

    expect(fn () => app(WordpressConnector::class)->connect($site->id, [
        'base_url' => 'https://sumppumpgurus.com', 'username' => 'launchpad-sync', 'app_password' => 'goodpass12345',
    ]))->toThrow(WordpressException::class, 'lp_manage_content capability');
});

it('verify() pings without persisting — true on a 2xx, no connection written', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response(['id' => 1], 200)]);

    $ok = app(WordpressConnector::class)->verify([
        'base_url' => 'https://eric-site.com/',
        'username' => 'launchpad-sync',
        'app_password' => 'abcd efgh ijkl mnop',
    ]);

    expect($ok)->toBeTrue()
        ->and(connections()->count())->toBe(0); // verify never writes
});

it('verify() returns false on a failed auth — and on an unreachable host', function () {
    Http::fake(['*' => Http::response('', 401)]);
    expect(app(WordpressConnector::class)->verify([
        'base_url' => 'https://eric-site.com', 'username' => 'u', 'app_password' => 'wrongpass1234',
    ]))->toBeFalse();

    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
    expect(app(WordpressConnector::class)->verify([
        'base_url' => 'https://nope.invalid', 'username' => 'u', 'app_password' => 'whatever12345',
    ]))->toBeFalse();
});

it('is idempotent on (site, provider) — re-connecting updates, never duplicates', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);
    $site = Site::factory()->create();

    app(WordpressConnector::class)->connect($site->id, ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'firstpass123']);
    app(WordpressConnector::class)->connect($site->id, ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'secondpass456']);

    $rows = connections()->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->credentials['app_password'])->toBe('secondpass456');
});

it('reverify() flags a stored connection compromised when the credential is rejected (report fix 3B)', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response('', 401)]);
    $site = Site::factory()->create();
    $conn = Connection::factory()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'deadpass12345'],
        'compromised' => false, 'compromised_reason' => null, 'last_rotated_at' => now(),
    ]);

    $ok = app(WordpressConnector::class)->reverify($conn);

    // The green chip flips red the moment the stored credential is revoked (it 401s the push endpoint).
    expect($ok)->toBeFalse()
        ->and($conn->fresh()->compromised)->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/wp-json/launchpad/v1/status'));
});

it('reverify() leaves a still-valid stored connection clean', function () {
    Http::fake(['*/wp-json/launchpad/v1/status' => Http::response(['ok' => true], 200)]);
    $site = Site::factory()->create();
    $conn = Connection::factory()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'goodpass12345'],
        'compromised' => false, 'compromised_reason' => null, 'last_rotated_at' => now(),
    ]);

    expect(app(WordpressConnector::class)->reverify($conn))->toBeTrue()
        ->and($conn->fresh()->compromised)->toBeFalse();
});
