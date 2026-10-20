<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Google Business Profile as Places reports it (§ Roadmap) — one of the tenant's own offices
 * (`location_id` set) or a map-pack competitor. Refreshed whole on every profile run. Site-scoped.
 *
 * @property string $id
 * @property string $site_id
 * @property string $place_id
 * @property string|null $location_id
 * @property string $name
 * @property string|null $domain
 * @property float|null $rating
 * @property int|null $ratings_total
 * @property list<string>|null $types
 * @property string|null $primary_type
 * @property int|null $photo_count
 * @property string|null $business_status
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $website
 * @property int|null $referring_domains
 * @property int|null $authority
 * @property Carbon $refreshed_at
 */
class GbpProfile extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'ratings_total' => 'integer',
            'types' => 'array',
            'photo_count' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'referring_domains' => 'integer',
            'authority' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }
}
