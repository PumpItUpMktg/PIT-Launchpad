<?php

namespace App\Enums;

/**
 * A target's lifecycle state, an input to sampling tiering.
 */
enum TargetLifecycle: string
{
    case Active = 'active';
    case Stable = 'stable';
    case Parked = 'parked';
}
