<?php

namespace App\Filament\Console\Pages;

use App\Enums\UserRole;
use App\JobCapture\Auth\TechProvisioner;
use App\JobCapture\Auth\TechRoster;
use App\Models\Membership;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TechDevice;
use App\Models\User;
use App\OpsConsole\ConsoleContext;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Console → Administration → Users & access: the Super-Admin-only surface for creating users and
 * setting who can do what — assign the internal Super Admin tier vs a client-side Site Admin, and tie a
 * Site Admin to the site(s) they operate (a {@see Membership}). Role changes flow through the existing
 * §9 audit hook (User::updated in AppServiceProvider), so every change is recorded. Gated on
 * {@see Capability::ManageUsers}, which only the Super Admin tier holds.
 */
class UsersAccess extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Users & access';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'users';

    protected string $view = 'filament.console.users';

    // New-user form.
    public string $newName = '';

    public string $newEmail = '';

    public string $newRole = 'site_admin';

    public ?string $newSiteId = null;

    /**
     * The just-issued capture credential to show once after provisioning a tech (the code is never stored
     * in plaintext, so it can only be displayed at the moment it is minted).
     *
     * @var array{name: string, site: string, link: string, code: string}|null
     */
    public ?array $lastIssued = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::ManageUsers);
    }

    public function getTitle(): string
    {
        return 'Users & access';
    }

    /** @return list<array{id: string, name: string, email: string, role: string, tier: string, sites: list<string>}> */
    public function getUsersProperty(): array
    {
        // Techs are managed as devices in the roster below — one row per device, grouped by client.
        return User::query()->where('role', '!=', UserRole::Tech->value)->with('memberships.site')->orderBy('name')->get()
            ->map(fn (User $u): array => [
                'id' => (string) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'role' => $u->role->value,
                'tier' => $u->role->label(),
                'sites' => $u->memberships
                    ->filter(fn (Membership $m): bool => $m->site_id !== null)
                    ->map(fn (Membership $m): string => trim((string) $m->site->brand_name))
                    ->filter(fn (string $n): bool => $n !== '')
                    ->unique()->values()->all(),
            ])
            ->all();
    }

    /** @return list<array{site_id: string, site: string, techs: list<array{id: string, name: string, phone: string, email: string, status: string, status_label: string, last_active: ?string, site_id: string}>}> */
    public function getTechsProperty(): array
    {
        return app(TechRoster::class)->forUser($this->user());
    }

    /** @return array<string, string> assignable roles (label => value not needed; value => label). */
    public function getRoleOptionsProperty(): array
    {
        return [
            UserRole::Operator->value => 'Super Admin',
            UserRole::SiteAdmin->value => 'Site Admin',
            UserRole::Client->value => 'Client',
            UserRole::Tech->value => 'Tech (capture device)',
        ];
    }

    /** @return array<string, string> id => name for the site picker. */
    public function getSiteOptionsProperty(): array
    {
        return app(ConsoleContext::class)->options($this->user());
    }

    public function createUser(): void
    {
        if (! $this->can(Capability::ManageUsers)) {
            return;
        }

        $name = trim($this->newName);
        $email = mb_strtolower(trim($this->newEmail));
        $role = UserRole::tryFrom($this->newRole);

        if ($role === null || $name === '') {
            Notification::make()->title('Enter a name and pick a role.')->danger()->send();

            return;
        }

        // A tech signs in only via a device token, so no email/password is required — provisioning mints
        // the role=tech User, its site-scoped capture device, and the first login code to hand over.
        if ($role === UserRole::Tech) {
            $this->createTech($name, $email);

            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Notification::make()->title('Enter a valid email.')->danger()->send();

            return;
        }
        if (User::query()->where('email', $email)->exists()) {
            Notification::make()->title('That email already has an account.')->danger()->send();

            return;
        }

        $tempPassword = Str::password(16);
        $user = User::create(['name' => $name, 'email' => $email, 'role' => $role, 'password' => Hash::make($tempPassword)]);

        if ($role === UserRole::SiteAdmin && $this->newSiteId !== null) {
            $this->assignSite($user->id, $this->newSiteId);
        }

        $this->newName = '';
        $this->newEmail = '';
        $this->newSiteId = null;

        Notification::make()
            ->title('User created')
            ->body("Temporary password for {$email}: {$tempPassword}")
            ->success()->persistent()->send();
    }

    /** Provision a tech (User + capture device + login code) and surface the capture link + code once. */
    private function createTech(string $name, string $email): void
    {
        if ($this->newSiteId === null) {
            Notification::make()->title('Pick the site the tech captures for.')->danger()->send();

            return;
        }

        $result = app(TechProvisioner::class)->provision($this->newSiteId, $name, null, $email !== '' ? $email : null);

        $this->lastIssued = [
            'name' => $name,
            'site' => $this->siteOptions[$this->newSiteId] ?? 'this site',
            'link' => $result['link'],
            'code' => $result['code'],
        ];

        $this->newName = '';
        $this->newEmail = '';
        $this->newSiteId = null;

        if ($result['delivered']) {
            Notification::make()->title("Provisioned {$name} — emailed the capture link + code to {$email}.")->success()->send();
        } else {
            Notification::make()->title("Provisioned {$name} — send them the capture link + code below.")->warning()->send();
        }
    }

    public function dismissIssued(): void
    {
        $this->lastIssued = null;
    }

    public function setRole(string $userId, string $roleValue): void
    {
        if (! $this->can(Capability::ManageUsers)) {
            return;
        }
        $role = UserRole::tryFrom($roleValue);
        $user = User::query()->find($userId);
        if ($role === null || $user === null) {
            return;
        }

        $user->forceFill(['role' => $role])->save(); // audited by the §9 User::updated hook

        Notification::make()->title("Role set to {$role->label()}.")->success()->send();
    }

    public function assignSite(string $userId, string $siteId): void
    {
        if (! $this->can(Capability::ManageUsers)) {
            return;
        }
        $user = User::query()->find($userId);
        $site = Site::query()->find($siteId);
        if ($user === null || $site === null) {
            return;
        }

        Membership::query()->firstOrCreate(
            ['user_id' => $user->id, 'account_id' => $site->account_id, 'site_id' => $site->id],
            ['role' => $user->role->value],
        );

        Notification::make()->title('Site assigned.')->success()->send();
    }

    public function revokeSite(string $userId, string $siteId): void
    {
        if (! $this->can(Capability::ManageUsers)) {
            return;
        }
        Membership::query()->where('user_id', $userId)->where('site_id', $siteId)->delete();

        Notification::make()->title('Site access revoked.')->send();
    }

    // ---- Technicians: the whole device lifecycle from this one page ----

    /** A fresh code for a live device (invite re-sent when an email is on file). */
    public function refreshTech(string $id): void
    {
        $device = $this->ownedDevice($id);
        if ($device === null) {
            return;
        }
        $result = app(TechRoster::class)->refresh($device);
        if ($result === null) {
            Notification::make()->title("{$device->name} is inactive — reactivate instead.")->warning()->send();

            return;
        }
        $this->showIssued($device, $result);
    }

    public function revokeTech(string $id): void
    {
        $device = $this->ownedDevice($id);
        if ($device === null) {
            return;
        }
        app(TechRoster::class)->revoke($device);
        Notification::make()->title("Revoked {$device->name} — their phone stops working now.")->success()->send();
    }

    public function reactivateTech(string $id): void
    {
        $device = $this->ownedDevice($id);
        if ($device === null) {
            return;
        }
        $this->showIssued($device, app(TechRoster::class)->reactivate($device));
    }

    /** Delete an inactive tech (device + account). Refused for a live one. */
    public function removeTech(string $id): void
    {
        $device = $this->ownedDevice($id);
        if ($device === null) {
            return;
        }
        if (! app(TechRoster::class)->remove($device, $this->user())) {
            Notification::make()->title("{$device->name} is still active — revoke first, then remove.")->danger()->send();

            return;
        }
        Notification::make()->title("Removed {$device->name}.")->success()->send();
    }

    /** Rebind a tech's device to another client. */
    public function moveTech(string $id, string $siteId): void
    {
        $device = $this->ownedDevice($id);
        $to = Site::query()->find($siteId);
        if ($device === null || $to === null) {
            return;
        }
        if (! app(TechRoster::class)->move($device, $to, $this->user())) {
            Notification::make()->title('That move is not allowed.')->danger()->send();

            return;
        }
        Notification::make()->title("Moved {$device->name} to {$to->brand_name} — their phone picks it up on the next sync.")->success()->send();
    }

    /** @param  array{code: string, link: string, delivered: bool}  $result */
    private function showIssued(TechDevice $device, array $result): void
    {
        $siteName = Site::query()->find((string) $device->site_id)?->brand_name;
        $this->lastIssued = [
            'name' => (string) $device->name,
            'site' => trim((string) $siteName) !== '' ? (string) $siteName : 'this site',
            'link' => $result['link'],
            'code' => $result['code'],
        ];
        if ($result['delivered'] && filled($device->email)) {
            Notification::make()->title("Emailed {$device->name}'s capture link + code to {$device->email}.")->success()->send();
        } else {
            Notification::make()->title("Send {$device->name} the link + code below — no email on file.")->warning()->send();
        }
    }

    /** A device on a client the signed-in user may see; null otherwise (or without the capability). */
    private function ownedDevice(string $id): ?TechDevice
    {
        if (! $this->can(Capability::ManageUsers)) {
            return null;
        }
        $device = TechDevice::withoutGlobalScope(SiteScope::class)->whereKey($id)->first();

        return $device !== null && $this->user()->canSeeSite((string) $device->site_id) ? $device : null;
    }
}
