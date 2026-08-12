<?php

namespace App\Models;

use App\Enums\MunicipalityType;
use App\Enums\SizeTier;
use Database\Factories\JobCityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A canonical city/place in the Job Capture geography registry (§1). GLOBAL — not site-scoped: keyed by the
 * Census place (7-digit) or county-subdivision (10-digit) GEOID (`place_geoid`), which normalizes name
 * variants ("Bedminster Twp" vs "Bedminster") to one row shared across tenants. Soft-links to its county
 * (nullable — resolved by the §4 geography pipeline). Population + size_tier mirror served-town ordering.
 *
 * @property string $id
 * @property string $place_geoid place (7) or county-subdivision (10) GEOID — the identity
 * @property string|null $job_county_id
 * @property string $name
 * @property string|null $state 2-letter abbreviation
 * @property MunicipalityType $type
 * @property float|null $lat
 * @property float|null $lng
 * @property int|null $population ACS5 total population
 * @property SizeTier|null $size_tier major|large|medium|small (derived from population; null = ungrouped)
 * @property string $slug state-suffixed (bedminster-nj)
 */
class JobCity extends Model
{
    /** @use HasFactory<JobCityFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MunicipalityType::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'population' => 'integer',
            'size_tier' => SizeTier::class,
        ];
    }

    /** @return BelongsTo<JobCounty, $this> */
    public function county(): BelongsTo
    {
        return $this->belongsTo(JobCounty::class, 'job_county_id');
    }
}
