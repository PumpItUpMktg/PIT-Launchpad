<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The provider-agnostic metric spine that the client dashboard reads from (§ Client Dashboard v1).
 * One row = one (provider, metric_key, dimension, period_grain, period_date) fact. Writes are idempotent
 * upserts on the grain unique key, so backfill and daily re-pulls are safe to repeat.
 *
 * The dashboard reads ONLY from this table — never live from a provider.
 *
 * @property string $site_id
 * @property string $provider
 * @property string $metric_key
 * @property string $dimension_type
 * @property string $dimension_value
 * @property string $period_grain
 * @property Carbon $period_date
 * @property float|null $value_numeric
 * @property array<string, mixed>|null $value_json
 * @property Carbon $captured_at
 */
class MetricSnapshot extends Model
{
    use BelongsToSite, HasUlids;

    /** The columns that uniquely identify a grain — the upsert key. */
    public const GRAIN_KEYS = [
        'site_id', 'provider', 'metric_key', 'dimension_type', 'dimension_value', 'period_grain', 'period_date',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'value_numeric' => 'decimal:4',
            'value_json' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
