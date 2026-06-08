<?php

namespace App\Enums;

/**
 * Conversion-intent level for a query. Buyer-in-pain / hire-now ranks above
 * generic research in opportunity scoring.
 */
enum IntentLevel: string
{
    case Transactional = 'transactional';
    case Commercial = 'commercial';
    case Informational = 'informational';
    case Navigational = 'navigational';

    /**
     * A 0..1 intent weight.
     */
    public function weight(): float
    {
        return match ($this) {
            self::Transactional => 1.0,
            self::Commercial => 0.7,
            self::Navigational => 0.4,
            self::Informational => 0.25,
        };
    }

    public function isInformational(): bool
    {
        return $this === self::Informational;
    }
}
