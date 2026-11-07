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
 * @property int|null $pack_rank the business's slot in the local pack (1–3), null = not in it (or no pack / not measured)
 * @property list<array{position: int, title: string, domain: string, url: string, rating: float|null, votes: int|null}>|null $pack_results the pack cards the page showed; null = not measured (a scan before the pack lane), [] = no pack on that page
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

        $pack = DB::connection()->getDriverName() === 'pgsql' ? 'pack_results::text' : 'pack_results';

        return $query->select([
            'id', 'site_id', 'scan_id', 'coverage_area_id', 'geo_id', 'label', 'state', 'lat', 'lng', 'query',
            'rank', 'ranking_url', 'pack_rank', 'collected_at', 'read_error', 'read_attempts', 'provider_task_id',
            DB::raw("CASE WHEN top_results IS NULL OR {$json} IN ('[]', 'null') THEN 1 ELSE 0 END AS no_results"),
            DB::raw("CASE WHEN pack_results IS NULL THEN NULL WHEN {$pack} IN ('[]', 'null') THEN 0 ELSE 1 END AS pack_shown"),
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

    /**
     * Whether the page this point read showed a local pack: true / false, or null when the point was
     * collected before the pack lane existed (not measured). Reads the light flag or the stored cards.
     */
    public function packShown(): ?bool
    {
        if (array_key_exists('pack_shown', $this->getAttributes())) {
            $flag = $this->getAttributes()['pack_shown'];

            return $flag === null ? null : (int) $flag === 1;
        }
        if ($this->pack_results === null) {
            return null;
        }

        return $this->pack_results !== [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'rank' => 'integer',
            'top_results' => 'array',
            'pack_rank' => 'integer',
            'pack_results' => 'array',
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
