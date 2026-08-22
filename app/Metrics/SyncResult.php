<?php

namespace App\Metrics;

use App\Models\MetricSyncRun;

/**
 * The outcome of a single provider sync (§ Client Dashboard v1). Immutable; a provider returns one of these
 * and SyncSiteMetrics records it onto the metric_sync_runs row.
 */
final readonly class SyncResult
{
    /**
     * @param  MetricSyncRun::STATUS_*  $status
     */
    public function __construct(
        public string $status,
        public int $rowsWritten = 0,
        public ?string $error = null,
    ) {}

    public static function success(int $rowsWritten): self
    {
        return new self(MetricSyncRun::STATUS_SUCCESS, $rowsWritten);
    }

    public static function partial(int $rowsWritten, string $error): self
    {
        return new self(MetricSyncRun::STATUS_PARTIAL, $rowsWritten, $error);
    }

    public static function failed(string $error, int $rowsWritten = 0): self
    {
        return new self(MetricSyncRun::STATUS_FAILED, $rowsWritten, $error);
    }
}
