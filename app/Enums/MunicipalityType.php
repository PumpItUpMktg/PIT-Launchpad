<?php

namespace App\Enums;

/**
 * The kind of Census geography a coverage municipality is. County subdivisions (MCDs —
 * townships, boroughs) are first-class here: in NJ/PA most "towns" are MCDs, not
 * incorporated places, so an incorporated-places-only set is a silent coverage failure.
 */
enum MunicipalityType: string
{
    /** An incorporated place (city/town/village) — Census place GEOID. */
    case Place = 'place';

    /** A county subdivision / minor civil division (township, borough). */
    case CountySubdivision = 'county_subdivision';

    public function label(): string
    {
        return match ($this) {
            self::Place => 'Place',
            self::CountySubdivision => 'Township/Borough (MCD)',
        };
    }
}
