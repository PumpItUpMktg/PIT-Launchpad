<?php

namespace App\Integrations\Conversions;

use App\Enums\ConversionSource;
use App\Models\Site;
use DateTimeInterface;

/**
 * The conversion-ingestion seam (GA4 / Krayin / Mautic → leads/conversions).
 * Several providers can be active for one tenant at once; the ingest job
 * aggregates them. The dashboard reads whatever the Conversion model holds.
 */
interface ConversionProvider
{
    /**
     * The source this provider emits — lets the ingest job key its incremental
     * cursor per (site × source) before pulling.
     */
    public function source(): ConversionSource;

    /**
     * Pull normalized (dated-count) records since the cursor. A provider with no
     * configuration / connection for the site returns an empty list (dormant).
     *
     * @return list<ConversionRecord>
     */
    public function pull(Site $site, DateTimeInterface $since): array;
}
