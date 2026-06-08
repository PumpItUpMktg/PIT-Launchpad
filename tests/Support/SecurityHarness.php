<?php

namespace Tests\Support;

use App\Enums\PlatformSecret;
use App\Models\PlatformSecretRotation;

class SecurityHarness
{
    /**
     * Record a post-pilot attestation for every platform secret, so the launch
     * gate's platform checks all pass and a test can isolate the connection side.
     */
    public static function attestAllPlatformSecrets(): void
    {
        foreach (PlatformSecret::cases() as $secret) {
            PlatformSecretRotation::factory()->secret($secret)->create();
        }
    }
}
