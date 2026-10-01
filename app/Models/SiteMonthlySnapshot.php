<?php

namespace App\Models;

use App\Activity\MonthlySnapshots;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One closed month of a site's activity, frozen (§ Activity): the headline counts of work done and the
 * metric movement over the month, written once by {@see MonthlySnapshots} and never
 * recomputed — the long-term progress record that cannot drift.
 *
 * @property string $id
 * @property string $site_id
 * @property Carbon $month
 * @property array<string, int> $counts
 * @property array<string, array{label: string, start: int|float|null, end: int|float|null, delta: int|float|null, kind: string}> $metrics
 * @property int $timeline_entries
 * @property Carbon $closed_at
 */
class SiteMonthlySnapshot extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'counts' => 'array',
            'metrics' => 'array',
            'closed_at' => 'datetime',
        ];
    }
}
