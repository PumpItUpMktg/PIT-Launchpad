<?php

namespace App\Enums;

/**
 * The kind of conversion the dashboard reports as the revenue proxy — totals and
 * trends only, never attributed to a specific engine action.
 */
enum ConversionType: string
{
    case Lead = 'lead';
    case Call = 'call';
    case Form = 'form';
    case Conversion = 'conversion';
}
