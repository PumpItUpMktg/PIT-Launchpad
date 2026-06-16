<?php

namespace App\Interview;

use App\Models\SiloBlueprint;
use App\Models\VoiceProfile;

/**
 * What an owner interview wrote: the SiloBlueprint carrying the seed snapshot and the
 * newly activated VoiceProfile version.
 */
final class PersistResult
{
    public function __construct(
        public readonly SiloBlueprint $blueprint,
        public readonly VoiceProfile $voice,
    ) {}
}
