<?php

namespace App\Jobs;

use App\Metrics\MetricProviderRegistry;
use App\Metrics\SyncResult;
use App\Models\MetricSyncRun;
use App\Models\Site;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync ONE provider's metrics for ONE site over ONE date range (§ Client Dashboard v1). The single entry
 * point that opens a metric_sync_runs row, resolves the provider from the registry, runs its sync(), and
 * records the outcome — so every attempt (success, partial, failure, provider-missing) leaves an auditable
 * row and the UI's "data through {date}" can read the latest success.
 *
 * One queue per provider (metrics:{provider}) so a slow/expensive provider (DataForSEO) can't starve a
 * cheap one (GSC). Ranges are passed as date strings for robust queue serialization and reconstructed into
 * a CarbonPeriod for the provider contract.
 *
 * Best-effort: $tries = 1 — the daily fan-out re-pulls the trailing window, so a transient provider error
 * self-heals on the next cadence rather than storming retries against a metered API.
 */
class SyncSiteMetrics implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly string $siteId,
        public readonly string $provider,
        public readonly string $rangeStart,
        public readonly string $rangeEnd,
    ) {
        // Per-provider queue by default (metrics:{provider}) so a slow provider can't starve a fast one;
        // a single-worker deployment can collapse them all onto one queue via LAUNCHPAD_METRICS_QUEUE.
        $this->onQueue(self::queueFor($provider));
    }

    /** The queue a provider's sync rides: the configured shared queue, else the per-provider default. */
    public static function queueFor(string $provider): string
    {
        $configured = config('launchpad.metrics.queue');

        return is_string($configured) && $configured !== '' ? $configured : 'metrics:'.$provider;
    }

    public function handle(MetricProviderRegistry $registry): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $run = MetricSyncRun::withoutGlobalScopes()->create([
            'site_id' => $site->id,
            'provider' => $this->provider,
            'range_start' => $this->rangeStart,
            'range_end' => $this->rangeEnd,
            'status' => MetricSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $provider = $registry->get($this->provider);
        if ($provider === null) {
            // A range dispatched for a provider that hasn't been wired yet (e.g. before PR 2/4) records a
            // clear failure rather than throwing — the fan-out for other providers is unaffected.
            $this->finish($run, SyncResult::failed("provider [{$this->provider}] is not registered"));

            return;
        }

        try {
            $range = CarbonPeriod::create(
                Carbon::parse($this->rangeStart)->startOfDay(),
                Carbon::parse($this->rangeEnd)->startOfDay(),
            );
            $this->finish($run, $provider->sync($site, $range));
        } catch (Throwable $e) {
            Log::warning('metric sync failed', [
                'site_id' => $site->id, 'provider' => $this->provider, 'error' => $e->getMessage(),
            ]);
            $this->finish($run, SyncResult::failed($e->getMessage()));
        }
    }

    private function finish(MetricSyncRun $run, SyncResult $result): void
    {
        $run->update([
            'status' => $result->status,
            'rows_written' => $result->rowsWritten,
            'error_message' => $result->error,
            'finished_at' => now(),
        ]);
    }
}
