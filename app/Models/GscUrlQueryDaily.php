<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A URL×query daily GSC snapshot at full grain (site, date, url, query,
 * country, device). Kept full-grain for the recent window, then rolled into
 * {@see GscUrlQueryMonthly} and pruned. `position` is the honest per-query
 * rank, not a blend.
 *
 * @property string $grain_hash
 * @property Carbon $date
 * @property string $url
 * @property string $query
 * @property string $country
 * @property string $device
 * @property int $impressions
 * @property int $clicks
 * @property float|null $position
 */
class GscUrlQueryDaily extends Model
{
    use BelongsToSite, HasUlids;

    protected $table = 'gsc_url_query_daily';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'ctr' => 'decimal:4',
            'position' => 'decimal:2',
        ];
    }
}
