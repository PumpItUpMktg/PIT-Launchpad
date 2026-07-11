<?php

namespace App\Local\Proof;

use App\Models\Service;

/**
 * The service-scoped recent-jobs source for a spoke page — the SAME {@see LocalJob} DTO the
 * location relay fixed, filtered by `job.service == this service` (the provider owns the matching).
 * Contract-first: the field job-capture system lands later. Empty ⇒ the section omits entirely.
 *
 * @see NullServiceJobs the default binding
 */
interface ServiceJobProvider
{
    /** @return list<LocalJob> */
    public function for(Service $service): array;
}
