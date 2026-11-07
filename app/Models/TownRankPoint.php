<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One town in a town-rank scan (§ Town Rank): the query sent for it, the rank the site's domain holds there
 * (null = not within depth, or not collected yet — see `collected_at`), the URL that ranks, and the top
 * results present so "who outranks us here" is answerable without a rescan.
 *
 * @property string $id
 * @property string $site_id
 * @property string $scan_id
 * @property string|null $coverage_area_id
 * @property string|null $geo_id the town's Census GEOID — its durable identity across a coverage rebuild
 * @property string $label
 * @property string|null $state
 * @property float $lat
 * @property float $lng
 * @property string $query
 * @property int|null $rank
 * @property string|null $ranking_url
 * @property list<array{position: int, url: string, domain: string, title?: string, website_name?: string, breadcrumb?: string, masked?: bool}>|null $top_results
 * @property string|null $provider_task_id
 * @property int $read_attempts reads that produced no answer; at the ceiling the point is closed unreadable
 * @property string|null $read_error why it could never be read — set means "no data", never "not found"
 * @property Carbon|null $collected_at
 */
class TownRankPoint extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /**
     * The point without its results JSON (the bulk of the row): everything the wall needs — rank, state,
     * link — plus a `no_results` flag standing in for "nothing stored", which {@see hasNoResults()} reads.
     *
     * @param  Builder<TownRankPoint>  $query
     * @return Builder<TownRankPoint>
     */
    public function scopeLight(Builder $query): Builder
    {
        $json = DB::connection()->getDriverName() === 'pgsql' ? 'top_results::text' : 'top_results';

        return $query->select([
            'id', 'site_id', 'scan_id', 'coverage_area_id', 'geo_id', 'label', 'state', 'lat', 'lng', 'query',
            'rank', 'ranking_url', 'collected_at', 'read_error', 'read_attempts', 'provider_task_id',
            DB::raw("CASE WHEN top_results IS NULL OR {$json} IN ('[]', 'null') THEN 1 ELSE 0 END AS no_results"),
        ]);
    }

    /** Whether nothing was stored for this point — read from the results themselves, or the light flag. */
    public function hasNoResults(): bool
    {
        if (array_key_exists('no_results', $this->getAttributes())) {
            return (int) $this->getAttributes()['no_results'] === 1;
        }

        return ($this->top_results ?? []) === [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'rank' => 'integer',
            'top_results' => 'array',
            'read_attempts' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TownRankScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(TownRankScan::class, 'scan_id');
    }
}
