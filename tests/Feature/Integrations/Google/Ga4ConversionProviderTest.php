<?php

use App\Enums\ConnectionProvider;
use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\Ga4ConversionProvider;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\Http as HttpFacade;

/**
 * @param  array<string, mixed>  $credentials
 */
function ga4Connection(Site $site, array $credentials, string $status = 'connected'): Connection
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

it('is the bound ConversionProvider', function () {
    expect(app(ConversionProvider::class))->toBeInstanceOf(Ga4ConversionProvider::class);
});

it('pulls GA4 conversions by date into normalized records', function () {
    $site = Site::factory()->create();
    ga4Connection($site, ['ga4_property' => 'properties/123']);

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

it('returns no records when the site has no Google connection', function () {
    HttpFacade::fake();
    $site = Site::factory()->create();

    expect(app(ConversionProvider::class)->pull($site, new DateTimeImmutable('-7 days')))->toBe([]);
    HttpFacade::assertNothingSent();
});

it('returns no records when the connection needs reconnect', function () {
    HttpFacade::fake();
    $site = Site::factory()->create();
    ga4Connection($site, ['ga4_property' => 'properties/123'], status: 'needs_reconnect');

    expect(app(ConversionProvider::class)->pull($site, new DateTimeImmutable('-7 days')))->toBe([]);
    HttpFacade::assertNothingSent();
});
