<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The long-term monthly rollup of {@see GscUrlQueryDaily}. `position` is the
 * impression-weighted monthly average; `days_present` records how many daily
 * rows rolled in. Retained indefinitely for the distinct-query and banded
 * top-3/10/20 trends after the daily detail is pruned.
 *
 * @property string $grain_hash
 * @property Carbon $month
 * @property string $url
 * @property string $query
 * @property string $country
 * @property string $device
 * @property int $impressions
 * @property int $clicks
 * @property float|null $position
 * @property int $days_present
 */
class GscUrlQueryMonthly extends Model
{
    use BelongsToSite, HasUlids;

    protected $table = 'gsc_url_query_monthly';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'position' => 'decimal:2',
            'days_present' => 'integer',
        ];
    }
}
