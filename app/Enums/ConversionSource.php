<?php

namespace App\Enums;

/**
 * Where a conversion was observed. GA4 (web conversions), Krayin (won-stage CRM
 * leads), and Mautic (form submissions / campaign goals) are aggregated by the
 * conversion ingest job; the dashboard tells them apart by this tag.
 */
enum ConversionSource: string
{
    case Ga4 = 'ga4';
    case Ghl = 'ghl';
    case Krayin = 'krayin';
    case Mautic = 'mautic';
    case Manual = 'manual';
}
