<?php

namespace App\Enums;

/**
 * A transition in a citation's life, recorded on the append-only event ledger (§ Citations, PR4). The monthly
 * diff buckets are just counts of these: `discovered` = newly covered, `fixed` = a wrong listing corrected,
 * `regressed` = a covered listing gone wrong, `lost` = a covered listing gone. `stalled` marks a gap that has
 * survived too many scans without resolution — the signal an operator escalates.
 */
enum CitationEventType: string
{
    case Discovered = 'discovered';
    case Fixed = 'fixed';
    case Regressed = 'regressed';
    case Lost = 'lost';
    case Stalled = 'stalled';

    // Submit → verify lifecycle (PR7)
    case Submitted = 'submitted';
    case Verified = 'verified';       // a submitted listing confirmed live/fixed on a later scan
    case Rejected = 'rejected';
    case Unverified = 'unverified';   // failed verification too many cycles → operator review

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** A backward step — a listing we had is now wrong or gone. Drives the regression alert. */
    public function isRegression(): bool
    {
        return in_array($this, [self::Regressed, self::Lost], true);
    }
}
