<?php

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Conversions\ConversionSourceException;
use App\Integrations\Conversions\KrayinConversionProvider;
use App\Models\Site;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function krayinProvider(string $token = 'ktok'): KrayinConversionProvider
{
    return new KrayinConversionProvider(app(Http::class), 'https://krayin.test', $token, ['won'], 30);
}

it('pulls only won-stage leads and collapses them to dated counts', function () {
    HttpFacade::fake([
        '*/api/v1/leads*' => HttpFacade::response(['data' => [
            ['id' => 1, 'lead_pipeline_stage' => ['code' => 'won'], 'closed_at' => '2026-06-01 10:00:00'],
            ['id' => 2, 'lead_pipeline_stage' => ['code' => 'won'], 'closed_at' => '2026-06-01 16:00:00'],
            ['id' => 3, 'lead_pipeline_stage' => ['code' => 'new'], 'updated_at' => '2026-06-01 12:00:00'], // not won → ignored
        ]]),
    ]);

    $records = krayinProvider()->pull(Site::factory()->create(), new DateTimeImmutable('-7 days'));

    expect($records)->toHaveCount(1)
        ->and($records[0]->count)->toBe(2) // two won leads same day
        ->and($records[0]->type)->toBe(ConversionType::Lead)
        ->and($records[0]->source)->toBe(ConversionSource::Krayin)
        ->and($records[0]->occurredAt->format('Y-m-d'))->toBe('2026-06-01');
});

it('is dormant when no base url / token is configured', function () {
    HttpFacade::fake();

    expect(krayinProvider(token: '')->pull(Site::factory()->create(), new DateTimeImmutable))->toBe([]);
    HttpFacade::assertNothingSent();
});

it('surfaces a 401 loudly', function () {
    HttpFacade::fake(['*/api/v1/leads*' => HttpFacade::response(['message' => 'Unauthenticated'], 401)]);

    expect(fn () => krayinProvider()->pull(Site::factory()->create(), new DateTimeImmutable))
        ->toThrow(ConversionSourceException::class);
});
