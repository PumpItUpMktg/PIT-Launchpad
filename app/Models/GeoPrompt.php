<?php

namespace App\Models;

use App\Enums\GeoIntent;
use App\Enums\GeoPromptKind;
use App\Enums\GeoPromptPriority;
use App\Enums\GeoPromptSource;
use App\Enums\SizeTier;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $coverage_area_id the covered TOWN this prompt measures (GEO's geography = CoverageArea)
 * @property SizeTier|null $size_tier the town's population tier, denormalized so the audit orders major→small
 * @property GeoPromptPriority $priority operator override; leads the check + content order ahead of size_tier
 * @property GeoPromptKind $kind visibility (primary cited% metric) vs coverage (brand-anchored accuracy check)
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
            'size_tier' => SizeTier::class,
            'priority' => GeoPromptPriority::class,
            'kind' => GeoPromptKind::class,
        ];
    }

    /**
     * The shared "what to work first" ordering — operator priority (high→low) leads, then the town size
     * tier (major→small), then oldest. The audit and the gap bridge both apply it so the operator's manual
     * priority pins an item to the front of a budget-bounded run and of the content queue alike.
     *
     * @param  Builder<GeoPrompt>  $query
     * @return Builder<GeoPrompt>
     */
    public function scopeWorkOrder($query)
    {
        return $query
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
            ->orderByRaw("case size_tier when 'major' then 0 when 'large' then 1 when 'medium' then 2 when 'small' then 3 else 4 end")
            ->orderBy('created_at');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The covered town this prompt measures — GEO's geography is the CoverageArea set (the location-linked,
     * size-tiered municipalities the platform publishes pages for), not the curated Market list.
     *
     * @return BelongsTo<CoverageArea, $this>
     */
    public function coverageArea(): BelongsTo
    {
        return $this->belongsTo(CoverageArea::class);
    }

    /**
     * @deprecated GEO no longer targets Markets — kept only so legacy `market_id` rows resolve. Use
     * {@see coverageArea()}.
     *
     * @return BelongsTo<Market, $this>
     */
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
