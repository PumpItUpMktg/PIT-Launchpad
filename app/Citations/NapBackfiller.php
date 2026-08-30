<?php

namespace App\Citations;

use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * One-shot backfill: runs the {@see NapProfileHydrator} over existing locations so GBP-backed ones that predate
 * the auto-population get a canonical NAP without re-importing each by hand. Uses the location's already-stored
 * GBP data (no network), so it's cheap and idempotent — re-running only syncs drift and skips locations with no
 * usable GBP data. Shared by the console command and the operator portfolio button.
 */
final class NapBackfiller
{
    public function __construct(private readonly NapProfileHydrator $hydrator) {}

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function run(?string $siteId = null): array
    {
        $query = Location::query()->withoutGlobalScope(SiteScope::class)->whereNull('merged_into_id');
        if ($siteId !== null && $siteId !== '') {
            $query->where('site_id', $siteId);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $query->each(function (Location $location) use (&$created, &$updated, &$skipped): void {
            $result = $this->hydrator->hydrate($location);
            if ($result->created()) {
                $created++;
            } elseif ($result->updated()) {
                $updated++;
            } elseif ($result->skipped()) {
                $skipped++;
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
