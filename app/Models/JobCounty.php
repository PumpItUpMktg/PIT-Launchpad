<?php

namespace App\Models;

use App\Enums\SizeTier;
use Database\Factories\JobCountyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A canonical county in the Job Capture geography registry (§1). GLOBAL — not site-scoped: keyed by the
 * nationally-unique 5-digit STATE+COUNTY FIPS (`county_geoid`), one row shared across every tenant's jobs.
 * Population + size_tier are stored for future tiering (free from the same Census/ACS call served-town
 * ordering already makes); the state-suffixed slug keeps the display label unambiguous.
 *
 * @property string $id
 * @property string $county_geoid 5-digit STATE+COUNTY FIPS — the identity
 * @property string $state_fips
 * @property string $name
 * @property string|null $state 2-letter abbreviation for the state-aware display label
 * @property int|null $population ACS5 total population
 * @property SizeTier|null $size_tier major|large|medium|small (derived from population; null = ungrouped)
 * @property string $slug state-suffixed (washington-county-pa)
 */
class JobCounty extends Model
{
    /** @use HasFactory<JobCountyFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'size_tier' => SizeTier::class,
        ];
    }

    /** @return HasMany<JobCity, $this> */
    public function cities(): HasMany
    {
        return $this->hasMany(JobCity::class);
    }
}
