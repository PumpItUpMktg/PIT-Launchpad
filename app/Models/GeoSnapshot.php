<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One observed AI-search-visibility reading for a {@see GeoPrompt} on one engine at a point in time — the
 * GEO time-series. Sampled and non-deterministic by nature; trend it, don't treat any single row as
 * ground truth.
 *
 * @property string $site_id
 * @property string $geo_prompt_id
 * @property string $engine
 * @property bool $cited
 * @property int|null $position
 * @property string|null $sentiment
 * @property array<int, string>|null $competitors
 * @property string|null $answer_excerpt
 * @property Carbon $checked_at
 */
class GeoSnapshot extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cited' => 'boolean',
            'position' => 'integer',
            'competitors' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GeoPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(GeoPrompt::class, 'geo_prompt_id');
    }
}
