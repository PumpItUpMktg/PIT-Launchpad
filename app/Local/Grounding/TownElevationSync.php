<?php

namespace App\Local\Grounding;

use App\Integrations\Usgs\Elevation;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownElevation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fill {@see TownElevation} for the towns a site covers.
 *
 * One request per town — the service answers for a point, so there is nothing to batch — at about 0.9s
 * each. Hence the cap and the resumability: towns already held are skipped, so a capped run continues
 * where the last one stopped, exactly like the FEMA sync beside it.
 *
 * A town whose row comes back without a value is still WRITTEN, with a null elevation: it was asked, and
 * recording that stops the next pass asking again forever.
 */
final class TownElevationSync
{
    public function __construct(private readonly Elevation $usgs) {}

    /**
     * @return array{towns: int, outstanding: int, fetched: int, measured: int, unknown: int}
     */
    public function forSite(Site $site, int $limit = 250, bool $force = false): array
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderByDesc('population')
            ->get(['id', 'geo_id', 'name', 'state', 'lat', 'lng']);

        $wanted = [];
        foreach ($towns as $town) {
            $geoId = trim((string) $town->geo_id);
            if ($geoId !== '') {
                $wanted[$geoId] = $town;
            }
        }

        $held = $force ? [] : TownElevation::query()->whereIn('geo_id', array_keys($wanted))->pluck('geo_id')->flip()->all();
        $todo = array_slice(array_diff_key($wanted, $held), 0, max(0, $limit), true);
        $outstanding = count($wanted) - count($held);

        $fetched = 0;
        $measured = 0;
        foreach ($todo as $geoId => $town) {
            $feet = $this->usgs->forPoint((float) $town->lat, (float) $town->lng);

            TownElevation::query()->updateOrCreate(['geo_id' => (string) $geoId], [
                'name' => (string) $town->name,
                'state' => $town->state,
                'elevation_ft' => $feet,
                'fetched_at' => Carbon::now(),
            ]);
            $fetched++;
            if ($feet !== null) {
                $measured++;
            }
        }

        Log::info('Town elevation: USGS sync pass complete.', [
            'site_id' => (string) $site->id, 'fetched' => $fetched, 'measured' => $measured,
            'outstanding' => $outstanding,
        ]);

        return [
            'towns' => count($wanted),
            'outstanding' => $outstanding,
            'fetched' => $fetched,
            'measured' => $measured,
            'unknown' => $fetched - $measured,
        ];
    }
}
