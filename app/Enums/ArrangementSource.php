<?php

namespace App\Enums;

/**
 * Provenance of an auto-arrange structural decision (fold target, sub-hub parent,
 * primary keyword). The decision-preservation twin keys on this: auto-arrange writes
 * only over `Auto` (or untouched) decisions and never over `Confirmed` ones — the
 * `source='manual'` lesson from CoverageWriter, applied to the silo taxonomy.
 */
enum ArrangementSource: string
{
    /** auto-arrange set this; a re-run may overwrite it. */
    case Auto = 'auto';

    /** Operator accepted or dismissed the recommendation; a re-run preserves it. */
    case Confirmed = 'confirmed';
}
