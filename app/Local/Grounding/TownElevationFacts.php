<?php

namespace App\Local\Grounding;

use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\TownElevation;

/**
 * A town's elevation, said the only way it means anything: RELATIVE to the rest of the service area.
 *
 * "Warrington sits at about 417 feet" tells a homeowner nothing — they have no scale to read it against,
 * and every town has a number. What carries information is being at an END of the local range: the low
 * towns are where water collects, and a business that works on basements meets different ground there.
 *
 * So a fact appears only when the town is notably low or notably high against the towns this site
 * serves, by both rank and a real difference in feet. The middle of the range says nothing.
 */
final class TownElevationFacts
{
    /** Fewer towns than this and there is no local range to be at the end of. */
    private const MIN_TOWNS = 5;

    /** A town must sit at least this far from the middle before the difference is worth a sentence. */
    private const MEANINGFUL_FEET = 75.0;

    /** …and be inside this share of the range's ends. */
    private const EDGE = 0.25;

    /**
     * @return list<string>
     */
    public function for(?TownElevation $town, ?string $siteId): array
    {
        if ($town === null || $town->elevation_ft === null || $siteId === null) {
            return [];
        }

        $others = $this->siteElevations($siteId);
        if (count($others) < self::MIN_TOWNS) {
            return [];
        }

        sort($others);
        $count = count($others);
        $median = $others[(int) floor($count / 2)];
        $feet = (float) $town->elevation_ft;

        $below = 0;
        foreach ($others as $other) {
            if ($other < $feet) {
                $below++;
            }
        }
        $rank = $below / $count;   // 0 = lowest in the area, 1 = highest

        $name = trim((string) $town->name) !== '' ? trim((string) $town->name) : 'this town';
        $difference = abs($feet - $median);
        if ($difference < self::MEANINGFUL_FEET) {
            return [];
        }

        if ($rank <= self::EDGE) {
            return [sprintf(
                '%s is among the lower-lying towns in this service area — about %d feet, where the middle of the area sits nearer %d.',
                $name, (int) round($feet), (int) round($median),
            )];
        }

        if ($rank >= 1 - self::EDGE) {
            return [sprintf(
                '%s sits high for this service area — about %d feet, where the middle of the area sits nearer %d.',
                $name, (int) round($feet), (int) round($median),
            )];
        }

        return [];
    }

    /** The stored row for a page's Census GEOID, or null when the town has never been measured. */
    public function row(?string $geoId): ?TownElevation
    {
        $geoId = trim((string) $geoId);

        return $geoId === '' ? null : TownElevation::query()->where('geo_id', $geoId)->first();
    }

    /**
     * Every measured elevation among the towns this site covers — the range the subject is read against.
     *
     * @return list<float>
     */
    private function siteElevations(string $siteId): array
    {
        $geoIds = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->pluck('geo_id')
            ->map(fn ($g): string => trim((string) $g))
            ->filter()
            ->unique()
            ->all();
        if ($geoIds === []) {
            return [];
        }

        return TownElevation::query()
            ->whereIn('geo_id', $geoIds)
            ->whereNotNull('elevation_ft')
            ->pluck('elevation_ft')
            ->map(fn ($f): float => (float) $f)
            ->all();
    }
}
