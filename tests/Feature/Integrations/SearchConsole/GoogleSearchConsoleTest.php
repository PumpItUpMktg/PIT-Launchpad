<?php

use App\Integrations\SearchConsole\GoogleSearchConsole;
use App\Integrations\SearchConsole\SearchConsoleProvider;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

/** A connected shared grant with a live token — the platform "one email". */
function gscBridgeGrant(string $status = 'connected'): void
{
    GoogleAccount::create([
        'credentials' => [
            'access_token' => 'tok',
            'refresh_token' => 'refresh-1',
            'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
        ],
        'status' => $status,
    ]);
}

it('is the bound card-facing SearchConsoleProvider', function () {
    expect(app(SearchConsoleProvider::class))->toBeInstanceOf(GoogleSearchConsole::class);
});

it('is not connected without a grant, a property, or when the grant needs reconnect', function () {
    $provider = app(SearchConsoleProvider::class);

    // No grant at all.
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);
    expect($provider->connected($site))->toBeFalse();

    // Grant present but no property picked.
    gscBridgeGrant();
    $noProp = Site::factory()->create(['gsc_property' => null, 'domain_url' => 'https://spg.example']);
    expect($provider->connected($noProp))->toBeFalse();

    // Property picked + live grant → connected.
    expect($provider->connected($site))->toBeTrue();
});

it('is not connected when the shared grant needs reconnect', function () {
    gscBridgeGrant(status: 'needs_reconnect');
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    expect(app(SearchConsoleProvider::class)->connected($site))->toBeFalse();
});

it('returns one page\'s impressions/clicks/CTR from an aggregated page-filtered query', function () {
    gscBridgeGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    HttpFacade::fake([
        '*/searchAnalytics/query' => HttpFacade::response(['rows' => [
            ['clicks' => 12, 'impressions' => 300, 'ctr' => 0.04, 'position' => 6.1],
        ]]),
    ]);

    $stats = app(SearchConsoleProvider::class)->pageStats($site, '/sump-pump-installation', 28);

    expect($stats)->not->toBeNull()
        ->and($stats->impressions)->toBe(300)
        ->and($stats->clicks)->toBe(12)
        ->and($stats->days)->toBe(28)
        ->and($stats->ctr())->toBe(4.0);

    // The query filters to the page's full URL (domain + path), aggregated (no dimensions).
    HttpFacade::assertSent(function ($request) {
        $group = $request['dimensionFilterGroups'][0]['filters'][0] ?? [];

        return str_contains($request->url(), 'sc-domain%3Aspg.example')
            && ($group['dimension'] ?? null) === 'page'
            && ($group['expression'] ?? null) === 'https://spg.example/sump-pump-installation'
            && ! isset($request['dimensions']);
    });
});

it('returns null (the collecting cell) when GSC has no row for the page yet, and caches it', function () {
    gscBridgeGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    $calls = 0;
    HttpFacade::fake(['*/searchAnalytics/query' => function () use (&$calls) {
        $calls++;

        return HttpFacade::response(['rows' => []]);
    }]);

    $provider = app(SearchConsoleProvider::class);
    expect($provider->pageStats($site, '/new-page', 28))->toBeNull()
        ->and($provider->pageStats($site, '/new-page', 28))->toBeNull();

    // Cached — the no-data result is not re-queried on the second render.
    expect($calls)->toBe(1);
});

it('returns null when a page has no domain to match against', function () {
    gscBridgeGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => null]);
    HttpFacade::fake();

    expect(app(SearchConsoleProvider::class)->pageStats($site, '/x', 28))->toBeNull();
    HttpFacade::assertNothingSent();
});
