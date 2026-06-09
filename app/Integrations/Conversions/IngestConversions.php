<?php

namespace App\Integrations\Conversions;

use App\Models\Conversion;
use App\Models\ConversionSyncState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The shared conversion ingest sweep (closes finding 2). Per tenant, it pulls
 * from every ACTIVE ConversionProvider (GA4 + Krayin + Mautic can all be live for
 * one client — this is aggregation, not a single-provider switch) and upserts
 * dated-count Conversion rows the §7c dashboard reads.
 *
 *  - Tags each row with source + type so the dashboard tells web conversions from
 *    leads from form submissions.
 *  - Syncs incrementally via a per-(site × source) cursor, with a short overlap
 *    so late-arriving data is corrected.
 *  - Upserts idempotently on (site × source × type × day), overwriting the count
 *    — re-running a window never double-counts.
 *  - Isolates per-provider failures: if Krayin is down, GA4/Mautic still ingest;
 *    the failure is surfaced, the run continues.
 */
class IngestConversions implements ShouldQueue
{
    use Queueable;

    /** Re-pull this many days behind the cursor to catch late-arriving data. */
    private const OVERLAP_DAYS = 2;

    /** First-run lookback when there is no cursor yet. */
    private const BACKFILL_DAYS = 30;

    public function handle(ConversionProviders $providers): void
    {
        $active = $providers->all();

        Site::query()->withoutGlobalScope(SiteScope::class)->each(function (Site $site) use ($active): void {
            foreach ($active as $provider) {
                $this->ingest($site, $provider);
            }
        });
    }

    private function ingest(Site $site, ConversionProvider $provider): void
    {
        $source = $provider->source();

        $state = ConversionSyncState::withoutGlobalScope(SiteScope::class)
            ->firstOrNew(['site_id' => $site->id, 'source' => $source->value]);

        $since = $state->last_synced_at !== null
            ? $state->last_synced_at->copy()->subDays(self::OVERLAP_DAYS)
            : now()->subDays(self::BACKFILL_DAYS);

        try {
            foreach ($provider->pull($site, $since) as $record) {
                Conversion::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'source' => $record->source->value,
                        'type' => $record->type->value,
                        'occurred_at' => $record->occurredAt->format('Y-m-d').' 00:00:00',
                    ],
                    ['count' => $record->count],
                );
            }

            $state->fill(['site_id' => $site->id, 'source' => $source, 'last_synced_at' => now()])->save();
        } catch (Throwable $e) {
            // Surface, isolate: one source failing must not abort the others.
            Log::warning('Conversion ingest failed', [
                'site_id' => $site->id,
                'source' => $source->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
