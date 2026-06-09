<?php

namespace App\Models;

use App\Enums\ConversionSource;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The conversion ingest cursor for one (site × source): when that source was last
 * pulled, so each run fetches incrementally rather than re-pulling history.
 *
 * @property ConversionSource $source
 * @property Carbon|null $last_synced_at
 */
class ConversionSyncState extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ConversionSource::class,
            'last_synced_at' => 'datetime',
        ];
    }
}
