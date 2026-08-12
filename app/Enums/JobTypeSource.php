<?php

namespace App\Enums;

use App\Models\JobType;

/**
 * The provenance of a {@see JobType} (§3). `Silo` types are derived from a Sandhog Works
 * tenant's silo structure (the `silo_id` soft reference records the origin); `Native` types are a
 * standalone tenant's own list, set at onboarding. The distinction drives how the vocabulary is kept in
 * sync — silo types are reconciled against the silo tree, native types are edited directly.
 */
enum JobTypeSource: string
{
    case Silo = 'silo';
    case Native = 'native';

    public function label(): string
    {
        return match ($this) {
            self::Silo => 'From silo',
            self::Native => 'Native',
        };
    }
}
