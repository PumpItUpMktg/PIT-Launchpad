<?php

namespace App\Enums;

/**
 * The shared control-plane secrets (one set for the whole platform, stored in
 * env / secrets manager — never in the DB). The launch gate checks a one-time
 * "rotated since pilot" attestation per secret; APP_KEY is special because it
 * encrypts every per-tenant secret (rotation procedure in AppKeyRotator).
 */
enum PlatformSecret: string
{
    case AppKey = 'app_key';
    case Database = 'database';
    case R2 = 'r2';
    case FalKey = 'fal_key';
    case AnthropicKey = 'anthropic_key';

    /**
     * Every platform secret must carry a post-pilot rotation attestation before
     * any site may go live.
     *
     * @return list<self>
     */
    public static function requiredForLaunch(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::AppKey => 'Application encryption key (APP_KEY)',
            self::Database => 'Database credentials',
            self::R2 => 'R2 object-storage keys',
            self::FalKey => 'fal.ai API key',
            self::AnthropicKey => 'Anthropic API key',
        };
    }
}
