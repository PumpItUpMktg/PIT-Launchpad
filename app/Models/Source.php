<?php

namespace App\Models;

use App\Enums\FeedOrigin;
use App\Enums\SourceType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Silo, $this> */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /**
     * Active = the client (or operator) hasn't paused it. The reconcile job
     * deactivates retired generated feeds rather than deleting them, so this is
     * also what excludes them from the ingest loop.
     *
     * @param  Builder<Source>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * @param  Builder<Source>  $query
     */
    public function scopeOrigin(Builder $query, FeedOrigin $origin): void
    {
        $query->where('origin', $origin->value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'origin' => FeedOrigin::class,
            'config' => 'array',
            'enabled' => 'boolean',
            'last_fetched_at' => 'datetime',
            'last_item_at' => 'datetime',
        ];
    }
}
