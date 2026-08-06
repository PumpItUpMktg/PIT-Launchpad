<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A URL-level daily GSC snapshot (retained indefinitely). `position` is Search
 * Console's native impression-weighted blended position for the URL — the
 * per-URL cohort series distinct from the site-wide totals.
 *
 * @property string $grain_hash
 * @property Carbon $date
 * @property string $url
 * @property int $impressions
 * @property int $clicks
 * @property float|null $position
 */
class GscUrlDaily extends Model
{
    use BelongsToSite, HasUlids;

    protected $table = 'gsc_url_daily';

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
