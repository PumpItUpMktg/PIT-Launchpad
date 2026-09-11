<?php

namespace App\Local\Proof;

use App\Models\Location;

/**
 * The recent-jobs source for a location page — contract-first (the field job-capture system lands
 * later). Empty ⇒ the section omits entirely; no headers over nothing, no placeholders.
 *
 * @see NullLocalJobs the default binding
 */
interface LocalJobProvider
{
    /** @return list<LocalJob> */
    public function for(Location $location): array;

    /**
     * Published jobs whose public (jittered) point is within $radius miles of an arbitrary SUBJECT point,
     * nearest-first — so a town page measures recent work from ITS OWN centroid rather than its parent
     * office's market. Empty ⇒ the section omits.
     *
     * @return list<LocalJob>
     */
    public function near(string $siteId, float $lat, float $lng, float $radius): array;
}
