<?php

namespace App\Enums;

/**
 * Where a news story is anchored, relative to the tenant's footprint — decided in code from the place the
 * scorer extracted, never by the model's own in/out opinion.
 *
 * - Footprint: the story's state is one the tenant serves (an office, a market or a coverage town there).
 * - Region: a neighbouring state — kept as general advisory content, never counted as local.
 * - Outside: a named place beyond the region ring — dropped before any draft (an Omaha sewer backup on an
 *   NJ/PA brand is somebody else's local news).
 * - Unknown: no place named (a placeless how-to, a national piece) or the footprint is unknown.
 */
enum PlaceVerdict: string
{
    case Footprint = 'footprint';
    case Region = 'region';
    case Outside = 'outside';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Footprint => 'In footprint',
            self::Region => 'Region',
            self::Outside => 'Out of market',
            self::Unknown => 'No place',
        };
    }
}
