<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property UserRole $role
 */
class User extends Authenticatable implements FilamentUser
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
        return match ($panel->getId()) {
            'client' => $this->role === UserRole::Client,
            default => $this->role->isStaff(), // admin + operator reach the operator panel
        };
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
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
            return null; // unrestricted until membership is seeded
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
