<?php

namespace App\Integrations\Conversions;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use DateTimeImmutable;

/**
 * A normalized conversion observation from the GA4/GHL seam. Totals only — no
 * attribution to a specific engine action (honest framing).
 */
final class ConversionRecord
{
    public function __construct(
        public readonly ConversionType $type,
        public readonly ConversionSource $source,
        public readonly DateTimeImmutable $occurredAt,
        public readonly int $count = 1,
    ) {}
}
