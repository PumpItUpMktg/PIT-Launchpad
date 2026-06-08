<?php

namespace App\Enums;

/**
 * The three sample types, sampled at different rates.
 */
enum SampleType: string
{
    case Positions = 'positions';
    case Serp = 'serp';
    case Keywords = 'keywords';
}
