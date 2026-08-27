<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One geo-grid scan header (§ Geo Grid) — a (location × keyword × run) captured at an exact geometry, with
 * the derived aggregates filled by PR 4. Site-scoped. The raw {@see GeoGridPoint}s are the source of truth;
 * every aggregate here is recomputable from them without rescanning.
 *
 * @property string $id
 * @property string $site_id
 * @property string $location_id
 * @property string $keyword_id
 * @property string $provider
 * @property string $mode grid | coverage
 * @property string|null $provider_scan_id
 * @property int $grid_size
 * @property float $spacing_miles
 * @property float $center_lat
 * @property float $center_lng
 * @property int $zoom
 * @property int $depth_cap
 * @property float|null $arp
 * @property float|null $atrp
 * @property float|null $solv
 * @property float|null $found_rate
 * @property float|null $pop_found_rate
 * @property float|null $pop_solv
 * @property string $status
 * @property Carbon|null $scanned_at
 */
class GeoGridScan extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grid_size' => 'integer',
            'spacing_miles' => 'decimal:2',
            'center_lat' => 'decimal:7',
            'center_lng' => 'decimal:7',
            'zoom' => 'integer',
            'depth_cap' => 'integer',
            'arp' => 'decimal:2',
            'atrp' => 'decimal:2',
            'solv' => 'decimal:2',
            'found_rate' => 'decimal:2',
            'pop_found_rate' => 'decimal:2',
            'pop_solv' => 'decimal:2',
            'scanned_at' => 'datetime',
        ];
    }

    /** @return HasMany<GeoGridPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(GeoGridPoint::class, 'scan_id');
    }
}
