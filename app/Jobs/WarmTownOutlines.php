<?php

namespace App\Jobs;

use App\GeoGrid\CountyOutlines;
use App\GeoGrid\TownOutlines;
use App\Models\Site;
use App\TownRank\TownRankPoints;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetch the Census boundaries the whole-site town map wants, OFF the web request.
 *
 * The map draws each town's own shape coloured by its rank, and a site covers ~700 towns. Fetching those
 * boundaries inside a page render is exactly the shape of request that used to time this page out, so the
 * render reads cache-only and falls back to a dot for anything missing; this fills the gaps so the shapes
 * appear on a later view. Boundaries are cached for 30 days and a town's outline does not move, so this is
 * a one-off cost per town that then holds for a month.
 *
 * Time-boxed well under its timeout: a pass warms what it can and the next pass continues, rather than
 * running unbounded against a gazetteer that rate-limits.
 */
class WarmTownOutlines implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 280;

    /** Best-effort: a failed warm must not retry-storm — the next scheduled pass picks up where it left off. */
    public int $tries = 1;

    public int $uniqueFor = 600;

    /** Stop fetching after this long, under the timeout, so a pass always ends cleanly. */
    private const SOFT_BUDGET_SECONDS = 200;

    /** Towns per gazetteer call — the reader batches, this bounds one pass's appetite. */
    private const CHUNK = 100;

    public function __construct(public ?string $siteId = null) {}

    public function uniqueId(): string
    {
        return 'warm-town-outlines:'.($this->siteId ?? 'all');
    }

    public function handle(TownRankPoints $points, TownOutlines $towns, CountyOutlines $counties): void
    {
        $started = microtime(true);
        $sites = $this->siteId !== null
            ? Site::withoutGlobalScopes()->whereKey($this->siteId)->get()
            : Site::withoutGlobalScopes()->get();

        $fetched = 0;
        $left = 0;
        foreach ($sites as $site) {
            $geoIds = [];
            $countyIds = [];
            foreach ($points->forSite($site) as $town) {
                $geoId = trim((string) ($town['geo_id'] ?? ''));
                if ($geoId !== '') {
                    $geoIds[] = $geoId;
                    // The county an outline belongs to is the GEOID's first five digits.
                    $countyIds[] = substr($geoId, 0, 5);
                }
            }
            if ($geoIds === []) {
                continue;
            }

            try {
                $counties->for(array_values(array_unique($countyIds)));
            } catch (Throwable $e) {
                Log::warning('Town outline warm: county outlines failed.', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }

            $missing = $towns->missing($geoIds);
            $left += count($missing);
            foreach (array_chunk($missing, self::CHUNK) as $chunk) {
                if (microtime(true) - $started >= self::SOFT_BUDGET_SECONDS) {
                    break 2;
                }
                $towns->for($chunk);   // fetches and caches; a town the Census has no shape for stays absent
                $fetched += count($chunk);
                $left -= count($chunk);
            }
        }

        Log::info('Town outline warm: pass finished.', [
            'sites' => $sites->count(),
            'requested' => $fetched,
            'still_missing' => max(0, $left),
            'seconds' => round(microtime(true) - $started, 1),
        ]);
    }
}
