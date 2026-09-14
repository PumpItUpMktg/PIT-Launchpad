<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One town-rank scan header (§ Town Rank) — a (site × keyword × mode × run) over the site's covered towns.
 * `mode` is `local` (the bare query searched FROM each town's centroid — what a resident sees) or
 * `town_query` ("{query} {town} {ST}" searched nationally — whether the town page is the one Google serves
 * for the explicit town search). Site-scoped. The {@see TownRankPoint}s are the source of truth.
 *
 * @property string $id
 * @property string $site_id
 * @property string $keyword_id
 * @property string $mode
 * @property string $provider
 * @property string $status pending | complete | partial
 * @property int $points_count
 * @property int $found_count
 * @property Carbon|null $scanned_at
 */
class TownRankScan extends Model
{
    use BelongsToSite, HasUlids;

    public const MODE_LOCAL = 'local';

    public const MODE_TOWN_QUERY = 'town_query';

    /** @var list<string> */
    public const MODES = [self::MODE_LOCAL, self::MODE_TOWN_QUERY];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'points_count' => 'integer',
            'found_count' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    /** @return HasMany<TownRankPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(TownRankPoint::class, 'scan_id');
    }
}
