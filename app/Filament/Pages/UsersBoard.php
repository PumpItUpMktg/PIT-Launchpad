<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\Access\TenantUsers;
use App\Operator\ActiveTenant;
use App\Security\Capability;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Users (operator) — **who can access the LOCKED tenant, and at what role**. It is memberships-for-this-
 * site, never a global user list: enumerating every user on a tenant-locked surface would leak other
 * tenants' membership (the shape the tenant-lock remediation removed). So it shows only the grants that
 * reach this site (site-level + account-wide on this account) and lets the operator grant / revoke access
 * against the locked tenant — no site picker.
 *
 * Tenant-locked: the working tenant is {@see ActiveTenant}; every write resolves the lock, never a passed
 * site id, so a grant/revoke can only ever touch this tenant. Operator-only ({@see canAccess}); every
 * mutation additionally re-checks {@see Capability::ManageUsers} (the §9 discipline), and role changes flow
 * through the §9 `User::updated` audit hook.
 *
 * @property-read array<string, mixed>|null $board
 */
class UsersBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Users';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $slug = 'users';

    protected string $view = 'filament.pages.users-board';

    public ?string $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Users';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array<string, mixed>|null */
    public function getBoardProperty(): ?array
    {
        return app(TenantUsers::class)->for($this->siteId);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantAccess')
                ->label('Grant access')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => $this->siteId !== null)
                ->schema([
                    TextInput::make('name')->label('Name')->required(),
                    TextInput::make('email')->label('Email')->email()->required()
                        ->helperText('An existing user with this email is granted access to this tenant; a new one is created.'),
                    Select::make('role')->label('Role')->options($this->roleOptions())->default(UserRole::SiteAdmin->value)->required(),
                ])
                ->action(fn (array $data) => $this->grant((string) $data['name'], (string) $data['email'], (string) $data['role'])),
        ];
    }

    /** @return array<string, string> the per-tenant grantable roles (value => label). */
    private function roleOptions(): array
    {
        return [
            UserRole::SiteAdmin->value => UserRole::SiteAdmin->label(),
            UserRole::Client->value => UserRole::Client->label(),
        ];
    }

    /**
     * Grant a user access to the LOCKED tenant. An existing user is attached (their role is left alone —
     * it may span other tenants); a new user is created with the chosen role. Never touches another tenant.
     */
    public function grant(string $name, string $email, string $roleValue): void
    {
        if (! $this->canManage()) {
            return;
        }
        $site = $this->lockedSite();
        if ($site === null) {
            return;
        }

        $role = UserRole::tryFrom($roleValue);
        if ($role === null || ! array_key_exists($role->value, $this->roleOptions())) {
            Notification::make()->title('Pick a role (Site Admin or Client).')->danger()->send();

            return;
        }

        $email = mb_strtolower(trim($email));
        $name = trim($name);

        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            $this->attach($user, $site);
            Notification::make()->title("Granted {$user->name} access to this tenant.")->success()->send();

            return;
        }

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Notification::make()->title('Enter a name and a valid email.')->danger()->send();

            return;
        }

        $tempPassword = Str::password(16);
        $user = User::create(['name' => $name, 'email' => $email, 'role' => $role, 'password' => Hash::make($tempPassword)]);
        $this->attach($user, $site);

        Notification::make()
            ->title('User created and granted access')
            ->body("Temporary password for {$email}: {$tempPassword}")
            ->success()->persistent()->send();
    }

    /**
     * Change a user's role from this tenant surface — allowed only for a single-tenant user granted THIS
     * site at site level, so a global-role change can never reach across tenants. A multi-tenant user is
     * deflected to the Console (which sees the whole user).
     */
    public function setRole(string $userId, string $roleValue): void
    {
        if (! $this->canManage()) {
            return;
        }
        $site = $this->lockedSite();
        if ($site === null) {
            return;
        }

        $role = UserRole::tryFrom($roleValue);
        if ($role === null || ! array_key_exists($role->value, $this->roleOptions())) {
            return;
        }

        // Must be a site-level member of THIS tenant, and of no other tenant.
        $isMember = Membership::query()->where('user_id', $userId)->where('site_id', $site->id)->exists();
        if (! $isMember) {
            return;
        }
        if (Membership::query()->where('user_id', $userId)->count() !== 1) {
            Notification::make()->title('This user belongs to more than one tenant — change their role in Console.')->warning()->send();

            return;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }

        $user->forceFill(['role' => $role])->save(); // audited by the §9 User::updated hook

        Notification::make()->title("Role set to {$role->label()}.")->success()->send();
    }

    /** Revoke a user's access to the LOCKED tenant — the site-level grant only (account-wide is managed at account level). */
    public function revoke(string $userId): void
    {
        if (! $this->canManage()) {
            return;
        }
        $site = $this->lockedSite();
        if ($site === null) {
            return;
        }

        Membership::query()->where('user_id', $userId)->where('site_id', $site->id)->delete();

        Notification::make()->title('Access revoked.')->send();
    }

    private function attach(User $user, Site $site): void
    {
        Membership::query()->firstOrCreate(
            ['user_id' => $user->id, 'account_id' => $site->account_id, 'site_id' => $site->id],
            ['role' => $user->role->value],
        );
    }

    private function canManage(): bool
    {
        return Auth::user()?->hasCapability(Capability::ManageUsers) ?? false;
    }

    /** Resolve the locked tenant as a writable model — never a passed id. */
    private function lockedSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }
}
