<?php

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Conversions\ConversionProviders;
use App\Integrations\Conversions\ConversionRecord;
use App\Integrations\Conversions\IngestConversions;
use App\Models\Conversion;
use App\Models\ConversionSyncState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Tests\Support\FakeConversionProvider;

/**
 * Point the ingest job at exactly these providers (bypassing the tagged set, so
 * the dormant real providers don't interfere with cursor/assertion determinism).
 *
 * @param  list<FakeConversionProvider>  $providers
 */
function ingestWith(array $providers): void
{
    app()->instance(ConversionProviders::class, new ConversionProviders($providers));
    app()->call([new IngestConversions, 'handle']);
}

it('upserts both an aggregate (GA4) and an individual (CRM) conversion, tagged by source/type', function () {
    $site = Site::factory()->create();

    ingestWith([
        new FakeConversionProvider(ConversionSource::Ga4, [
            new ConversionRecord(ConversionType::Conversion, ConversionSource::Ga4, new DateTimeImmutable('2026-06-01'), 5),
        ]),
        // CRM individual leads collapsed to a dated count via the shared helper.
        new FakeConversionProvider(ConversionSource::Krayin, ConversionRecord::dailyCounts(
            ConversionType::Lead,
            ConversionSource::Krayin,
            [new DateTimeImmutable('2026-06-01 09:00'), new DateTimeImmutable('2026-06-01 14:00')],
        )),
    ]);

    $rows = Conversion::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(2);

    $ga4Row = $rows->firstWhere('source', ConversionSource::Ga4);
    $krayinRow = $rows->firstWhere('source', ConversionSource::Krayin);

    expect($ga4Row->count)->toBe(5)
        ->and($ga4Row->type)->toBe(ConversionType::Conversion)
        ->and($krayinRow->count)->toBe(2) // two leads same day → count 2
        ->and($krayinRow->type)->toBe(ConversionType::Lead);
});

it('is idempotent — re-running overwrites the day count, never doubles', function () {
    $site = Site::factory()->create();
    $records = [new ConversionRecord(ConversionType::Conversion, ConversionSource::Ga4, new DateTimeImmutable('2026-06-02'), 4)];

    ingestWith([new FakeConversionProvider(ConversionSource::Ga4, $records)]);
    ingestWith([new FakeConversionProvider(ConversionSource::Ga4, $records)]);

    $rows = Conversion::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->count)->toBe(4); // overwritten, not 8
});

it('advances a per-source incremental cursor and backfills on the first run', function () {
    $site = Site::factory()->create();
    $ga4 = new FakeConversionProvider(ConversionSource::Ga4, []);

    ingestWith([$ga4]);

    $state = ConversionSyncState::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('source', ConversionSource::Ga4->value)->first();
    expect($state)->not->toBeNull()
        ->and($state->last_synced_at)->not->toBeNull()
        ->and($ga4->calledWith[0]->format('Y-m-d'))->toBe(now()->subDays(30)->format('Y-m-d'));
});

it('isolates per-provider failures — one source down, the others still ingest', function () {
    $site = Site::factory()->create();

    ingestWith([
        new FakeConversionProvider(ConversionSource::Krayin, [], throw: 'Krayin 503'),
        new FakeConversionProvider(ConversionSource::Ga4, [
            new ConversionRecord(ConversionType::Conversion, ConversionSource::Ga4, new DateTimeImmutable('2026-06-03'), 3),
        ]),
    ]);

    // GA4 still ingested despite Krayin throwing.
    expect(Conversion::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'ga4')->count())->toBe(1);
    // Krayin wrote nothing and did not advance its cursor.
    expect(ConversionSyncState::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'krayin')->exists())->toBeFalse();
});
