<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\CitationScanRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One citation scan of one location (§ Citations, PR4): when it ran, the coverage snapshot, and the diff
 * buckets versus the prior run. Location-scoped.
 *
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class CitationScanRun extends Model
{
    /** @use HasFactory<CitationScanRunFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<CitationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CitationEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'directories_evaluated' => 'integer',
            'covered_count' => 'integer',
            'needs_fix_count' => 'integer',
            'not_listed_count' => 'integer',
            'score' => 'integer',
            'new_count' => 'integer',
            'fixed_count' => 'integer',
            'regressed_count' => 'integer',
            'lost_count' => 'integer',
            'meta' => 'array',
        ];
    }
}
