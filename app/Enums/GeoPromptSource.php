<?php

namespace App\Enums;

/**
 * How a GEO prompt entered the set: auto-seeded from the service × market × intent matrix, an assisted
 * weakness top-up (later phase), or hand-written by an operator.
 */
enum GeoPromptSource: string
{
    case Auto = 'auto';
    case Assisted = 'assisted';
    case Manual = 'manual';
}
