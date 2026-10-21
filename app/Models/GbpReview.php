<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Google review on one of the tenant's own listings, as Places reported it on the last profile refresh —
 * shown verbatim on the office's location page under Google's attribution. Replaced whole per office on
 * every refresh. Site-scoped.
 *
 * @property string $id
 * @property string $site_id
 * @property string $location_id
 * @property string $place_id
 * @property string $author_name
 * @property string|null $author_url
 * @property int $rating
 * @property string $text
 * @property string|null $relative_time
 * @property Carbon|null $reviewed_at
 * @property Carbon $refreshed_at
 */
class GbpReview extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'reviewed_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
