<?php

use App\Integrations\Google\GoogleException;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

/** A connected shared grant with a live token — the platform "one email". */
function gscGrant(): void
{
    GoogleAccount::create([
        'credentials' => [
            'access_token' => 'tok',
            'refresh_token' => 'refresh-1',
            'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
        ],
        'status' => 'connected',
    ]);
}

it('queries search analytics and parses first-party metric rows', function () {
    gscGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:example.com']);

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

it('returns no rows when the site has no GSC property selected', function () {
    HttpFacade::fake();
    gscGrant();
    $site = Site::factory()->create(['gsc_property' => null]);

    expect(app(SearchConsoleProvider::class)->searchAnalytics($site, new DateTimeImmutable('-7 days'), new DateTimeImmutable))
        ->toBe([]);
    HttpFacade::assertNothingSent();
});

it('returns no rows when Google has never been connected', function () {
    HttpFacade::fake();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:example.com']);

    expect(app(SearchConsoleProvider::class)->searchAnalytics($site, new DateTimeImmutable('-7 days'), new DateTimeImmutable))
        ->toBe([]);
    HttpFacade::assertNothingSent();
});

it('surfaces a 403 scope error loudly', function () {
    gscGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:example.com']);

    HttpFacade::fake([
        '*/searchAnalytics/query' => HttpFacade::response(['error' => ['message' => 'Insufficient Permission']], 403),
    ]);

    expect(fn () => app(SearchConsoleProvider::class)->searchAnalytics($site, new DateTimeImmutable('-7 days'), new DateTimeImmutable))
        ->toThrow(GoogleException::class);
});
