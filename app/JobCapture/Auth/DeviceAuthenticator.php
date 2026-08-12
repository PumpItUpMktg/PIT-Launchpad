<?php

namespace App\JobCapture\Auth;

use App\Models\Scopes\SiteScope;
use App\Models\TechDevice;
use Illuminate\Support\Str;

/**
 * Mints and verifies tech capture-device credentials (§5). The only path that touches the login-code and
 * device-token hashes on {@see TechDevice}. No passwords, no WordPress accounts: a tech proves identity
 * once with a 6-digit code (delivered by magic link / SMS — delivery wired later) and receives a
 * long-lived device token the PWA keeps. Only hashes are stored; comparisons are constant-time.
 */
final class DeviceAuthenticator
{
    /**
     * Issue a fresh 6-digit login code — store only its hash + expiry, return the plaintext for delivery.
     * Replaces any outstanding code (a new request invalidates the old).
     */
    public function issueLoginCode(TechDevice $device): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $device->forceFill([
            'login_code_hash' => $this->hash($code),
            'login_code_expires_at' => now()->addSeconds($this->codeTtlSeconds()),
        ])->save();

        return $code;
    }

    /**
     * Redeem a login code: on a match (correct, unexpired, device active) mint a long-lived device token,
     * store its hash, clear the one-time code, and return the plaintext token for the PWA to keep. Null on
     * any failure — never revealing which condition failed.
     */
    public function redeemLoginCode(TechDevice $device, string $code): ?string
    {
        if (! $device->isActive()
            || $device->login_code_hash === null
            || $device->login_code_expires_at === null
            || $device->login_code_expires_at->isPast()
            || ! hash_equals($device->login_code_hash, $this->hash($code))
        ) {
            return null;
        }

        $token = Str::random(48);

        $device->forceFill([
            'token_hash' => $this->hash($token),
            'login_code_hash' => null,
            'login_code_expires_at' => null,
            'last_active_at' => now(),
        ])->save();

        return $token;
    }

    /**
     * Resolve a device token to its active device, touching `last_active_at`. Drops the SiteScope so auth
     * works before any tenant is bound — the token itself carries the tenant. Null for an empty, unknown,
     * or revoked token.
     */
    public function resolveToken(string $token): ?TechDevice
    {
        if (trim($token) === '') {
            return null;
        }

        $device = TechDevice::withoutGlobalScope(SiteScope::class)
            ->where('token_hash', $this->hash($token))
            ->whereNull('revoked_at')
            ->first();

        $device?->forceFill(['last_active_at' => now()])->save();

        return $device;
    }

    private function codeTtlSeconds(): int
    {
        return (int) config('launchpad.job_capture.login_code_ttl', 600);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
