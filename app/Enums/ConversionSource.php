<?php

namespace App\Enums;

/**
 * Where a conversion was observed. The GA4/GHL pull is a mock-first seam; real
 * ingestion is a deferred integration.
 */
enum ConversionSource: string
{
    case Ga4 = 'ga4';
    case Ghl = 'ghl';
    case Manual = 'manual';
}
