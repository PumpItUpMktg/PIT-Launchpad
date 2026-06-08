<?php

namespace App\Integrations\Conversions;

use App\Models\Site;
use DateTimeInterface;

/**
 * The conversion-ingestion seam (GA4 / GHL → leads/conversions). Vendor-deferred
 * and mock-first: the real pull+normalize adapter binds later with no change to
 * the dashboard, which reads whatever the Conversion model holds.
 */
interface ConversionProvider
{
    /**
     * @return list<ConversionRecord>
     */
    public function pull(Site $site, DateTimeInterface $since): array;
}
