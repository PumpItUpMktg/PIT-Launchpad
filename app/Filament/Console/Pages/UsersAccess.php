<?php

namespace App\Filament\Console\Pages;

use App\Enums\UserRole;
use App\JobCapture\Auth\TechProvisioner;
use App\Models\Membership;
use App\Models\Site;
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
     * @var array{name: string, link: string, code: string}|null
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
        return User::query()->with('memberships.site')->orderBy('name')->get()
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
            'link' => $result['link'],
            'code' => $result['code'],
        ];

        $this->newName = '';
        $this->newEmail = '';
        $this->newSiteId = null;

        Notification::make()->title("Provisioned {$name} — send them the capture link + code.")->success()->send();
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
}
