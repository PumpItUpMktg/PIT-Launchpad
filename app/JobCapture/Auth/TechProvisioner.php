<?php

namespace App\JobCapture\Auth;

use App\Enums\UserRole;
use App\Models\TechDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Onboards a tech as a first-class platform account (§5): a User (role=tech, no panel access, no console
 * capabilities) plus a capture {@see TechDevice} bound to it, and the first login code. The User is the
 * unified identity — a Job Capture → Launchpad upgrade is then a role change, not a new account — while the
 * device token stays the tech's only sign-in (they never use email/password). Reused by the Capture Devices
 * screen and the Users & access hub.
 */
final class TechProvisioner
{
    public function __construct(private readonly DeviceAuthenticator $authenticator) {}

    /**
     * @return array{device: TechDevice, code: string, link: string}
     */
    public function provision(string $siteId, string $name, ?string $phone = null, ?string $email = null): array
    {
        $normalizedEmail = $email !== null && trim($email) !== '' ? strtolower(trim($email)) : null;
        $normalizedPhone = $phone !== null && trim($phone) !== '' ? trim($phone) : null;

        $user = User::create([
            'name' => $name,
            'email' => $this->accountEmail($normalizedEmail),
            'role' => UserRole::Tech,
            'password' => Hash::make(Str::password(32)),
        ]);

        $device = TechDevice::create([
            'site_id' => $siteId,
            'user_id' => $user->id,
            'name' => $name,
            'phone' => $normalizedPhone,
            'email' => $normalizedEmail,
        ]);

        return [
            'device' => $device,
            'code' => $this->authenticator->issueLoginCode($device),
            'link' => url('/capture').'?device='.$device->id,
        ];
    }

    /**
     * A real, unused email if one was supplied; otherwise a unique non-deliverable device address. Techs
     * sign in with the device token, so the address only has to be unique — an operator can set a real one
     * on upgrade.
     */
    private function accountEmail(?string $email): string
    {
        if ($email !== null && ! User::query()->where('email', $email)->exists()) {
            return $email;
        }

        return 'tech-'.strtolower((string) Str::ulid()).'@device.local';
    }
}
