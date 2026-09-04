<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Security\Capability;
use App\Security\RoleCapabilities;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * @property UserRole $role
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /**
     * Panel access by role: the §7b operator cockpit is operator-only; the §7c
     * client dashboard is client-only. Clients never reach the operator panel
     * and vice-versa.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Platform super-user: god mode — every panel, including the client portal.
        if ($this->isPlatformSuperUser()) {
            return true;
        }

        return match ($panel->getId()) {
            'client' => $this->role === UserRole::Client,
            // The stand-alone Operations Console: the internal Super Admin tier AND a client-side
            // Site Admin. (Existing panels are unchanged — a Site Admin reaches neither.)
            'console' => $this->isSuperAdmin() || $this->isSiteAdmin(),
            default => $this->role->isStaff(), // admin + operator reach the operator panel
        };
    }

    /**
     * The platform owner(s) — a config-driven email allowlist ({@see config('launchpad.super_users')})
     * that gets EVERY capability and EVERY panel regardless of the stored role, so god-mode access
     * survives any permission change.
     */
    public function isPlatformSuperUser(): bool
    {
        $email = strtolower(trim((string) $this->email));

        return $email !== '' && in_array($email, (array) config('launchpad.super_users', []), true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** The internal Super Admin tier (full authority incl. backend corrections). */
    public function isSuperAdmin(): bool
    {
        return $this->role->isSuperAdmin();
    }

    /** A client-side Site Admin (the limited operating role). */
    public function isSiteAdmin(): bool
    {
        return $this->role === UserRole::SiteAdmin;
    }

    /** Whether this user's role holds a given operational capability ({@see RoleCapabilities}). */
    public function hasCapability(Capability $capability): bool
    {
        return $this->isPlatformSuperUser() || RoleCapabilities::allows($this->role, $capability);
    }

    /**
     * The site ids this user may see, or NULL for unrestricted (all sites). Admin is always
     * unrestricted; an operator is unrestricted UNTIL they carry membership rows (back-compat —
     * "operators seeded manually", so no memberships means the pre-gating behavior), then limited to:
     *  - every site named directly by a per-site membership (`site_id` set), plus
     *  - every site under an account granted account-wide (a membership with `site_id` null).
     *
     * @return list<string>|null
     */
    public function permittedSiteIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        $memberships = $this->memberships()->get(['account_id', 'site_id']);
        if ($memberships->isEmpty()) {
            // Back-compat: manually-seeded operators with no memberships stay unrestricted. A Site Admin
            // is ALWAYS scoped — no memberships means no sites, never the whole portfolio.
            return $this->role === UserRole::SiteAdmin ? [] : null;
        }

        $siteIds = $memberships->pluck('site_id')->filter()->values();

        $accountWide = $memberships->whereNull('site_id')->pluck('account_id')->filter()->values();
        if ($accountWide->isNotEmpty()) {
            $siteIds = $siteIds->merge(
                Site::query()->whereIn('account_id', $accountWide->all())->pluck('id'),
            );
        }

        return $siteIds->unique()->values()->all();
    }

    /** Whether this user may see a given site (or its id). */
    public function canSeeSite(Site|string|null $site): bool
    {
        if ($site === null) {
            return false;
        }
        $permitted = $this->permittedSiteIds();
        if ($permitted === null) {
            return true; // unrestricted
        }

        return in_array($site instanceof Site ? $site->id : $site, $permitted, true);
    }

    /**
     * Filament tenancy adapter (2b): the operator "belongs to" the Sites they may see, so Filament's
     * routing can scope the panel to /admin/{tenant}. This is a THIN adapter over the existing
     * visibility model — {@see ActiveTenant} + {@see CurrentSite} remain the source of truth for the
     * locked working tenant; Filament's tenant is only the URL segment, verified against the lock.
     *
     * @return Collection<int, Site>
     */
    public function getTenants(Panel $panel): Collection
    {
        $permitted = $this->permittedSiteIds();

        return $permitted === null
            ? Site::query()->get()
            : Site::query()->whereIn('id', $permitted)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Site && $this->canSeeSite($tenant);
    }

    /**
     * The accounts this user belongs to (via memberships).
     *
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'memberships')
            ->withPivot(['role', 'site_id'])
            ->withTimestamps();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }
}
