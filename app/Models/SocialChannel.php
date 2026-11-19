<?php

namespace App\Models;

use App\Enums\SocialNetwork;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A connected social destination for a site — a GBP location, a Facebook Page, an Instagram account —
 * keyed by the posting provider's channel id. GBP channels belong to a physical {@see Location}.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $location_id
 * @property bool $location_locked the operator assigned the location by hand; the sync keeps it
 * @property Carbon|null $ignored_at set aside by the operator: listed under Ignored, never shared to, restorable
 * @property SocialNetwork $network
 * @property string $provider
 * @property string|null $provider_account the provider profile this channel posts through (a tenant can span several)
 * @property string $provider_channel_id
 * @property string|null $provider_name
 * @property string|null $provider_address
 * @property string|null $provider_place_id
 * @property string|null $label
 * @property bool $enabled
 * @property int|null $max_per_day
 * @property Carbon|null $synced_at
 */
class SocialChannel extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'network' => SocialNetwork::class,
            'enabled' => 'boolean',
            'location_locked' => 'boolean',
            'ignored_at' => 'datetime',
            'max_per_day' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * The channels that take a share: enabled by the provider and not set aside by the operator.
     *
     * @param  Builder<SocialChannel>  $query
     * @return Builder<SocialChannel>
     */
    public function scopeTakesShares(Builder $query): Builder
    {
        return $query->where('enabled', true)->whereNull('ignored_at');
    }

    /** Set aside by the operator — listed under "Ignored", never shared to, restorable. */
    public function ignored(): bool
    {
        return $this->ignored_at !== null;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<SocialPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function displayLabel(): string
    {
        $label = trim((string) $this->label);
        if ($label !== '') {
            return $label;
        }
        $where = $this->location->name ?? $this->provider_name;

        return $this->network->label().($where !== null && $where !== '' ? " · {$where}" : '');
    }

    /** A GBP profile the sync could not tie to one of our locations — it never receives a job share. */
    public function unmatched(): bool
    {
        return $this->network->perLocation() && $this->location_id === null;
    }
}
