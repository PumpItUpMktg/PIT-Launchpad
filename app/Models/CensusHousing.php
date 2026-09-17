<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One town's housing stock as the Census ACS 5-year estimates report it.
 *
 * Global (no `site_id`) and keyed on the Census GEOID — see the create migration for why. Shares are
 * computed on read from the stored counts; each returns null when its denominator is zero or missing, so
 * "we don't know" never renders as a number.
 *
 * @property string $geo_id 10-digit county subdivision or 7-digit place
 * @property string $name
 * @property string|null $state
 * @property string|null $county_geoid
 * @property string $acs_year
 * @property int|null $median_year_built
 * @property int|null $occupied_units
 * @property int|null $owner_occupied_units
 * @property int|null $total_units
 * @property int|null $single_family_units
 * @property int|null $pre_1960_units
 * @property Carbon|null $fetched_at
 */
class CensusHousing extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'census_housing';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    /** Share of occupied homes lived in by their owner, 0–1, or null when unknown. */
    public function ownerOccupiedShare(): ?float
    {
        return $this->share($this->owner_occupied_units, $this->occupied_units);
    }

    /** Share of housing units that are single-family (detached or attached), 0–1, or null when unknown. */
    public function singleFamilyShare(): ?float
    {
        return $this->share($this->single_family_units, $this->total_units);
    }

    /** Share of housing units built before 1960, 0–1, or null when unknown. */
    public function pre1960Share(): ?float
    {
        return $this->share($this->pre_1960_units, $this->total_units);
    }

    private function share(?int $part, ?int $whole): ?float
    {
        if ($part === null || $whole === null || $whole <= 0) {
            return null;
        }

        return round($part / $whole, 4);
    }
}
