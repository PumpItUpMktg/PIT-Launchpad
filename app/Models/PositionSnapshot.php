<?php

namespace App\Models;

use App\Enums\BeatabilityLane;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\PositionSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-series position sample: one organic series per keyword + per-market
 * local-pack series (carrying market_id). Distinct from the content-level
 * SerpSnapshot used for the refresh trigger.
 */
class PositionSnapshot extends Model
{
    /** @use HasFactory<PositionSnapshotFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lane' => BeatabilityLane::class,
            'rank' => 'integer',
            'serp_features' => 'array',
            'avg_rank' => 'decimal:2',
            'pct_top3' => 'decimal:4',
            'coverage' => 'decimal:4',
            'captured_at' => 'datetime',
        ];
    }
}
