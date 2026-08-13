<?php

namespace App\Models;

use App\JobCapture\Auth\DeviceAuthenticator;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\TechDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tech's capture device (§5) — site-scoped. It authenticates the capture PWA with a long-lived device
 * token (issued after a magic-link / 6-digit-code login), so a tech never has a WordPress account or a
 * password. Only hashes live here; {@see DeviceAuthenticator} is the sole path that
 * mints and verifies them. A device is deactivated by stamping `revoked_at` (tech churn = one revoked
 * token), never deleted.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $token_hash
 * @property string|null $login_code_hash
 * @property Carbon|null $login_code_expires_at
 * @property Carbon|null $last_active_at
 * @property Carbon|null $revoked_at
 */
class TechDevice extends Model
{
    /** @use HasFactory<TechDeviceFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'login_code_expires_at' => 'datetime',
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** A device is usable until it is revoked. */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** The tech's platform user account (role=tech) — the unified identity behind the device.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
