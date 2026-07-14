<?php

namespace App\Enums;

/**
 * Field provenance for interview-seeded values (gathering relay). `Seeded` = written by the
 * extraction pass, awaiting a human eye; `Confirmed` = an operator saved the field on a review
 * surface. Extraction NEVER overwrites a confirmed field; re-runs update only seeded/empty ones.
 * Fields entered manually without seeding simply have no provenance row.
 */
enum ProvenanceState: string
{
    case Seeded = 'seeded';
    case Confirmed = 'confirmed';
}
