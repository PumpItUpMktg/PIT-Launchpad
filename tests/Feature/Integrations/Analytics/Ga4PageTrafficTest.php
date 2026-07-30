<?php

use App\Integrations\Analytics\Ga4PageTraffic;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

/** A connected shared grant with a live token — the platform "one email". */
function ga4TrafficGrant(string $status = 'connected'): void
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

it('is the bound card-facing PageTrafficProvider', function () {
    expect(app(PageTrafficProvider::class))->toBeInstanceOf(Ga4PageTraffic::class);
});

it('is not connected without a grant, a property, or when the grant needs reconnect', function () {
    $provider = app(PageTrafficProvider::class);

    $site = Site::factory()->create(['ga4_property' => 'properties/123']);
    expect($provider->connected($site))->toBeFalse(); // no grant

    ga4TrafficGrant();
    $noProp = Site::factory()->create(['ga4_property' => null]);
    expect($provider->connected($noProp))->toBeFalse(); // no property

    expect($provider->connected($site))->toBeTrue(); // grant + property
});

it('is not connected when the shared grant needs reconnect', function () {
    ga4TrafficGrant(status: 'needs_reconnect');
    $site = Site::factory()->create(['ga4_property' => 'properties/123']);

    expect(app(PageTrafficProvider::class)->connected($site))->toBeFalse();
});

it('returns a page\'s sessions from a pagePath-filtered GA4 report', function () {
    ga4TrafficGrant();
    $site = Site::factory()->create(['ga4_property' => 'properties/123']);

    HttpFacade::fake([
        '*/properties/123:runReport' => HttpFacade::response(['rows' => [
            ['metricValues' => [['value' => '42']]],
        ]]),
    ]);

    expect(app(PageTrafficProvider::class)->sessions($site, '/sump-pump-installation', 28))->toBe(42);

    HttpFacade::assertSent(function ($request) {
        $filter = $request['dimensionFilter']['filter'] ?? [];

        return $filter['fieldName'] === 'pagePath'
            && $filter['stringFilter']['value'] === '/sump-pump-installation'
            && $request['metrics'][0]['name'] === 'sessions';
    });
});

it('accepts a bare (non-prefixed) property id', function () {
    ga4TrafficGrant();
    $site = Site::factory()->create(['ga4_property' => '999']);

    HttpFacade::fake(['*/properties/999:runReport' => HttpFacade::response(['rows' => [['metricValues' => [['value' => '7']]]]])]);

    expect(app(PageTrafficProvider::class)->sessions($site, '/x', 28))->toBe(7);
});

it('returns null (the collecting cell) when GA4 has no row for the page yet, and caches it', function () {
    ga4TrafficGrant();
    $site = Site::factory()->create(['ga4_property' => 'properties/123']);

    $calls = 0;
    HttpFacade::fake(['*/properties/123:runReport' => function () use (&$calls) {
        $calls++;

        return HttpFacade::response(['rows' => []]);
    }]);

    $provider = app(PageTrafficProvider::class);
    expect($provider->sessions($site, '/new-page', 28))->toBeNull()
        ->and($provider->sessions($site, '/new-page', 28))->toBeNull();

    expect($calls)->toBe(1); // no-data result cached, not re-queried
});
