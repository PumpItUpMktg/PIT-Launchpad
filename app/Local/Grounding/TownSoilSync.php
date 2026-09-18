<?php

namespace App\Local\Grounding;

use App\GeoGrid\TownOutlines;
use App\Integrations\Usda\SoilDrainage;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownSoilDrainage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fill {@see TownSoilDrainage} for the towns a site covers.
 *
 * One request per town — the query IS the town's boundary — at about 0.9s each, so the pass is capped
 * and resumable, exactly like the FEMA sync it sits beside. Boundaries come from {@see TownOutlines}
 * (TIGERweb, cached a month), and a town whose boundary we cannot read is left alone entirely rather
 * than recorded as unsurveyed: we never asked.
 */
final class TownSoilSync
{
    /** Ground that drains poorly enough to matter to a basement, in USDA's own words. */
    public const POORLY = ['Somewhat poorly drained', 'Poorly drained', 'Very poorly drained'];

    public function __construct(
        private readonly TownOutlines $outlines,
        private readonly SoilDrainage $usda,
    ) {}

    /**
     * @return array{towns: int, outstanding: int, fetched: int, surveyed: int, unsurveyed: int, no_boundary: list<array{name: string, geo_id: string}>}
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

        $held = $force ? [] : TownSoilDrainage::query()->whereIn('geo_id', array_keys($wanted))->pluck('geo_id')->flip()->all();
        $todo = array_slice(array_diff_key($wanted, $held), 0, max(0, $limit), true);
        $outstanding = count($wanted) - count($held);

        $rings = $todo === [] ? [] : $this->outlines->for(array_map('strval', array_keys($todo)));

        $fetched = 0;
        $surveyed = 0;
        $noBoundary = [];
        foreach ($todo as $geoId => $town) {
            $townRings = $rings[(string) $geoId] ?? null;
            if (! is_array($townRings) || $townRings === []) {
                $noBoundary[] = ['name' => (string) $town->name, 'geo_id' => (string) $geoId];

                continue;
            }

            $classes = $this->usda->forRings($townRings);
            $poorly = 0.0;
            foreach ($classes as $class) {
                if (in_array($class['class'], self::POORLY, true)) {
                    $poorly += $class['share'];
                }
            }

            TownSoilDrainage::query()->updateOrCreate(['geo_id' => (string) $geoId], [
                'name' => (string) $town->name,
                'state' => $town->state,
                // Nothing back = this ground is not in the survey, which is NOT "it drains fine".
                'surveyed' => $classes !== [],
                'dominant' => $classes[0]['class'] ?? null,
                'poorly_share' => $classes === [] ? null : round($poorly, 4),
                'classes' => $classes,
                'fetched_at' => Carbon::now(),
            ]);
            $fetched++;
            if ($classes !== []) {
                $surveyed++;
            }
        }

        Log::info('Town soil: USDA sync pass complete.', [
            'site_id' => (string) $site->id, 'fetched' => $fetched, 'surveyed' => $surveyed,
            'no_boundary' => count($noBoundary), 'outstanding' => $outstanding,
        ]);

        return [
            'towns' => count($wanted),
            'outstanding' => $outstanding,
            'fetched' => $fetched,
            'surveyed' => $surveyed,
            'unsurveyed' => $fetched - $surveyed,
            'no_boundary' => $noBoundary,
        ];
    }
}
