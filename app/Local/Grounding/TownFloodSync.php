<?php

namespace App\Local\Grounding;

use App\GeoGrid\TownOutlines;
use App\Integrations\Fema\FloodZones;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownFloodZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fill {@see TownFloodZone} for the towns a site covers.
 *
 * Unlike the Census housing sync, this is one request PER TOWN — the query is the town's own boundary, so
 * there is nothing to batch. Each is ~0.6s against FEMA, which is why the caller passes a `$limit` and the
 * run is resumable: towns already held are skipped, so a capped run picks up where the last one stopped
 * rather than starting over.
 *
 * Boundaries come from {@see TownOutlines} (TIGERweb, cached a month). A town whose boundary is not
 * available is left alone entirely — no row — rather than stored as "not mapped", which would be a claim
 * about FEMA we never made.
 */
final class TownFloodSync
{
    public function __construct(
        private readonly TownOutlines $outlines,
        private readonly FloodZones $fema,
    ) {}

    /**
     * @return array{towns: int, outstanding: int, fetched: int, mapped: int, unmapped: int, no_boundary: list<array{name: string, geo_id: string}>}
     */
    public function forSite(Site $site, int $limit = 100, bool $force = false): array
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('population')
            ->get(['id', 'geo_id', 'name', 'state']);

        $wanted = [];
        foreach ($towns as $town) {
            $geoId = trim((string) $town->geo_id);
            if ($geoId !== '') {
                $wanted[$geoId] = $town;
            }
        }

        $held = $force ? [] : TownFloodZone::query()->whereIn('geo_id', array_keys($wanted))->pluck('geo_id')->flip()->all();
        $todo = array_slice(array_diff_key($wanted, $held), 0, max(0, $limit), true);
        $outstanding = count($wanted) - count($held);

        // Biggest towns first, so a capped run covers the towns most likely to carry a page.
        $rings = $todo === [] ? [] : $this->outlines->for(array_map('strval', array_keys($todo)));

        $fetched = 0;
        $mapped = 0;
        $unmapped = 0;
        $noBoundary = [];
        foreach ($todo as $geoId => $town) {
            $townRings = $rings[(string) $geoId] ?? null;
            if (! is_array($townRings) || $townRings === []) {
                $noBoundary[] = ['name' => (string) $town->name, 'geo_id' => (string) $geoId];

                continue;
            }

            $zones = $this->fema->forRings($townRings);
            $hasSfha = false;
            foreach ($zones as $zone) {
                $hasSfha = $hasSfha || $zone['sfha'];
            }

            TownFloodZone::query()->updateOrCreate(['geo_id' => (string) $geoId], [
                'name' => (string) $town->name,
                'state' => $town->state,
                // Nothing back = FEMA has not mapped here, which is NOT the same as "no flood hazard".
                'mapped' => $zones !== [],
                'has_sfha' => $hasSfha,
                'zones' => $zones,
                'fetched_at' => Carbon::now(),
            ]);
            $fetched++;
            $zones === [] ? $unmapped++ : $mapped++;
        }

        Log::info('Town flood zones: FEMA sync pass complete.', [
            'site_id' => (string) $site->id, 'fetched' => $fetched, 'mapped' => $mapped,
            'unmapped' => $unmapped, 'no_boundary' => count($noBoundary), 'outstanding' => $outstanding,
        ]);

        return [
            'towns' => count($wanted),
            'outstanding' => $outstanding,
            'fetched' => $fetched,
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'no_boundary' => $noBoundary,
        ];
    }
}
