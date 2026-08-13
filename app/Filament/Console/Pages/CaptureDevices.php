<?php

namespace App\Filament\Console\Pages;

use App\JobCapture\Auth\DeviceAuthenticator;
use App\Models\Scopes\SiteScope;
use App\Models\TechDevice;
use App\Models\User;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Jobs → Capture Devices: onboard a tech onto the capture PWA (§5). Adding a device creates the
 * {@see TechDevice}, issues a one-time 6-digit login code, and shows the operator the capture link
 * (`/capture?device=…`) plus the code to text the tech — the delivery channel (SMS / magic link) is a
 * later swap; today the operator sends it. Revoke is one click (tech churn = one revoked token). Only the
 * Super-Admin tier reaches this, since it mints device credentials.
 */
class CaptureDevices extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'Capture Devices';

    protected static string|\UnitEnum|null $navigationGroup = 'Jobs';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'capture-devices';

    protected string $view = 'filament.console.capture-devices';

    public string $newName = '';

    public string $newPhone = '';

    /**
     * The just-issued credential to show once (the code is never stored in plaintext, so it can only be
     * displayed at the moment it is minted).
     *
     * @var array{name: string, link: string, code: string}|null
     */
    public ?array $lastIssued = null;

    /** Minting device credentials is Super-Admin-only. */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::ManageUsers);
    }

    /** @return list<array{id: string, name: string, phone: string, active: bool, last_active: ?string}> */
    public function getDevicesProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return TechDevice::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderBy('name')
            ->get()
            ->map(fn (TechDevice $device): array => [
                'id' => (string) $device->id,
                'name' => (string) $device->name,
                'phone' => (string) $device->phone,
                'active' => $device->isActive(),
                'last_active' => $device->last_active_at instanceof Carbon ? $device->last_active_at->diffForHumans() : null,
            ])
            ->all();
    }

    /** Create a tech device for the current site and issue its first login code. */
    public function addDevice(): void
    {
        if (! $this->can(Capability::ManageUsers) || $this->siteId === null) {
            return;
        }

        $name = trim($this->newName);
        if ($name === '') {
            Notification::make()->title('Enter the tech’s name.')->warning()->send();

            return;
        }

        $device = TechDevice::create([
            'site_id' => $this->siteId,
            'name' => $name,
            'phone' => trim($this->newPhone) ?: null,
        ]);

        $this->issue($device, "Added {$name} — send them the link + code.");
        $this->newName = '';
        $this->newPhone = '';
    }

    /** Issue a fresh login code for an existing device (e.g. the code expired before the tech used it). */
    public function reissueCode(string $id): void
    {
        if (! $this->can(Capability::ManageUsers)) {
            return;
        }
        $device = $this->ownedDevice($id);
        if ($device === null) {
            return;
        }

        $this->issue($device, "New code for {$device->name}.");
    }

    /** Revoke a device — its token stops working immediately (tech churn / lost phone). */
    public function revoke(string $id): void
    {
        if (! $this->can(Capability::ManageUsers)) {
            return;
        }
        $device = $this->ownedDevice($id);
        if ($device === null) {
            return;
        }

        $device->forceFill(['revoked_at' => now()])->save();
        Notification::make()->title("Revoked {$device->name}.")->success()->send();
    }

    public function dismissIssued(): void
    {
        $this->lastIssued = null;
    }

    private function issue(TechDevice $device, string $title): void
    {
        $code = app(DeviceAuthenticator::class)->issueLoginCode($device);

        $this->lastIssued = [
            'name' => (string) $device->name,
            'link' => url('/capture').'?device='.$device->id,
            'code' => $code,
        ];

        Notification::make()->title($title)->success()->send();
    }

    /** A device in a site the operator may see. */
    private function ownedDevice(string $id): ?TechDevice
    {
        $device = TechDevice::withoutGlobalScope(SiteScope::class)->whereKey($id)->first();

        if ($device === null || ! $this->user()->canSeeSite((string) $device->site_id)) {
            return null;
        }

        return $device;
    }
}
