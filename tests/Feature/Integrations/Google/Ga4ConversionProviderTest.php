<?php

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\Ga4ConversionProvider;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

/** A connected shared grant with a live token — the platform "one email". */
function ga4Grant(string $status = 'connected'): void
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

it('is the bound ConversionProvider', function () {
    expect(app(ConversionProvider::class))->toBeInstanceOf(Ga4ConversionProvider::class);
});

it('pulls GA4 conversions by date into normalized records', function () {
    ga4Grant();
    $site = Site::factory()->create(['ga4_property' => 'properties/123']);

    HttpFacade::fake([
        '*/properties/123:runReport' => HttpFacade::response(['rows' => [
            ['dimensionValues' => [['value' => '20260601']], 'metricValues' => [['value' => '4']]],
            ['dimensionValues' => [['value' => '20260602']], 'metricValues' => [['value' => '2']]],
            ['dimensionValues' => [['value' => '20260603']], 'metricValues' => [['value' => '0']]], // dropped
        ]]),
    ]);

    $records = app(ConversionProvider::class)->pull($site, new DateTimeImmutable('-7 days'));

    expect($records)->toHaveCount(2)
        ->and($records[0]->type)->toBe(ConversionType::Conversion)
        ->and($records[0]->source)->toBe(ConversionSource::Ga4)
        ->and($records[0]->count)->toBe(4)
        ->and($records[0]->occurredAt->format('Y-m-d'))->toBe('2026-06-01');

    HttpFacade::assertSent(fn ($request) => $request['metrics'][0]['name'] === 'conversions'
        && $request['dimensions'][0]['name'] === 'date');
});

it('returns no records when Google has never been connected', function () {
    HttpFacade::fake();
    $site = Site::factory()->create(['ga4_property' => 'properties/123']);

    expect(app(ConversionProvider::class)->pull($site, new DateTimeImmutable('-7 days')))->toBe([]);
    HttpFacade::assertNothingSent();
});

it('returns no records when the site has no GA4 property selected', function () {
    HttpFacade::fake();
    ga4Grant();
    $site = Site::factory()->create(['ga4_property' => null]);

    expect(app(ConversionProvider::class)->pull($site, new DateTimeImmutable('-7 days')))->toBe([]);
    HttpFacade::assertNothingSent();
});

it('returns no records when the shared grant needs reconnect', function () {
    HttpFacade::fake();
    ga4Grant(status: 'needs_reconnect');
    $site = Site::factory()->create(['ga4_property' => 'properties/123']);

    expect(app(ConversionProvider::class)->pull($site, new DateTimeImmutable('-7 days')))->toBe([]);
    HttpFacade::assertNothingSent();
});
