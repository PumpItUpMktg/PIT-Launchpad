<?php

namespace App\Enums;

/**
 * Where a cold contact sits in the import → verify → warm → promote pipeline.
 *
 * Imported → (Bird lookup) → Verified | Risky | Invalid
 * Verified → (pushed to Instantly) → Warming → Warm | Disqualified
 * Warm → (pushed to Brevo) → Promoted
 */
enum ColdContactStage: string
{
    case Imported = 'imported';
    case Verified = 'verified';
    case Risky = 'risky';
    case Invalid = 'invalid';
    case Warming = 'warming';
    case Warm = 'warm';
    case Promoted = 'promoted';
    case Disqualified = 'disqualified';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Imported',
            self::Verified => 'Verified',
            self::Risky => 'Risky (review)',
            self::Invalid => 'Invalid',
            self::Warming => 'Warming',
            self::Warm => 'Warm',
            self::Promoted => 'In Brevo',
            self::Disqualified => 'Disqualified',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Imported => 'gray',
            self::Verified => 'info',
            self::Risky => 'warning',
            self::Invalid, self::Disqualified => 'danger',
            self::Warming => 'primary',
            self::Warm, self::Promoted => 'success',
        };
    }

    /** Terminal stages: the pipeline never moves these again on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Invalid, self::Promoted, self::Disqualified], true);
    }
}
