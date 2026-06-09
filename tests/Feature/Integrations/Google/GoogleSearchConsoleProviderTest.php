<?php

use App\Enums\ConnectionProvider;
use App\Integrations\Google\GoogleException;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

/**
 * @param  array<string, mixed>  $credentials
 */
function gscConnection(Site $site, array $credentials, string $status = 'connected'): Connection
{
    return Connection::create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::Google,
        'credentials' => $credentials + [
            'access_token' => 'tok',
            'refresh_token' => 'refresh-1',
            'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
        ],
        'status' => $status,
    ]);
}

it('queries search analytics and parses first-party metric rows', function () {
    $site = Site::factory()->create();
    gscConnection($site, ['gsc_property' => 'sc-domain:example.com']);

    HttpFacade::fake([
        '*/searchAnalytics/query' => HttpFacade::response(['rows' => [
            ['keys' => ['water heater repair'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.035, 'position' => 7.2],
        ]]),
    ]);

    $rows = app(SearchConsoleProvider::class)->searchAnalytics(
        $site,
        new DateTimeImmutable('-28 days'),
        new DateTimeImmutable('-3 days'),
        ['query'],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->keys)->toBe(['water heater repair'])
        ->and($rows[0]->clicks)->toBe(12)
        ->and($rows[0]->impressions)->toBe(340)
        ->and($rows[0]->position)->toBe(7.2);

    HttpFacade::assertSent(fn ($request) => str_contains($request->url(), 'sc-domain%3Aexample.com')
        && $request['dimensions'] === ['query']);
});

it('returns no rows when no GSC property is selected', function () {
    HttpFacade::fake();
    $site = Site::factory()->create();
    gscConnection($site, []); // no gsc_property

    expect(app(SearchConsoleProvider::class)->searchAnalytics($site, new DateTimeImmutable('-7 days'), new DateTimeImmutable))
        ->toBe([]);
    HttpFacade::assertNothingSent();
});

it('surfaces a 403 scope error loudly', function () {
    $site = Site::factory()->create();
    gscConnection($site, ['gsc_property' => 'sc-domain:example.com']);

    HttpFacade::fake([
        '*/searchAnalytics/query' => HttpFacade::response(['error' => ['message' => 'Insufficient Permission']], 403),
    ]);

    expect(fn () => app(SearchConsoleProvider::class)->searchAnalytics($site, new DateTimeImmutable('-7 days'), new DateTimeImmutable))
        ->toThrow(GoogleException::class);
});
