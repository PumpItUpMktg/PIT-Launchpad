<?php

namespace App\Enums;

/**
 * The near-duplicate decision: very-high overlap with a live page → refresh it,
 * don't duplicate; moderate → flag the operator (merge/distinct); low → proceed.
 */
enum NearDupTier: string
{
    case Refresh = 'refresh';
    case OperatorFlag = 'operator_flag';
    case Proceed = 'proceed';
}
