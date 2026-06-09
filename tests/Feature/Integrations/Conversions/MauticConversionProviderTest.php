<?php

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Conversions\ConversionSourceException;
use App\Integrations\Conversions\MauticConversionProvider;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function mauticProvider(?string $formId = '7'): MauticConversionProvider
{
    return new MauticConversionProvider(
        app(Http::class), app(Cache::class),
        'https://mautic.test', 'client-1', 'secret-1', $formId, 30,
    );
}

it('authenticates then pulls form submissions as dated counts', function () {
    HttpFacade::fake([
        '*/oauth/v2/token' => HttpFacade::response(['access_token' => 'mtok', 'expires_in' => 3600]),
        '*/api/forms/7/submissions*' => HttpFacade::response(['submissions' => [
            ['id' => 1, 'dateSubmitted' => '2026-06-01 09:30:00'],
            ['id' => 2, 'dateSubmitted' => '2026-06-01 15:00:00'],
            ['id' => 3, 'dateSubmitted' => '2026-06-02 11:00:00'],
        ]]),
    ]);

    $records = mauticProvider()->pull(Site::factory()->create(), new DateTimeImmutable('-7 days'));

    expect($records)->toHaveCount(2); // two days
    $first = collect($records)->firstWhere(fn ($r) => $r->occurredAt->format('Y-m-d') === '2026-06-01');
    expect($first->count)->toBe(2)
        ->and($first->type)->toBe(ConversionType::Form)
        ->and($first->source)->toBe(ConversionSource::Mautic);

    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/api/forms/7/submissions'));
});

it('is dormant when unconfigured', function () {
    HttpFacade::fake();

    expect(mauticProvider(formId: null)->pull(Site::factory()->create(), new DateTimeImmutable))->toBe([]);
    HttpFacade::assertNothingSent();
});

it('surfaces an auth failure loudly', function () {
    HttpFacade::fake([
        '*/oauth/v2/token' => HttpFacade::response(['error' => 'invalid_client'], 401),
    ]);

    expect(fn () => mauticProvider()->pull(Site::factory()->create(), new DateTimeImmutable))
        ->toThrow(ConversionSourceException::class);
});
