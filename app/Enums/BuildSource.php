<?php

namespace App\Enums;

/**
 * Where a build-manifest page comes from: the standard scaffold, the finalized service
 * structure, or the location (town) layer.
 */
enum BuildSource: string
{
    case Standard = 'standard';
    case Service = 'service';
    case Location = 'location';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Service => 'Service',
            self::Location => 'Location',
        };
    }
}
