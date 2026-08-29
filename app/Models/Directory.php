<?php

namespace App\Models;

use App\Enums\AcquisitionType;
use App\Enums\CostPeriod;
use App\Enums\DirectoryScope;
use App\Enums\MultiLocationPolicy;
use App\Enums\SubmissionMethod;
use Database\Factories\DirectoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A citation directory in the GLOBAL catalog (§ Citations) — no tenant scoping; one row reused by every
 * tenant. Global attributes live here; market-dependent ratings live on {@see DirectoryMarketSignal}.
 * `seo_value` is computed, `business_value` operator-set — kept separate on purpose.
 *
 * @property DirectoryScope $scope
 * @property MultiLocationPolicy $multi_location_policy
 * @property AcquisitionType $acquisition_type
 * @property SubmissionMethod|null $submission_method
 * @property CostPeriod|null $cost_period
 * @property list<string>|null $trade_categories
 * @property float|null $cost_amount
 * @property int|null $seo_value
 * @property int|null $business_value
 * @property bool $is_active
 */
class Directory extends Model
{
    /** @use HasFactory<DirectoryFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<DirectoryMarketSignal, $this> */
    public function marketSignals(): HasMany
    {
        return $this->hasMany(DirectoryMarketSignal::class);
    }

    /**
     * The effective SEO value for a market: the per-market override when present, else the global value.
     */
    public function seoValueFor(?string $geoValue): ?int
    {
        if ($geoValue !== null) {
            $local = $this->marketSignals->firstWhere('geo_value', $geoValue)?->seo_value_local;
            if ($local !== null) {
                return (int) $local;
            }
        }

        return $this->seo_value;
    }

    /** Cost per SEO value point — the number that answers "is this worth a couple dollars". Null when free. */
    public function costPerValuePoint(?string $geoValue = null): ?float
    {
        $value = $this->seoValueFor($geoValue);
        if ($this->cost_amount === null || $this->cost_amount <= 0 || $value === null || $value <= 0) {
            return null;
        }

        return round((float) $this->cost_amount / $value, 2);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => DirectoryScope::class,
            'multi_location_policy' => MultiLocationPolicy::class,
            'acquisition_type' => AcquisitionType::class,
            'submission_method' => SubmissionMethod::class,
            'cost_period' => CostPeriod::class,
            'trade_categories' => 'array',
            'authority_tier' => 'integer',
            'cost_amount' => 'decimal:2',
            'avg_turnaround_days' => 'integer',
            'requires_client_action' => 'boolean',
            'effort_minutes' => 'integer',
            'domain_rank' => 'integer',
            'seo_value' => 'integer',
            'business_value' => 'integer',
            'is_nofollow' => 'boolean',
            'is_active' => 'boolean',
            'is_submittable' => 'boolean',
        ];
    }

    /** The high/medium/low band the UI shows for the numeric 1–5 authority_tier. */
    public function tierLabel(): string
    {
        return match (true) {
            $this->authority_tier >= 4 => 'high',
            $this->authority_tier >= 3 => 'medium',
            default => 'low',
        };
    }
}
