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

    /**
     * A short, honest word to distinguish two SAME-NAME municipalities in the SAME county when the county
     * alone can't (a Census `place` and its neighboring `county_subdivision` both named "Bethlehem"). An
     * incorporated place is the town proper; an MCD in NJ/PA is a township or borough — "Twp/Boro" keeps
     * both truths without claiming the wrong one. Used only as a tie-breaker; the common path is county-only.
     */
    public function disambiguator(): string
    {
        return match ($this) {
            self::Place => '',
            self::CountySubdivision => 'Twp/Boro',
        };
    }
}
