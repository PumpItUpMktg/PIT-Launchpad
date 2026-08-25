<?php

namespace App\Models;

use App\Enums\GeoCheckAction;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a GEO check run — the activity-log row the operator watches (see {@see GeoCheckAction}).
 * Append-only; a run stamps a shared `run_id` across its rows for per-run history, and old rows are pruned
 * on a retention window.
 *
 * @property string $site_id
 * @property string $run_id
 * @property string|null $geo_prompt_id
 * @property string|null $engine
 * @property GeoCheckAction $action
 * @property bool|null $cited
 * @property list<string>|null $competitors
 * @property string|null $town
 * @property Carbon $created_at
 */
class GeoCheckEvent extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => GeoCheckAction::class,
            'cited' => 'boolean',
            'competitors' => 'array',
        ];
    }

    /** @return BelongsTo<GeoPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(GeoPrompt::class, 'geo_prompt_id');
    }
}
