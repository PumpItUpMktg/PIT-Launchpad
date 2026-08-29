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
 * @property list<mixed>|null $hours
 * @property list<string>|null $categories
 * @property list<string>|null $photo_urls
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
        ];
    }
}
