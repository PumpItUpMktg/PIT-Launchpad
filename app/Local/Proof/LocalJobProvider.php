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
}
