<?php

namespace App\Geo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A per-tenant "GEO check running" flag — the state behind the in-process indicator on the AI Search
 * screen. A run (the {@see GeoVisibilityAudit}, whether fired by the command or the queued job) marks
 * the tenant checking while it works and clears it when done. Backed by the shared cache so the web UI
 * can read what a worker set, and given a TTL past the run's budget so a crashed run can never leave a
 * stuck "checking" flag.
 */
class GeoCheckStatus
{
    public function begin(string $siteId): void
    {
        Cache::put($this->key($siteId), Carbon::now()->toIso8601String(), $this->ttlSeconds());
    }

    public function finish(string $siteId): void
    {
        Cache::forget($this->key($siteId));
    }

    /** When the current run started, or null if the tenant isn't being checked right now. */
    public function startedAt(string $siteId): ?Carbon
    {
        $value = Cache::get($this->key($siteId));

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function isRunning(string $siteId): bool
    {
        return $this->startedAt($siteId) !== null;
    }

    private function key(string $siteId): string
    {
        return "geo:check:running:{$siteId}";
    }

    private function ttlSeconds(): int
    {
        // Expire well past the run budget so a crash can't strand the flag; a real run clears it sooner.
        return (int) config('launchpad.geo.budget_seconds', 240) + 120;
    }
}
