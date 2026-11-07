<?php

namespace App\Models;

use App\Enums\SocialNetwork;
use App\Models\Concerns\BelongsToSite;
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
 * @property SocialNetwork $network
 * @property string $provider
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
            'max_per_day' => 'integer',
            'synced_at' => 'datetime',
        ];
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
