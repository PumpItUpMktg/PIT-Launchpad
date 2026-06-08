<?php

namespace App\Models;

use App\Enums\PlatformSecret;
use Database\Factories\PlatformSecretRotationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post-pilot rotation attestation for a shared platform secret. Platform
 * secrets are not per-tenant, so this is a global (un-scoped) record — one row
 * per secret, satisfying the launch gate for every tenant once rotated.
 *
 * @property PlatformSecret $platform_secret
 */
class PlatformSecretRotation extends Model
{
    /** @use HasFactory<PlatformSecretRotationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function rotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rotated_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform_secret' => PlatformSecret::class,
            'rotated_at' => 'datetime',
        ];
    }
}
