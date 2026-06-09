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

    /**
     * Collapse individual CRM events (each a date) into dated-count records — the
     * shape the Conversion model holds and the ingest upserts idempotently.
     * Per-event identity / deal value is intentionally not carried (the model has
     * no column for it — a flagged §1/§7c follow-up).
     *
     * @param  list<DateTimeImmutable>  $dates
     * @return list<self>
     */
    public static function dailyCounts(ConversionType $type, ConversionSource $source, array $dates): array
    {
        $byDay = [];
        foreach ($dates as $date) {
            $key = $date->format('Y-m-d');
            $byDay[$key] = ($byDay[$key] ?? 0) + 1;
        }

        $records = [];
        foreach ($byDay as $day => $count) {
            $records[] = new self($type, $source, new DateTimeImmutable($day.' 00:00:00'), $count);
        }

        return $records;
    }
}
