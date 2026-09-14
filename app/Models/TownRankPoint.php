<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One town in a town-rank scan (§ Town Rank): the query sent for it, the rank the site's domain holds there
 * (null = not within depth, or not collected yet — see `collected_at`), the URL that ranks, and the top
 * results present so "who outranks us here" is answerable without a rescan.
 *
 * @property string $id
 * @property string $site_id
 * @property string $scan_id
 * @property string|null $coverage_area_id
 * @property string $label
 * @property string|null $state
 * @property float $lat
 * @property float $lng
 * @property string $query
 * @property int|null $rank
 * @property string|null $ranking_url
 * @property list<array{position: int, url: string, domain: string}>|null $top_results
 * @property string|null $provider_task_id
 * @property Carbon|null $collected_at
 */
class TownRankPoint extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'rank' => 'integer',
            'top_results' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TownRankScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(TownRankScan::class, 'scan_id');
    }
}
