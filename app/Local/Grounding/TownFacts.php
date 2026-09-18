<?php

namespace App\Local\Grounding;

use App\ContentEngine\Drafting\PageGroundingAssembler;

/**
 * Everything we can honestly say about a town, from the town's own Census GEOID.
 *
 * One seam for the page grounding, so a new source (soil drainage, climate normals per town) is added
 * here rather than threaded through {@see PageGroundingAssembler} again.
 * Sources are ordered as a writer would use them: what the housing is, then what the ground does.
 *
 * Every source is keyed on the GEOID the page already carries, so no fact can belong to another town —
 * the failure mode that made town grounding honest-by-omission in the first place. A town with nothing
 * stored still yields nothing.
 */
final class TownFacts
{
    public function __construct(
        private readonly TownHousingFacts $housing = new TownHousingFacts,
        private readonly TownFloodFacts $flood = new TownFloodFacts,
        private readonly TownElevationFacts $elevation = new TownElevationFacts,
        private readonly TownSoilFacts $soil = new TownSoilFacts,
    ) {}

    /**
     * @return list<string>
     */
    public function for(?string $geoId, ?string $siteId = null): array
    {
        $geoId = trim((string) $geoId);
        if ($geoId === '') {
            return [];
        }

        return [
            ...$this->housing->for($this->housing->row($geoId)),
            ...$this->flood->for($this->flood->row($geoId)),
            // Elevation needs the site: a number of feet only means something against the range of the
            // towns this business actually serves.
            ...$this->elevation->for($this->elevation->row($geoId), $siteId),
            // What the ground does with water — the last of the four, and the one a basement trade
            // cares about most.
            ...$this->soil->for($this->soil->row($geoId)),
        ];
    }
}
