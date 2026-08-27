<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measured grid cell (§ Geo Grid) — the business's rank at (row, col) for its scan, plus the top few
 * competitors present at that point. `rank` null = it did not appear within the scan's depth_cap. Site-scoped
 * (tenant isolation on every model). The raw source of truth aggregates derive from.
 *
 * @property string $id
 * @property string $site_id
 * @property string $scan_id
 * @property int $row
 * @property int $col
 * @property float $lat
 * @property float $lng
 * @property int|null $rank
 * @property list<array{name: string, place_id: string|null, rank: int|null}>|null $competitors
 * @property string|null $provider_task_id
 */
class GeoGridPoint extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row' => 'integer',
            'col' => 'integer',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'rank' => 'integer',
            'competitors' => 'array',
        ];
    }

    /** @return BelongsTo<GeoGridScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(GeoGridScan::class, 'scan_id');
    }
}
