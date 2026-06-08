<?php

namespace App\Integrations\Conversions;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Models\Site;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Deterministic conversion seam for local/tests — a few weeks of leads so the
 * dashboard renders without a live GA4/GHL pull.
 */
class MockConversionProvider implements ConversionProvider
{
    public function pull(Site $site, DateTimeInterface $since): array
    {
        $records = [];
        for ($week = 0; $week < 6; $week++) {
            $records[] = new ConversionRecord(
                type: ConversionType::Lead,
                source: ConversionSource::Ga4,
                occurredAt: new DateTimeImmutable("-{$week} weeks"),
                count: 3 + $week,
            );
        }

        return $records;
    }
}
