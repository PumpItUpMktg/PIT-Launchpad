<?php

namespace App\Support;

/**
 * The one place a dataset's expected refresh interval is read — so the freshness stamps escalate against
 * the REAL cadence, never a per-surface number (absent-state relay AC #4). Intervals match the scheduled
 * work in routes/console.php:
 *
 *   - gsc / index — daily
 *   - geo — weekly
 *   - serp — the §5 tracking gate (content_engine.pipeline.tracking_cadence_days), read directly so the
 *     freshness interval and the actual refresh cadence can never drift apart.
 *
 * Returns null for an unknown dataset (callers pass null to FreshnessStamp → an un-configured panel stays
 * quiet rather than alarming on a guessed threshold).
 */
final class Cadence
{
    /** The expected refresh interval for a dataset, in seconds — or null if the dataset has no cadence. */
    public static function intervalSeconds(string $dataset): ?int
    {
        $days = $dataset === 'serp'
            ? config('content_engine.pipeline.tracking_cadence_days')
            : config("launchpad.cadence.{$dataset}");

        return is_numeric($days) && (float) $days > 0 ? (int) round((float) $days * 86400) : null;
    }
}
