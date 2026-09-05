<?php

use App\Enums\IndexCoverageState;
use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\UrlInspection\GoogleIndexInspector;
use App\Integrations\UrlInspection\IndexInspector;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http as HttpFacade;

function inspectGrant(string $status = 'connected'): void
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

function inspectResponse(string $coverage, string $verdict = 'PASS', ?string $googleCanonical = null): array
{
    return ['inspectionResult' => ['indexStatusResult' => array_filter([
        'verdict' => $verdict,
        'coverageState' => $coverage,
        'googleCanonical' => $googleCanonical,
        'lastCrawlTime' => '2026-08-01T12:00:00Z',
    ])]];
}

it('is the bound IndexInspector', function () {
    expect(app(IndexInspector::class))->toBeInstanceOf(GoogleIndexInspector::class);
});

it('is not connected without a grant or a property', function () {
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);
    expect(app(IndexInspector::class)->connected($site))->toBeFalse(); // no grant

    inspectGrant();
    $noProp = Site::factory()->create(['gsc_property' => null]);
    expect(app(IndexInspector::class)->connected($noProp))->toBeFalse()
        ->and(app(IndexInspector::class)->connected($site))->toBeTrue();
});

it('inspects a URL, maps coverageState, and caches the result', function () {
    inspectGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    HttpFacade::fake([
        '*/urlInspection/index:inspect' => HttpFacade::response(inspectResponse('Submitted and indexed', 'PASS', 'https://spg.example/hoboken-nj')),
    ]);

    $status = app(IndexInspector::class)->inspect($site, 'https://spg.example/hoboken-nj');

    expect($status)->not->toBeNull()
        ->and($status->state)->toBe(IndexCoverageState::Indexed)
        ->and($status->indexed())->toBeTrue()
        ->and($status->canonicalMismatch())->toBeFalse();

    // Second call is served from cache — no second HTTP request.
    app(IndexInspector::class)->inspect($site, 'https://spg.example/hoboken-nj');
    HttpFacade::assertSentCount(1);
});

it('caches a PASS verdict for 14 days and a non-PASS verdict for 3 days (tiered TTL)', function () {
    inspectGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    $ttls = [];
    $cache = Mockery::mock(Repository::class);
    $cache->shouldIgnoreMissing();                 // daily-counter get/increment etc. → no-op
    $cache->shouldReceive('get')->andReturn(null); // nothing cached → always fetch
    $cache->shouldReceive('put')->andReturnUsing(function ($key, $val, $ttl = null) use (&$ttls) {
        if (is_array($val)) {                      // the verdict put (a status array), not the int counter
            $ttls[] = $ttl;
        }

        return true;
    });

    $inspector = new GoogleIndexInspector(
        app(GoogleConnectionService::class), $cache,
        'https://searchconsole.googleapis.com/v1', cacheTtl: 1209600, dailyCap: 1800, pendingTtl: 259200,
    );

    HttpFacade::fake(['*/urlInspection/index:inspect' => HttpFacade::sequence()
        ->push(inspectResponse('Submitted and indexed', 'PASS'))
        ->push(inspectResponse('Crawled - currently not indexed', 'NEUTRAL')),
    ]);

    $indexed = $inspector->inspect($site, 'https://spg.example/indexed');
    $pending = $inspector->inspect($site, 'https://spg.example/pending');

    expect($indexed->indexed())->toBeTrue()
        ->and($pending->indexed())->toBeFalse()
        ->and($ttls)->toBe([1209600, 259200]); // PASS → 14d, non-PASS → 3d
});

it('cached() never makes an API call and returns null before an inspection', function () {
    inspectGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);
    HttpFacade::fake();

    expect(app(IndexInspector::class)->cached($site, 'https://spg.example/x'))->toBeNull();
    HttpFacade::assertNothingSent();
});

it('flags a canonical Google disagrees with', function () {
    inspectGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);
    HttpFacade::fake([
        '*/urlInspection/index:inspect' => HttpFacade::response(inspectResponse('Duplicate, Google chose different canonical than user', 'NEUTRAL', 'https://spg.example/other')),
    ]);

    $status = app(IndexInspector::class)->inspect($site, 'https://spg.example/hoboken');

    expect($status->state)->toBe(IndexCoverageState::ExcludedCanonical)
        ->and($status->canonicalMismatch())->toBeTrue();
});

it('degrades to null on a request timeout instead of aborting', function () {
    inspectGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);
    // A cURL timeout surfaces as a ConnectionException (NOT a GoogleException) — it must not propagate.
    HttpFacade::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(app(IndexInspector::class)->inspect($site, 'https://spg.example/slow'))->toBeNull()
        ->and(app(IndexInspector::class)->cached($site, 'https://spg.example/slow'))->toBeNull(); // not cached → retried later
});

it('stops inspecting once the per-day cap is reached', function () {
    inspectGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);
    HttpFacade::fake(['*/urlInspection/index:inspect' => HttpFacade::response(inspectResponse('Submitted and indexed'))]);

    // A cap of 1 → the first URL inspects, the second is refused (returns null, no call).
    $inspector = new GoogleIndexInspector(
        app(GoogleConnectionService::class),
        app('cache.store'),
        'https://searchconsole.googleapis.com/v1',
        259200,
        dailyCap: 1,
    );

    expect($inspector->inspect($site, 'https://spg.example/a'))->not->toBeNull()
        ->and($inspector->inspect($site, 'https://spg.example/b'))->toBeNull();
    HttpFacade::assertSentCount(1);
});
