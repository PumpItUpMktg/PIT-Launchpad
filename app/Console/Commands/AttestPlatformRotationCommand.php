<?php

namespace App\Console\Commands;

use App\Enums\PlatformSecret;
use App\Models\PlatformSecretRotation;
use Illuminate\Console\Command;

/**
 * Records the post-pilot rotation attestation for a shared platform secret. The
 * operator performs the actual env swap (DB/R2/FAL/Anthropic/APP_KEY); this
 * writes the one-per-secret attestation the launch gate checks.
 */
class AttestPlatformRotationCommand extends Command
{
    protected $signature = 'launchpad:attest-platform-rotation
        {secret : One of app_key|database|r2|fal_key|anthropic_key}';

    protected $description = 'Record that a platform secret has been rotated since the pilot.';

    public function handle(): int
    {
        $secret = PlatformSecret::tryFrom((string) $this->argument('secret'));
        if ($secret === null) {
            $this->error('Unknown platform secret. Expected one of: '
                .implode(', ', array_map(fn (PlatformSecret $s) => $s->value, PlatformSecret::cases())));

            return self::FAILURE;
        }

        PlatformSecretRotation::updateOrCreate(
            ['platform_secret' => $secret->value],
            ['rotated_at' => now()],
        );

        $this->info("Recorded rotation attestation for {$secret->label()}.");

        return self::SUCCESS;
    }
}
