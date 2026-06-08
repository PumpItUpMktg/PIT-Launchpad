<?php

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string|null $brand_name
 * @property string|null $logo_url
 * @property string|null $primary_color
 * @property string|null $accent_color
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUlids;

    /**
     * The white-label brand the §7c client panel shows — the agency's, never
     * Launchpad's. Falls back to the account name and brand defaults.
     *
     * @return array{name: string, logo_url: string|null, primary: string, accent: string}
     */
    public function branding(): array
    {
        return [
            'name' => (string) ($this->brand_name ?: $this->name),
            'logo_url' => $this->logo_url,
            'primary' => (string) ($this->primary_color ?: '#0B2545'),
            'accent' => (string) ($this->accent_color ?: '#5BC0EB'),
        ];
    }

    protected $guarded = [];

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['role', 'site_id'])
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
        ];
    }
}
