<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\LocationNapProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one authoritative NAP submission payload for a location (§ Citations). Every work order renders from
 * it, so every submission is byte-identical by construction. Location-scoped (site_id via BelongsToSite +
 * a unique location_id). `phone_primary` is the location's own local number (canonical); `phone_secondary`
 * is a shared/corporate line, acceptable but never primary.
 *
 * @property string $id
 * @property string $site_id
 * @property string $location_id
 * @property string $business_name
 * @property string $address_1
 * @property string|null $address_2
 * @property string $city
 * @property string $state
 * @property string $postal
 * @property string $phone_primary
 * @property string|null $phone_secondary
 * @property string|null $website_url
 * @property string|null $description_short
 * @property string|null $description_long
 * @property string|null $service_area_description
 * @property string|null $logo_url
 * @property string|null $verification_email
 * @property list<mixed>|null $hours
 * @property list<string>|null $categories
 * @property list<string>|null $photo_urls
 * @property array<string, mixed>|null $gbp_synced per-field snapshot of the last GBP value written (override detection)
 */
class LocationNapProfile extends Model
{
    /** @use HasFactory<LocationNapProfileFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'categories' => 'array',
            'photo_urls' => 'array',
            'gbp_synced' => 'array',
        ];
    }
}
