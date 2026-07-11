<?php

namespace App\Local\Proof;

use App\Models\Service;

/** No job-capture source is deployed yet — the jobs section stays omitted (never a placeholder). */
final class NullServiceJobs implements ServiceJobProvider
{
    public function for(Service $service): array
    {
        return [];
    }
}
