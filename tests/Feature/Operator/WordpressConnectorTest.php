<?php

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Controls\WordpressConnector;
use Illuminate\Support\Facades\Http;

function connections()
{
    return Connection::withoutGlobalScope(SiteScope::class);
}

it('verifies against live WordPress, then stores a clean wp_app_password connection', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response(['id' => 1, 'name' => 'Launchpad Sync'], 200)]);
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

    Http::assertSent(fn ($request) => str_contains($request->url(), '/wp-json/wp/v2/users/me')
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

it('is idempotent on (site, provider) — re-connecting updates, never duplicates', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);
    $site = Site::factory()->create();

    app(WordpressConnector::class)->connect($site->id, ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'firstpass123']);
    app(WordpressConnector::class)->connect($site->id, ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'secondpass456']);

    $rows = connections()->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->credentials['app_password'])->toBe('secondpass456');
});
