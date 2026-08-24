<?php

namespace App\Models;

use App\Enums\GeoIntent;
use App\Enums\GeoPromptSource;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A GEO test prompt — a question we check AI search engines for the brand's visibility on. Auto-seeded
 * from the service × market × intent matrix or hand-written; the dimension tags (service/market/intent)
 * power the coverage matrix and the gap → content bridge.
 *
 * @property string $site_id
 * @property string|null $service_id
 * @property string|null $market_id
 * @property GeoIntent|null $intent
 * @property GeoPromptSource $source
 * @property string $prompt
 * @property string|null $label
 * @property bool $active
 */
class GeoPrompt extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'intent' => GeoIntent::class,
            'source' => GeoPromptSource::class,
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return HasMany<GeoSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(GeoSnapshot::class);
    }

    /** @return HasOne<GeoSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(GeoSnapshot::class)->latestOfMany('checked_at');
    }

    /**
     * How many engines have a reading and how many currently cite the brand — the latest snapshot PER
     * engine (so re-runs don't double-count). Reads the loaded `snapshots` relation; eager-load it to
     * avoid N+1.
     *
     * @return array{measured: int, cited: int}
     */
    public function engineSummary(): array
    {
        $latestPerEngine = $this->snapshots->sortByDesc('checked_at')->unique('engine');

        return ['measured' => $latestPerEngine->count(), 'cited' => $latestPerEngine->where('cited', true)->count()];
    }
}
